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
        // Un bibliotecario no puede suspender ni reactivar cuentas de Administrador
        if (($_SESSION['rol'] ?? '') !== 'Administrador') {
            $rolDestino = $conexion->prepare('SELECT id_rol FROM usuario WHERE id_usuario = :id');
            $rolDestino->execute([':id' => $id_usuario]);
            if ((int)$rolDestino->fetchColumn() === 1) {
                semsad_responder(['status' => 'error', 'mensaje' => 'Solo un Administrador puede cambiar el estado de una cuenta de Administrador.'], 403);
            }
        }

        // La columna estado puede ser ENUM('Activo','Suspendido','Bloqueado'): se usa el valor que exista de verdad.
        $valor = $nuevo_estado === 'Activo'
            ? 'Activo'
            : semsad_valor_enum($conexion, 'usuario', 'estado', ['Inactivo', 'Suspendido', 'Bloqueado']);
        if ($valor === null) {
            semsad_responder(['status' => 'error', 'mensaje' => 'La base de datos no tiene un estado de baja para usuarios.'], 500);
        }
        $stmt = $conexion->prepare("UPDATE usuario SET estado = :e WHERE id_usuario = :id");
        $stmt->execute([':e' => $valor, ':id' => $id_usuario]);

        semsad_responder([
            'status'  => 'success',
            'mensaje' => "Estado del usuario actualizado a $nuevo_estado correctamente."
        ]);
    } catch (Exception $e) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al cambiar estado.' . semsad_detalle_error($e)], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
