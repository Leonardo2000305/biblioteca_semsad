<?php
require_once 'conexion.php';
require_once __DIR__ . '/ayudas_prestamo.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_usuario = (int)($input['id_usuario'] ?? $_POST['id_usuario'] ?? 0);
    $nuevo_estado = trim($input['estado'] ?? $_POST['estado'] ?? '');

    if ($id_usuario <= 0 || !in_array($nuevo_estado, ['Activo', 'Inactivo'])) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Parámetros no válidos.']);
    }

    try {
        // La columna estado puede ser ENUM('Activo','Suspendido','Bloqueado'): se usa el valor que exista de verdad.
        $valor = $nuevo_estado === 'Activo'
            ? 'Activo'
            : semsad_valor_enum($conexion, 'Usuario', 'estado', ['Inactivo', 'Suspendido', 'Bloqueado']);
        if ($valor === null) {
            semsad_responder(['status' => 'error', 'mensaje' => 'La base de datos no tiene un estado de baja para usuarios.'], 500);
        }
        $stmt = $conexion->prepare("UPDATE Usuario SET estado = :e WHERE id_usuario = :id");
        $stmt->execute([':e' => $valor, ':id' => $id_usuario]);

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
