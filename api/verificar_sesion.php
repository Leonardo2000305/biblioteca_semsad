<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (isset($_SESSION['id_usuario'])) {
    echo json_encode([
        'status' => 'success',
        'sesion_activa' => true,
        'usuario' => [
            'id_usuario' => $_SESSION['id_usuario'],
            'nombre' => $_SESSION['nombre'],
            'rol' => $_SESSION['rol'],
            'ci' => isset($_SESSION['ci']) ? $_SESSION['ci'] : ''
        ]
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'sesion_activa' => false,
        'mensaje' => 'No hay una sesión activa.'
    ]);
}
?>
