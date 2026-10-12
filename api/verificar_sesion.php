<?php
// Permitir cookies de sesión en entornos con HTTPS/Vercel
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', 'True');
    session_start();
}

// Configuración dinámica de CORS para permitir envío de cookies de sesión
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar si existe un ID de usuario guardado en la sesión
$id_usuario = $_SESSION['id_usuario'] ?? $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? null;

if ($id_usuario !== null) {
    echo json_encode([
        'status' => 'success',
        'sesion_activa' => true,
        'usuario' => [
            'id_usuario' => $id_usuario,
            'nombre'     => $_SESSION['nombre'] ?? $_SESSION['nombre_usuario'] ?? 'Usuario',
            'rol'        => $_SESSION['rol'] ?? $_SESSION['id_rol'] ?? 'estudiante',
            'ci'         => $_SESSION['ci'] ?? $_SESSION['codigo'] ?? ''
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
