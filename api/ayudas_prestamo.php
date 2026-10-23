<?php
/**
 * Reglas y utilidades compartidas por préstamos, multas y reportes.
 * Un solo lugar para que ventanilla, dashboard, multas y reportes siempre usen el MISMO criterio.
 */

// Reglas de negocio (documento del proyecto, RF12 y RF13). Cambiar aquí si la biblioteca las modifica.
const SEMSAD_MULTA_POR_DIA   = 5.00;   // Bs por cada día de retraso
const SEMSAD_MULTA_TOPE      = 200.00; // Bs, monto máximo de una multa
const SEMSAD_DIAS_BLOQUEO    = 15;     // más de estos días de retraso => la cuenta se bloquea
const SEMSAD_ESTADOS_ACTIVOS = ['Activo', 'Vencido', 'Con Mora']; // préstamos que siguen sin devolverse

/** Devuelve el primer valor de $preferidos que exista en la columna ENUM, o null si ninguno existe. */
function semsad_valor_enum(PDO $c, string $tabla, string $columna, array $preferidos): ?string
{
    try {
        $fila = $c->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'")->fetch(PDO::FETCH_ASSOC);
        $tipo = (string)($fila['Type'] ?? '');
        if (stripos($tipo, 'enum(') !== 0) {
            return $preferidos[0] ?? null;           // no es ENUM: cualquier texto sirve
        }
        preg_match_all("/'((?:[^']|'')*)'/", $tipo, $m);
        $validos = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
        foreach ($preferidos as $p) {
            if (in_array($p, $validos, true)) {
                return $p;
            }
        }
    } catch (Throwable $e) {
        error_log('[SEMSAD] semsad_valor_enum: ' . $e->getMessage());
    }
    return null;
}

/** Días completos de retraso y monto de la multa para una fecha límite. */
function semsad_calcular_mora(?string $fechaLimite, ?int $ahora = null): array
{
    $ahora = $ahora ?? time();
    $ts = $fechaLimite ? strtotime($fechaLimite) : false;
    if ($ts === false || $ts >= $ahora) {
        return ['dias' => 0, 'monto' => 0.00];
    }
    $dias  = (int)floor(($ahora - $ts) / 86400);
    $monto = min($dias * SEMSAD_MULTA_POR_DIA, SEMSAD_MULTA_TOPE);
    return ['dias' => $dias, 'monto' => (float)$monto];
}

/** Marca como vencidos los préstamos pasados de fecha, usando un estado que exista de verdad en la base. */
function semsad_marcar_vencidos(PDO $c): void
{
    try {
        $estado = semsad_valor_enum($c, 'prestamo', 'estado', ['Vencido', 'Con Mora']);
        if ($estado === null) {
            return;
        }
        $q = $c->prepare("UPDATE prestamo SET estado = :e WHERE estado = 'Activo' AND fecha_limite < NOW()");
        $q->execute([':e' => $estado]);
    } catch (Throwable $e) {
        error_log('[SEMSAD] semsad_marcar_vencidos: ' . $e->getMessage());
    }
}

/**
 * Lista de espera: si hay gente en cola para el libro, las copias disponibles son para los primeros turnos.
 * Devuelve un mensaje de error si $idUsuario NO puede llevarse el libro ahora, o null si puede.
 */
function semsad_turno_permite(PDO $c, int $idUsuario, int $idMaterial): ?string
{
    try {
        $q = $c->prepare("SELECT COUNT(*) FROM ejemplar e WHERE e.id_material = :m AND " . semsad_sql_ejemplar_libre('e'));
        $q->execute([':m' => $idMaterial]);
        $disponibles = (int)$q->fetchColumn();

        $q = $c->prepare("SELECT id_usuario FROM reserva WHERE id_material = :m AND estado = 'Pendiente' ORDER BY prioridad ASC, id_reserva ASC");
        $q->execute([':m' => $idMaterial]);
        $cola = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        if (!$cola || $disponibles < 1) {
            return null;   // sin cola (o sin copias: ya lo valida quien llama)
        }
        $pos = array_search($idUsuario, $cola, true);
        if ($pos !== false) {
            return $pos < $disponibles ? null : 'Hay ' . $pos . ' persona(s) antes que tú en la lista de espera de este libro.';
        }
        return count($cola) >= $disponibles
            ? 'Las copias disponibles de este libro están reservadas para la lista de espera. Anótate para tener turno.'
            : null;
    } catch (Throwable $e) {
        return null;       // sin tabla Reserva: no se aplica la regla
    }
}

