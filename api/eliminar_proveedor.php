<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$id = (int)($input['id_proveedor'] ?? 0);
if ($id <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de proveedor no válido.'], 400);

try {
    $stmt = $conexion->prepare("SELECT nombre FROM Proveedor WHERE id_proveedor = :id");
    $stmt->execute([':id' => $id]);
    $nombre = $stmt->fetchColumn();
    if (!$nombre) semsad_responder(['status' => 'error', 'mensaje' => 'El proveedor no existe.'], 404);

    $q = $conexion->prepare("SELECT COUNT(*) FROM Adquisicion WHERE id_proveedor = :id");
    $q->execute([':id' => $id]);
    if ((int)$q->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'No se puede eliminar: el proveedor tiene adquisiciones históricas vinculadas.'], 409);
    }

    $conexion->prepare("DELETE FROM Proveedor WHERE id_proveedor = :id")->execute([':id' => $id]);
    semsad_responder(['status' => 'success', 'mensaje' => "Proveedor \"$nombre\" eliminado correctamente."]);
} catch (Throwable $e) {
    error_log('[SEMSAD] eliminar_proveedor: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo eliminar el proveedor.'], 500);
}
?>