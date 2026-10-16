<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
$rol = (string)($_SESSION['rol'] ?? '');
if ($id_usuario <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Inicia sesión nuevamente.'], 401);
if (!in_array($rol, ['Estudiante', 'Docente'], true)) semsad_responder(['status' => 'error', 'mensaje' => 'Solo estudiantes y docentes pueden solicitar una renovación.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$id_prestamo = (int)($input['id_prestamo'] ?? 0);
if ($id_prestamo <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de préstamo no válido.'], 400);

try {
    $conexion->beginTransaction();

    $stmt = $conexion->prepare("
        SELECT p.id_prestamo, p.id_usuario, p.fecha_limite, p.estado, p.num_renovaciones,
               u.id_rol, e.id_material, mb.id_tipo_material, mb.titulo
        FROM Prestamo p
        INNER JOIN Usuario u ON u.id_usuario = p.id_usuario
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        INNER JOIN MaterialBibliografico mb ON mb.id_material = e.id_material
        WHERE p.id_prestamo = :p AND p.id_usuario = :u
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':p' => $id_prestamo, ':u' => $id_usuario]);
    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prestamo) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No se encontró un préstamo asociado a tu cuenta.'], 404);
    }
    if ((string)$prestamo['estado'] !== 'Activo') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Solo se pueden renovar préstamos activos y sin mora.'], 409);
    }
    if (strtotime((string)$prestamo['fecha_limite']) < time()) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'El plazo ya venció. Devuelve el libro y regulariza cualquier mora en ventanilla.'], 409);
    }

    $stmtMultas = $conexion->prepare("
        SELECT COUNT(*)
        FROM Multa m
        INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
    ");
    $stmtMultas->execute([':u' => $id_usuario]);
    if ((int)$stmtMultas->fetchColumn() > 0) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Tienes multas pendientes. No puedes renovar hasta regularizar tu cuenta.'], 403);
    }

    $stmtReservas = $conexion->prepare("
        SELECT COUNT(*)
        FROM Reserva
        WHERE id_material = :m AND estado = 'Pendiente' AND id_usuario <> :u
    ");
    $stmtReservas->execute([':m' => (int)$prestamo['id_material'], ':u' => $id_usuario]);
    if ((int)$stmtReservas->fetchColumn() > 0) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Otro usuario está esperando este libro. No se permite renovarlo; devuélvelo para atender la reserva.'], 409);
    }

    $stmtRegla = $conexion->prepare("
        SELECT max_renovaciones, dias_prestamo
        FROM ReglaPrestamo
        WHERE id_rol = :r AND id_tipo_material = :t
        ORDER BY id_regla DESC
        LIMIT 1
    ");
    $stmtRegla->execute([
        ':r' => (int)$prestamo['id_rol'],
        ':t' => (int)$prestamo['id_tipo_material']
    ]);
    $regla = $stmtRegla->fetch(PDO::FETCH_ASSOC);
    if (!$regla) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No hay una regla de renovación configurada para tu rol y este tipo de material. Consulta al bibliotecario.'], 409);
    }

    $maxRenovaciones = (int)$regla['max_renovaciones'];
    $yaRenovaciones = (int)$prestamo['num_renovaciones'];
    if ($maxRenovaciones <= 0 || $yaRenovaciones >= $maxRenovaciones) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Este préstamo ya alcanzó el máximo de renovaciones permitido.'], 409);
    }

    $dias = (int)$regla['dias_prestamo'];
    if ($dias <= 0) $dias = 7;
    $nuevaFecha = date('Y-m-d H:i:s', strtotime((string)$prestamo['fecha_limite'] . " +$dias days"));
    $update = $conexion->prepare("
        UPDATE Prestamo
        SET fecha_limite = :fecha, num_renovaciones = num_renovaciones + 1
        WHERE id_prestamo = :p AND id_usuario = :u AND estado = 'Activo'
    ");
    $update->execute([':fecha' => $nuevaFecha, ':p' => $id_prestamo, ':u' => $id_usuario]);
    if ($update->rowCount() !== 1) throw new RuntimeException('No se actualizó el préstamo.');

    $conexion->commit();
    semsad_responder([
        'status' => 'success',
        'mensaje' => 'La renovación del préstamo se registró correctamente.',
        'id_prestamo' => $id_prestamo,
        'titulo' => (string)$prestamo['titulo'],
        'fecha_limite' => date('d/m/Y', strtotime($nuevaFecha)),
        'renovaciones_usadas' => $yaRenovaciones + 1,
        'renovaciones_maximas' => $maxRenovaciones
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] renovar_prestamo: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo renovar el préstamo. No se confirmó ningún cambio.'], 500);
}
?>