<?php
require_once 'conexion.php';
semsad_iniciar_sesion();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_usuario = (int)($input['id_usuario'] ?? $_POST['id_usuario'] ?? 0);
    $nuevo_estado = trim($input['estado'] ?? $_POST['estado'] ?? '');

    if ($id_usuario <= 0 || !in_array($nuevo_estado, ['Activo', 'Inactivo'])) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Parámetros no válidos.']);
    }

    try {
        $stmt = $conexion->prepare("UPDATE Usuario SET estado = :e WHERE id_usuario = :id");
        $stmt->execute([':e' => $nuevo_estado, ':id' => $id_usuario]);

        semsad_responder([
            'status'  => 'success',
            'mensaje' => "Estado del usuario actualizado a $nuevo_estado correctamente."
        ]);
    } catch (Exception $e) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al cambiar estado: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
