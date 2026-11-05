<?php
// ARCHIVO: verificar_sesion_sirve_para_saber_si_hay_login.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: decirle a la página si hay alguien con la sesión iniciada.
// QUÉ HACE: Lee la sesión del servidor. Si hay un id de usuario, responde sesion_activa = true con nombre, rol y CI guardados en la sesión.
//   Si no hay id, responde sesion_activa = false. No consulta la base de datos.
// CONECTA CON: js/app.js (revisa la sesión al cargar la página); la sesión la crea login_sirve_para_iniciar_sesion.php
// Permitir cookies de sesión en entornos con HTTPS/Vercel
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', 'True');
    session_start();
}

// Configuración dinámica de CORS para permitir envío de cookies de sesión
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

// Responde a la verificación previa del navegador (OPTIONS) sin datos.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verificar si existe un ID de usuario guardado en la sesión
$id_usuario = $_SESSION['id_usuario'] ?? $_SESSION['usuario_id'] ?? $_SESSION['id'] ?? null;

// Si hay id en la sesión, hay sesión activa: se responde con los datos guardados en ella.
if ($id_usuario !== null) {
    echo json_encode([
        'status' => 'success',
        'sesion_activa' => true,
        'usuario' => [
            'id_usuario' => $id_usuario,
            'nombre'     => $_SESSION['nombre'] ?? $_SESSION['nombre_usuario'] ?? 'usuario',
            'rol'        => $_SESSION['rol'] ?? $_SESSION['id_rol'] ?? 'estudiante',
            'ci'         => $_SESSION['ci'] ?? $_SESSION['codigo'] ?? ''
        ]
    ]);
} else {
    // Si no hay id en la sesión, no hay sesión activa.
    echo json_encode([
        'status' => 'error',
        'sesion_activa' => false,
        'mensaje' => 'No hay una sesión activa.'
    ]);
}
?>