/** Marca como atendida la reserva pendiente del usuario para ese libro (cuando por fin lo lleva). */
function semsad_atender_reserva(PDO $c, int $idUsuario, int $idMaterial): void
{
    try {
        $c->prepare("UPDATE reserva SET estado = 'Atendida' WHERE id_usuario = :u AND id_material = :m AND estado = 'Pendiente'")
          ->execute([':u' => $idUsuario, ':m' => $idMaterial]);
    } catch (Throwable $e) { /* sin tabla Reserva */ }
}


/* ── CADUCIDAD DE RESERVAS (hora de corte) ───────────────────────────────
 * Cuando hay copias libres para la lista de espera, los primeros turnos reciben
 * aviso y tienen plazo hasta las 10:00 del día siguiente para retirar el libro.
 * Si no lo retiran, su reserva pasa a "Vencida" y el siguiente turno pasa a ser el primero.
 * Requiere las columnas fecha_aviso y fecha_limite_retiro (db/caducidad_reservas.sql).
 * Si la migración no se ha ejecutado, no hace nada.
 */

/** Hora límite para retirar: 10:00 del día siguiente al aviso (zona horaria de La Paz). */
function semsad_hora_corte_siguiente(DateTimeImmutable $ahora): DateTimeImmutable
{
    return $ahora->modify('+1 day')->setTime(10, 0, 0);
}

function semsad_caducar_reservas(PDO $c): void
{
    try {
        if (!semsad_tiene_columna($c, 'reserva', 'fecha_limite_retiro')) {
            return;
        }
        $ahora    = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));
        $ahoraSql = $ahora->format('Y-m-d H:i:s');

        // 1. Las reservas avisadas cuyo plazo ya venció pasan a Vencida
        $c->prepare("UPDATE reserva SET estado = 'Vencida'
                     WHERE estado = 'Pendiente' AND fecha_limite_retiro IS NOT NULL AND fecha_limite_retiro < :ahora")
          ->execute([':ahora' => $ahoraSql]);

        // 2. Por cada libro con cola: avisar a los primeros turnos hasta igualar las copias libres
        $materiales = $c->query("SELECT DISTINCT id_material FROM reserva WHERE estado = 'Pendiente'")->fetchAll(PDO::FETCH_COLUMN);
        $limiteSql  = semsad_hora_corte_siguiente($ahora)->format('Y-m-d H:i:s');

        foreach ($materiales as $idMaterial) {
            $idMaterial = (int)$idMaterial;

            $q = $c->prepare("SELECT COUNT(*) FROM ejemplar e WHERE e.id_material = :m AND " . semsad_sql_ejemplar_libre('e'));
            $q->execute([':m' => $idMaterial]);
            $libres = (int)$q->fetchColumn();

            $q = $c->prepare("SELECT COUNT(*) FROM reserva WHERE id_material = :m AND estado = 'Pendiente' AND fecha_limite_retiro IS NOT NULL");
            $q->execute([':m' => $idMaterial]);
            $yaAvisados = (int)$q->fetchColumn();

            $faltan = $libres - $yaAvisados;
            if ($faltan <= 0) {
                continue;
            }

            $q = $c->prepare("SELECT id_reserva FROM reserva
                              WHERE id_material = :m AND estado = 'Pendiente' AND fecha_limite_retiro IS NULL
                              ORDER BY prioridad ASC, id_reserva ASC LIMIT " . (int)$faltan);
            $q->execute([':m' => $idMaterial]);
            $up = $c->prepare("UPDATE reserva SET fecha_aviso = :a, fecha_limite_retiro = :l WHERE id_reserva = :r");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $idReserva) {
                $up->execute([':a' => $ahoraSql, ':l' => $limiteSql, ':r' => (int)$idReserva]);
            }
        }
    } catch (Throwable $e) {
        error_log('[SEMSAD] semsad_caducar_reservas: ' . $e->getMessage());
    }
}
