<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
$input = semsad_leer_json();
$id_multa = (int)($input['id_multa'] ?? 0);
$metodo = trim((string)($input['metodo_pago'] ?? ''));
if ($id_multa <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Identificador de multa no válido.'], 400);
if (!in_array($metodo, ['Efectivo', 'Digital'], true)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Selecciona Efectivo o Digital como método de pago.'], 400);
}

try {
    $columns = $conexion->query("SHOW COLUMNS FROM Multa")->fetchAll(PDO::FETCH_COLUMN, 0);
    $conexion->beginTransaction();

    $stmt = $conexion->prepare("
        SELECT m.id_multa, m.id_prestamo, m.monto, m.estado_pago, p.id_usuario,
               u.nombres, u.apellidos, u.correo
        FROM Multa m
        INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        INNER JOIN Usuario u ON u.id_usuario = p.id_usuario
        WHERE m.id_multa = :id
        FOR UPDATE
    ");
    $stmt->execute([':id' => $id_multa]);
    $multa = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$multa) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No se encontró la multa indicada.'], 404);
    }
    if ((string)$multa['estado_pago'] !== 'Pendiente') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Esta multa ya fue procesada y no se puede cobrar nuevamente.'], 409);
    }

    $set = ["estado_pago = 'Pagado'"];
    $params = [':id' => $id_multa];
    if (in_array('fecha_pago', $columns, true)) $set[] = 'fecha_pago = NOW()';
    if (in_array('metodo_pago', $columns, true)) {
        $set[] = 'metodo_pago = :metodo';
        $params[':metodo'] = $metodo;
    }
    if (in_array('id_bibliotecario_cobro', $columns, true)) {
        $set[] = 'id_bibliotecario_cobro = :bibliotecario';
        $params[':bibliotecario'] = (int)($_SESSION['id_usuario'] ?? 0);
    }

    $sqlUpdate = 'UPDATE Multa SET ' . implode(', ', $set) . " WHERE id_multa = :id AND estado_pago = 'Pendiente'";
    $conexion->prepare($sqlUpdate)->execute($params);

    $idUsuario = (int)$multa['id_usuario'];
    $stmtPend = $conexion->prepare("
        SELECT COUNT(*)
        FROM Multa m INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
    ");
    $stmtPend->execute([':u' => $idUsuario]);
    $multasPendientes = (int)$stmtPend->fetchColumn();

    $stmtMora = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :u AND estado = 'Con Mora'");
    $stmtMora->execute([':u' => $idUsuario]);
    $prestamosMora = (int)$stmtMora->fetchColumn();

    // Al saldar todo, se restablece la cuenta suspendida por morosidad.
    if ($multasPendientes === 0 && $prestamosMora === 0) {
        $conexion->prepare("UPDATE Usuario SET estado = 'Activo' WHERE id_usuario = :u AND estado = 'Suspendido'")
            ->execute([':u' => $idUsuario]);
    }

    $conexion->commit();

    $nombre = trim(($multa['nombres'] ?? '') . ' ' . ($multa['apellidos'] ?? ''));
    $requiereMigracion = !in_array('fecha_pago', $columns, true) || !in_array('metodo_pago', $columns, true);
    $mensaje = 'Pago registrado. Comprobante: MULTA-' . $id_multa . '.';
    if ($requiereMigracion) {
        $mensaje .= ' La base actual no tiene columnas para conservar método y fecha de pago; el estado Pagado sí quedó registrado.';
    }
    semsad_responder([
        'status' => 'success',
        'mensaje' => $mensaje,
        'comprobante' => [
            'codigo' => 'MULTA-' . $id_multa,
            'id_multa' => $id_multa,
            'usuario' => $nombre,
            'correo' => (string)$multa['correo'],
            'monto' => round((float)$multa['monto'], 2),
            'metodo_pago' => $metodo,
            'fecha_pago' => date('d/m/Y H:i'),
            'reactivado' => $multasPendientes === 0 && $prestamosMora === 0,
            'persistencia_completa' => !$requiereMigracion
        ]
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] registrar_pago_multa: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar el pago. No se confirmó ningún cambio.'], 500);
}
?>