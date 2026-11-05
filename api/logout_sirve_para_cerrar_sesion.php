<?php
// ARCHIVO: logout_sirve_para_cerrar_sesion.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Cerrar la sesión del usuario (salir del sistema).
// QUÉ HACE: Vacía los datos de la sesión, borra la cookie de sesión del navegador y destruye la sesión en el servidor.
// No pide datos ni revisa el rol. Responde un JSON con un mensaje de éxito.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; login_sirve_para_iniciar_sesion.php (crea la sesión que este archivo cierra); botón de cerrar sesión de las páginas de la web (revisar qué archivos HTML lo llaman).
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';   // CORS restringido y cabeceras JSON comunes
semsad_iniciar_sesion();

// Vacía todos los datos guardados en la sesión.
$_SESSION = [];
// Borra la cookie de sesión del navegador poniéndole una fecha ya pasada.
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
// Destruye la sesión en el servidor.
session_destroy();

semsad_responder(['status' => 'success', 'mensaje' => 'Sesión cerrada exitosamente.']);
