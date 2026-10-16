<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$id = (int)($input['id_proveedor'] ?? 0);
$nombre = trim((string)($input['nombre'] ?? ''));
$tipo = trim((string)($input['tipo'] ?? ''));
$contacto = trim((string)($input['contacto'] ?? ''));

if ($nombre === '' || $tipo === '') semsad_responder(['status' => 'error', 'mensaje' => 'Nombre y tipo son obligatorios.'], 400);
if (mb_strlen($nombre) > 100 || mb_strlen($tipo) > 50 || mb_strlen($contacto) > 100) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Uno de los campos supera la longitud permitida.'], 400);
}

try {
    if ($id > 0) {
        $check = $conexion->prepare("SELECT id_proveedor FROM Proveedor WHERE id_proveedor = :id");
        $check->execute([':id' => $id]);
        if (!$check->fetchColumn()) semsad_responder(['status' => 'error', 'mensaje' => 'El proveedor no existe.'], 404);
        $stmt = $conexion->prepare("UPDATE Proveedor SET nombre = :n, tipo = :t, contacto = :c WHERE id_proveedor = :id");
        $stmt->execute([':n' => $nombre, ':t' => $tipo, ':c' => ($contacto !== '' ? $contacto : null), ':id' => $id]);
        semsad_responder(['status' => 'success', 'mensaje' => 'Proveedor actualizado correctamente.', 'id_proveedor' => $id]);
    }

    $stmtDup = $conexion->prepare("SELECT COUNT(*) FROM Proveedor WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(:n)) AND tipo = :t");
    $stmtDup->execute([':n' => $nombre, ':t' => $tipo]);
    if ((int)$stmtDup->fetchColumn() > 0) semsad_responder(['status' => 'error', 'mensaje' => 'Ya existe un proveedor con ese nombre y tipo.'], 409);

    $stmt = $conexion->prepare("INSERT INTO Proveedor (nombre, tipo, contacto) VALUES (:n, :t, :c)");
    $stmt->execute([':n' => $nombre, ':t' => $tipo, ':c' => ($contacto !== '' ? $contacto : null)]);
    semsad_responder(['status' => 'success', 'mensaje' => 'Proveedor registrado correctamente.', 'id_proveedor' => (int)$conexion->lastInsertId()]);
} catch (Throwable $e) {
    error_log('[SEMSAD] guardar_proveedor: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo guardar el proveedor.'], 500);
}
?>