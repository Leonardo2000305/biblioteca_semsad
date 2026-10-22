<?php
/**
 * Cambia el estado de un ejemplar: poner en mantenimiento, dar de baja o reactivar.
 * POST { id_ejemplar, estado }  con estado ∈ {Disponible, En Mantenimiento, Baja}
 *
 * Reglas:
 * - Un ejemplar prestado o reservado no puede pasar a mantenimiento ni a baja.
 * - Baja es un estado final: no se reactiva.
 * - Solo personal de la biblioteca.
 */
require_once 'conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$idEjemplar = (int)($input['id_ejemplar'] ?? 0);
$nuevo = trim((string)($input['estado'] ?? ''));
$permitidos = ['Disponible', 'En Mantenimiento', 'Baja'];

if ($idEjemplar <= 0 || !in_array($nuevo, $permitidos, true)) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Datos no válidos.']);
    exit;
}

try {
    $conexion->beginTransaction();

    // Bloquea la fila para que dos cambios simultáneos no se pisen
    $stmt = $conexion->prepare("SELECT estado_ejemplar FROM ejemplar WHERE id_ejemplar = :e FOR UPDATE");
    $stmt->execute([':e' => $idEjemplar]);
    $actual = $stmt->fetchColumn();

    if ($actual === false) {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'El ejemplar no existe.']);
        exit;
    }
    if ($actual === 'Baja') {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'Un ejemplar dado de baja no se puede reactivar.']);
        exit;
    }
    if (in_array($actual, ['Prestado', 'Reservado'], true) && $nuevo !== 'Disponible') {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'El ejemplar está ' . $actual . '. Primero debe devolverse o liberarse.']);
        exit;
    }
    if ($nuevo === $actual) {
        $conexion->rollBack();
        echo json_encode(['status' => 'success', 'mensaje' => 'El ejemplar ya estaba en ese estado.']);
        exit;
    }

    $conexion->prepare("UPDATE ejemplar SET estado_ejemplar = :n WHERE id_ejemplar = :e")
             ->execute([':n' => $nuevo, ':e' => $idEjemplar]);
    $conexion->commit();

    $mensajes = [
        'Disponible' => 'El ejemplar volvió a estar disponible.',
        'En Mantenimiento' => 'El ejemplar quedó en mantenimiento.',
        'Baja' => 'El ejemplar fue dado de baja.',
    ];
    echo json_encode(['status' => 'success', 'mensaje' => $mensajes[$nuevo]]);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('cambiar_estado_ejemplar: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo cambiar el estado del ejemplar.']);
}
