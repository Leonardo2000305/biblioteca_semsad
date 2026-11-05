<?php
// ARCHIVO: cambiar_estado_ejemplar_sirve_para_mantenimiento_o_baja.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Cambiar el estado de una copia (ejemplar) de un libro: mandarla a mantenimiento, darla de baja o dejarla disponible.
// QUÉ HACE: Recibe por POST un JSON con id_ejemplar y el nuevo estado. Valida los datos, revisa las reglas y guarda el cambio.
// Solo puede usarlo el personal de la biblioteca. Responde un JSON con status y mensaje.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de administración de inventario (revisar qué archivo HTML la llama).
/**
 * Cambia el estado de un ejemplar: poner en mantenimiento, dar de baja o reactivar.
 * POST { id_ejemplar, estado }  con estado ∈ {Disponible, En Mantenimiento, Baja}
 *
 * Reglas:
 * - Un ejemplar prestado o reservado no puede pasar a mantenimiento ni a baja.
 * - Baja es un estado final: no se reactiva.
 * - Solo personal de la biblioteca.
 */
require_once 'conexion_sirve_para_conectar_base_datos.php';
// Solo el Administrador o el Bibliotecario pueden usar este archivo. Si no cumplen, la petición se corta aquí.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

// Solo se acepta POST. Cualquier otro método recibe un error.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
    exit;
}

// Lee el cuerpo de la petición en formato JSON. Si no llega nada válido, usa un arreglo vacío.
$input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
// Lee el id del ejemplar (como número) y el nuevo estado (como texto, sin espacios sobrantes).
$idEjemplar = (int)($input['id_ejemplar'] ?? 0);
$nuevo = trim((string)($input['estado'] ?? ''));
// Lista de estados permitidos: cualquier otro valor se rechaza.
$permitidos = ['Disponible', 'En Mantenimiento', 'Baja'];

// Validación: el id debe ser mayor que cero y el estado debe estar en la lista permitida.
if ($idEjemplar <= 0 || !in_array($nuevo, $permitidos, true)) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Datos no válidos.']);
    exit;
}

try {
    // Inicia una transacción: los cambios se confirman todos juntos, o no se guarda ninguno.
    $conexion->beginTransaction();

    // Bloquea la fila para que dos cambios simultáneos no se pisen
    $stmt = $conexion->prepare("SELECT estado_ejemplar FROM ejemplar WHERE id_ejemplar = :e FOR UPDATE");
    $stmt->execute([':e' => $idEjemplar]);
    $actual = $stmt->fetchColumn();

    // Si el ejemplar no existe, se deshace la transacción y se responde con error.
    if ($actual === false) {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'El ejemplar no existe.']);
        exit;
    }
    // Regla: la baja es un estado final. Un ejemplar dado de baja no vuelve a usarse.
    if ($actual === 'Baja') {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'Un ejemplar dado de baja no se puede reactivar.']);
        exit;
    }
    // Regla: un ejemplar prestado o reservado solo puede volver a Disponible. Mientras esté en uso no pasa a mantenimiento ni a baja.
    if (in_array($actual, ['Prestado', 'Reservado'], true) && $nuevo !== 'Disponible') {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'El ejemplar está ' . $actual . '. Primero debe devolverse o liberarse.']);
        exit;
    }
    // Si ya está en el estado pedido, no se cambia nada y se responde con éxito.
    if ($nuevo === $actual) {
        $conexion->rollBack();
        echo json_encode(['status' => 'success', 'mensaje' => 'El ejemplar ya estaba en ese estado.']);
        exit;
    }

    // Guarda el nuevo estado y confirma la transacción.
    $conexion->prepare("UPDATE ejemplar SET estado_ejemplar = :n WHERE id_ejemplar = :e")
             ->execute([':n' => $nuevo, ':e' => $idEjemplar]);
    $conexion->commit();

    // Textos que se muestran a la persona según el nuevo estado.
    $mensajes = [
        'Disponible' => 'El ejemplar volvió a estar disponible.',
        'En Mantenimiento' => 'El ejemplar quedó en mantenimiento.',
        'Baja' => 'El ejemplar fue dado de baja.',
    ];
    // Respuesta de éxito con el mensaje correspondiente.
    echo json_encode(['status' => 'success', 'mensaje' => $mensajes[$nuevo]]);
} catch (PDOException $e) {
    // Si hubo un error con la base de datos y la transacción sigue abierta, se deshace.
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // Guarda el error técnico en el log del servidor. A la persona solo se le muestra un mensaje sencillo.
    error_log('cambiar_estado_ejemplar: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo cambiar el estado del ejemplar.']);
}
