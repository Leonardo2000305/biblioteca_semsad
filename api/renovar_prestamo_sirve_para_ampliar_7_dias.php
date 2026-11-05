<?php
// ARCHIVO: renovar_prestamo_sirve_para_ampliar_7_dias.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: dejar que el estudiante amplíe 7 días más el plazo de un libro que tiene prestado.
// QUÉ HACE: Exige sesión y revisa las reglas: el préstamo es suyo y está activo, no tiene multas, nadie espera el libro y no pasa de 2 renovaciones.
//   Si todo está bien, suma 7 días a la fecha límite y responde en JSON.
// CONECTA CON: principal/index.html (botón Renovar de los préstamos del estudiante); usa conexion_sirve_para_conectar_base_datos.php y reglas_prestamos_sirve_para_multas_y_plazos.php
/**
 * RENOVAR PRÉSTAMO (estudiante/docente con sesión): amplía 7 días la fecha límite.
 * Reglas (RF10): el préstamo es suyo y no está vencido, no tiene multas pendientes, nadie espera el libro,
 * y como máximo 2 renovaciones por préstamo.
 * Entrada JSON: { id_prestamo }
 */
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
semsad_iniciar_sesion();

// Máximo de renovaciones permitidas por préstamo.
const SEMSAD_MAX_RENOVACIONES = 2;

// Solo se acepta POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
// Quién renueva: solo la sesión del servidor, nunca un id que mande el navegador.
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
// Sin sesión: se responde 401 para que la página pida iniciar sesión.
if ($idUsuario <= 0) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}
// Id del préstamo que se quiere renovar (viene en el JSON).
$idPrestamo = (int)(semsad_leer_json()['id_prestamo'] ?? 0);
if ($idPrestamo <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Préstamo no válido.']);
}

try {
    // Primero se marcan los préstamos vencidos, para no renovar uno que ya venció.
    semsad_marcar_vencidos($conexion);

    // Transacción: se bloquea el préstamo (FOR UPDATE) para que no se renueve dos veces a la vez.
    $conexion->beginTransaction();
    $q = $conexion->prepare('SELECT id_prestamo, id_usuario, estado, fecha_limite FROM prestamo WHERE id_prestamo = :p FOR UPDATE');
    $q->execute([':p' => $idPrestamo]);
    $p = $q->fetch(PDO::FETCH_ASSOC);

    // Las reglas se revisan una por una; si una falla, su mensaje queda en la variable error.
    $error = null;
    // Regla: el préstamo debe existir y ser de este usuario.
    if (!$p || (int)$p['id_usuario'] !== $idUsuario) {
        $error = 'No encontramos ese préstamo en tu cuenta.';
    // Regla: el préstamo debe estar Activo; si está vencido, no se renueva.
    } elseif ($p['estado'] !== 'Activo') {
        $error = in_array($p['estado'], ['Vencido', 'Con Mora'], true)
            ? 'Tu préstamo está vencido. Devuélvelo en ventanilla; no se puede renovar.'
            : 'Este préstamo ya no está activo.';
    // Regla: si la fecha límite ya pasó, tampoco se renueva.
    } elseif (strtotime($p['fecha_limite']) < time()) {
        $error = 'Tu préstamo está vencido. Devuélvelo en ventanilla; no se puede renovar.';
    }

    // Regla: no se renueva si el usuario tiene multas pendientes.
    if (!$error) {
        try {
            $q = $conexion->prepare("SELECT COUNT(*) FROM multa m INNER JOIN prestamo pr ON pr.id_prestamo = m.id_prestamo WHERE pr.id_usuario = :u AND m.estado_pago = 'Pendiente'");
            $q->execute([':u' => $idUsuario]);
            if ((int)$q->fetchColumn() > 0) {
                $error = 'Tienes multas pendientes. Regulariza tu cuenta para renovar.';
            }
        } catch (PDOException $ignorar) { /* sin tabla Multa */ }
    }

    // Regla: no se renueva si otra persona está esperando alguno de estos libros.
    if (!$error) {
        $q = $conexion->prepare('SELECT DISTINCT e.id_material FROM detalle_prestamo dp INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar WHERE dp.id_prestamo = :p');
        $q->execute([':p' => $idPrestamo]);
        $materiales = $q->fetchAll(PDO::FETCH_COLUMN);
        try {
            foreach ($materiales as $m) {
                $q = $conexion->prepare("SELECT COUNT(*) FROM reserva WHERE id_material = :m AND estado = 'Pendiente' AND id_usuario <> :u");
                $q->execute([':m' => $m, ':u' => $idUsuario]);
                if ((int)$q->fetchColumn() > 0) {
                    $error = 'Otro usuario está esperando este libro, por eso no se puede renovar.';
                    break;
                }
            }
        } catch (PDOException $ignorar) { /* sin tabla Reserva */ }
    }

    // Revisa si la tabla tiene la columna num_renovaciones (el contador de renovaciones).
    $tieneContador = in_array('num_renovaciones', array_map('strtolower', $conexion->query('SHOW COLUMNS FROM prestamo')->fetchAll(PDO::FETCH_COLUMN, 0)), true);
    // Regla: máximo 2 renovaciones por préstamo.
    if (!$error && $tieneContador) {
        $q = $conexion->prepare('SELECT num_renovaciones FROM prestamo WHERE id_prestamo = :p');
        $q->execute([':p' => $idPrestamo]);
        if ((int)$q->fetchColumn() >= SEMSAD_MAX_RENOVACIONES) {
            $error = 'Ya usaste las ' . SEMSAD_MAX_RENOVACIONES . ' renovaciones permitidas para este préstamo.';
        }
    }

    // Si alguna regla no se cumple, se deshace la transacción y se responde el motivo.
    if ($error) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => $error]);
    }

    // Nueva fecha límite: la fecha límite actual más 7 días.
    $nuevo = date('Y-m-d H:i:s', strtotime($p['fecha_limite'] . ' +7 days'));
    // Si existe el contador, también suma 1 a las renovaciones.
    $sql = $tieneContador
        ? 'UPDATE prestamo SET fecha_limite = :l, num_renovaciones = num_renovaciones + 1 WHERE id_prestamo = :p'
        : 'UPDATE prestamo SET fecha_limite = :l WHERE id_prestamo = :p';
    $conexion->prepare($sql)->execute([':l' => $nuevo, ':p' => $idPrestamo]);
    // Confirma la renovación.
    $conexion->commit();

    // Respuesta JSON de éxito con la nueva fecha límite.
    semsad_responder(['status' => 'success', 'mensaje' => '¡Préstamo renovado! Nueva fecha límite: ' . date('d/m/Y', strtotime($nuevo)) . '.']);
} catch (Throwable $e) {
    // Error: si la transacción sigue abierta, se deshacen los cambios.
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // Guarda el error en el log del servidor.
    error_log('[SEMSAD] renovar_prestamo: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo renovar el préstamo.'], 500);
}
