<?php
require_once __DIR__ . '/conexion.php';   // CORS restringido y cabeceras JSON comunes
semsad_iniciar_sesion();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

semsad_responder(['status' => 'success', 'mensaje' => 'Sesión cerrada exitosamente.']);
