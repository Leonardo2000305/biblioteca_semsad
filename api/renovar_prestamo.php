<?php
/**
 * RENOVAR PRÉSTAMO (estudiante/docente con sesión): amplía 7 días la fecha límite.
 * Reglas (RF10): el préstamo es suyo y no está vencido, no tiene multas pendientes, nadie espera el libro,
 * y como máximo 2 renovaciones por préstamo.
 * Entrada JSON: { id_prestamo }
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/ayudas_prestamo.php';
semsad_iniciar_sesion();

const SEMSAD_MAX_RENOVACIONES = 2;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($idUsuario <= 0) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}
$idPrestamo = (int)(semsad_leer_json()['id_prestamo'] ?? 0);
if ($idPrestamo <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Préstamo no válido.']);
}

try {
    semsad_marcar_vencidos($conexion);

    $conexion->beginTransaction();
    $q = $conexion->prepare('SELECT id_prestamo, id_usuario, estado, fecha_limite FROM Prestamo WHERE id_prestamo = :p FOR UPDATE');
    $q->execute([':p' => $idPrestamo]);
    $p = $q->fetch(PDO::FETCH_ASSOC);

    $error = null;
    if (!$p || (int)$p['id_usuario'] !== $idUsuario) {
        $error = 'No encontramos ese préstamo en tu cuenta.';
    } elseif ($p['estado'] !== 'Activo') {
        $error = in_array($p['estado'], ['Vencido', 'Con Mora'], true)
            ? 'Tu préstamo está vencido. Devuélvelo en ventanilla; no se puede renovar.'
            : 'Este préstamo ya no está activo.';
    } elseif (strtotime($p['fecha_limite']) < time()) {
        $error = 'Tu préstamo está vencido. Devuélvelo en ventanilla; no se puede renovar.';
    }

    if (!$error) {
        try {
            $q = $conexion->prepare("SELECT COUNT(*) FROM Multa m INNER JOIN Prestamo pr ON pr.id_prestamo = m.id_prestamo WHERE pr.id_usuario = :u AND m.estado_pago = 'Pendiente'");
            $q->execute([':u' => $idUsuario]);
            if ((int)$q->fetchColumn() > 0) {
                $error = 'Tienes multas pendientes. Regulariza tu cuenta para renovar.';
            }
        } catch (PDOException $ignorar) { /* sin tabla Multa */ }
    }

    if (!$error) {
        $q = $conexion->prepare('SELECT DISTINCT e.id_material FROM Detalle_Prestamo dp INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar WHERE dp.id_prestamo = :p');
        $q->execute([':p' => $idPrestamo]);
        $materiales = $q->fetchAll(PDO::FETCH_COLUMN);
        try {
            foreach ($materiales as $m) {
                $q = $conexion->prepare("SELECT COUNT(*) FROM Reserva WHERE id_material = :m AND estado = 'Pendiente' AND id_usuario <> :u");
                $q->execute([':m' => $m, ':u' => $idUsuario]);
                if ((int)$q->fetchColumn() > 0) {
                    $error = 'Otro usuario está esperando este libro, por eso no se puede renovar.';
                    break;
                }
            }
        } catch (PDOException $ignorar) { /* sin tabla Reserva */ }
    }

    $tieneContador = in_array('num_renovaciones', array_map('strtolower', $conexion->query('SHOW COLUMNS FROM Prestamo')->fetchAll(PDO::FETCH_COLUMN, 0)), true);
    if (!$error && $tieneContador) {
        $q = $conexion->prepare('SELECT num_renovaciones FROM Prestamo WHERE id_prestamo = :p');
        $q->execute([':p' => $idPrestamo]);
        if ((int)$q->fetchColumn() >= SEMSAD_MAX_RENOVACIONES) {
            $error = 'Ya usaste las ' . SEMSAD_MAX_RENOVACIONES . ' renovaciones permitidas para este préstamo.';
        }
    }

    if ($error) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => $error]);
    }

    $nuevo = date('Y-m-d H:i:s', strtotime($p['fecha_limite'] . ' +7 days'));
    $sql = $tieneContador
        ? 'UPDATE Prestamo SET fecha_limite = :l, num_renovaciones = num_renovaciones + 1 WHERE id_prestamo = :p'
        : 'UPDATE Prestamo SET fecha_limite = :l WHERE id_prestamo = :p';
    $conexion->prepare($sql)->execute([':l' => $nuevo, ':p' => $idPrestamo]);
    $conexion->commit();

    semsad_responder(['status' => 'success', 'mensaje' => '¡Préstamo renovado! Nueva fecha límite: ' . date('d/m/Y', strtotime($nuevo)) . '.']);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('[SEMSAD] renovar_prestamo: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo renovar el préstamo.'], 500);
}
