<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_sesion = (int)($_SESSION['id_usuario'] ?? 0);
$rol = (string)($_SESSION['rol'] ?? '');
if ($id_sesion <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Inicia sesión nuevamente.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$id_reserva = (int)($input['id_reserva'] ?? 0);
if ($id_reserva <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de reserva no válido.'], 400);

try {
    $staff = in_array($rol, ['Administrador', 'Bibliotecario'], true);
    $sql = "SELECT id_reserva, id_usuario, estado FROM Reserva WHERE id_reserva = :r";
    if (!$staff) $sql .= " AND id_usuario = :u";
    $sql .= " LIMIT 1";
    $stmt = $conexion->prepare($sql);
    $params = [':r' => $id_reserva];
    if (!$staff) $params[':u'] = $id_sesion;
    $stmt->execute($params);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reserva) semsad_responder(['status' => 'error', 'mensaje' => 'No se encontró una reserva que puedas modificar.'], 404);
    if ($reserva['estado'] !== 'Pendiente') semsad_responder(['status' => 'error', 'mensaje' => 'Solo se pueden cancelar reservas pendientes.'], 409);

    $columns = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    $conexion->beginTransaction();
    if (in_array('id_ejemplar', $columns, true)) {
        $q = $conexion->prepare("SELECT id_ejemplar FROM Reserva WHERE id_reserva = :r FOR UPDATE");
        $q->execute([':r' => $id_reserva]);
        $idEjemplar = (int)$q->fetchColumn();
        if ($idEjemplar > 0) {
            $conexion->prepare("UPDATE Ejemplar SET estado_ejemplar = 'Disponible' WHERE id_ejemplar = :e AND estado_ejemplar = 'Reservado'")
                ->execute([':e' => $idEjemplar]);
        }
    }
    $conexion->prepare("UPDATE Reserva SET estado = 'Cancelada' WHERE id_reserva = :r AND estado = 'Pendiente'")
        ->execute([':r' => $id_reserva]);
    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => 'Reserva cancelada correctamente.']);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] cancelar_reserva: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo cancelar la reserva.'], 500);
}
?>