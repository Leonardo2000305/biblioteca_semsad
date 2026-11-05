<?php
// ARCHIVO: cambiar_estado_usuario_sirve_para_suspender_o_reactivar.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Suspender (desactivar) o reactivar la cuenta de un usuario.
// QUÉ HACE: Recibe por POST el id del usuario y el estado nuevo (Activo o Inactivo). Valida los datos y evita que un bibliotecario toque cuentas de Administrador.
// Guarda el cambio en la base de datos y responde un JSON con el resultado.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; reglas_prestamos_sirve_para_multas_y_plazos.php (se incluye al inicio; revisar si este archivo usa algo de él); pantalla de administración de usuarios (revisar qué archivo HTML la llama).
require_once 'conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

// Solo se acepta POST. Cualquier otro método recibe error 405 (al final del archivo).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lee los datos en JSON. Si no llegan así, toma los datos de un formulario ($_POST).
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_usuario = (int)($input['id_usuario'] ?? $_POST['id_usuario'] ?? 0);
    $nuevo_estado = trim($input['estado'] ?? $_POST['estado'] ?? '');

    // Validación: el id debe ser mayor que cero y el estado solo puede ser Activo o Inactivo.
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
        // Si la base no tiene ningún valor de «baja» para usuarios, no se puede desactivar la cuenta.
        if ($valor === null) {
            semsad_responder(['status' => 'error', 'mensaje' => 'La base de datos no tiene un estado de baja para usuarios.'], 500);
        }
        // Guarda el nuevo estado en la cuenta del usuario.
        $stmt = $conexion->prepare("UPDATE usuario SET estado = :e WHERE id_usuario = :id");
        $stmt->execute([':e' => $valor, ':id' => $id_usuario]);

        // Respuesta de éxito con el mensaje del nuevo estado.
        semsad_responder([
            'status'  => 'success',
            'mensaje' => "Estado del usuario actualizado a $nuevo_estado correctamente."
        ]);
    // Si ocurre un error al guardar, se responde con 500. El detalle técnico solo aparece en modo depuración.
    } catch (Exception $e) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al cambiar estado.' . semsad_detalle_error($e)], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
