<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$input = semsad_leer_json();
$id_usuario = (int)($input['id_usuario'] ?? 0);
$nuevo_estado = trim((string)($input['estado'] ?? ''));

if ($id_usuario <= 0 || !in_array($nuevo_estado, ['Activo', 'Suspendido', 'Bloqueado'], true)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Parámetros no válidos.'], 400);
}
if ($id_usuario === (int)($_SESSION['id_usuario'] ?? 0) && $nuevo_estado !== 'Activo') {
    semsad_responder(['status' => 'error', 'mensaje' => 'No puedes suspender o bloquear tu propia sesión administrativa.'], 400);
}

try {
    if ($nuevo_estado === 'Activo') {
        $stmtDeuda = $conexion->prepare("
            SELECT COUNT(*)
            FROM Multa m
            INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
            WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
        ");
        $stmtDeuda->execute([':u' => $id_usuario]);
        if ((int)$stmtDeuda->fetchColumn() > 0) {
            semsad_responder(['status' => 'error', 'mensaje' => 'No se puede reactivar la cuenta: el usuario aún tiene multas pendientes.'], 409);
        }
        $stmtMora = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :u AND estado = 'Con Mora'");
        $stmtMora->execute([':u' => $id_usuario]);
        if ((int)$stmtMora->fetchColumn() > 0) {
            semsad_responder(['status' => 'error', 'mensaje' => 'No se puede reactivar la cuenta mientras tenga préstamos con mora pendientes.'], 409);
        }
    }

    $stmt = $conexion->prepare("UPDATE Usuario SET estado = :e WHERE id_usuario = :id");
    $stmt->execute([':e' => $nuevo_estado, ':id' => $id_usuario]);
    if ($stmt->rowCount() < 1) {
        $exists = $conexion->prepare("SELECT 1 FROM Usuario WHERE id_usuario = :id");
        $exists->execute([':id' => $id_usuario]);
        if (!$exists->fetchColumn()) {
            semsad_responder(['status' => 'error', 'mensaje' => 'El usuario no existe.'], 404);
        }
    }

    semsad_responder(['status' => 'success', 'mensaje' => "Estado del usuario actualizado a $nuevo_estado."]);
} catch (Throwable $e) {
    error_log('[SEMSAD] cambiar_estado_usuario: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo cambiar el estado del usuario.'], 500);
}
?>