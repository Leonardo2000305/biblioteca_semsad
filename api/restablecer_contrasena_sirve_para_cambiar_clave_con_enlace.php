<?php
// ARCHIVO: restablecer_contrasena_sirve_para_cambiar_clave_con_enlace.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: cambiar la contraseña olvidada usando el enlace que llegó al correo.
// QUÉ HACE: Recibe el correo, el token y la nueva clave. Revisa que la clave cumpla las reglas y que el enlace sea válido y no esté vencido.
//   Si todo está bien, guarda la clave nueva cifrada y responde en JSON.
// CONECTA CON: login/restablecer.html (formulario de nueva contraseña); el enlace lo crea solicitar_recuperacion_sirve_para_enviar_enlace_de_clave.php
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';

// Solo se acepta POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

// Lee el JSON: correo, token y la nueva contraseña (acepta 'password' o 'clave').
$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));
$token   = trim((string)($entrada['token'] ?? ''));
$nueva   = (string)($entrada['password'] ?? $entrada['clave'] ?? '');

// Validación: deben venir correo, token y la nueva clave.
if ($correo === '' || $token === '' || $nueva === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos para cambiar la contraseña. Abre de nuevo el enlace de tu correo.']);
}
// Validación: la nueva clave debe cumplir las reglas de seguridad.
if ($errorClave = semsad_clave_error($nueva)) {
    semsad_responder(['status' => 'error', 'mensaje' => $errorClave]);
}

try {
    // Busca la cuenta por correo, sin distinguir mayúsculas.
    $q = $conexion->prepare('SELECT id_usuario, contrasena, estado FROM usuario WHERE LOWER(correo) = :c LIMIT 1');
    $q->execute([':c' => $correo]);
    $usuario = $q->fetch();
    $idUsuario = $usuario ? (int)$usuario['id_usuario'] : null;

    // El enlace sirve solo si la cuenta está Activa y el token es válido (firma, correo y tiempo).
    $valido = $usuario
        && ($usuario['estado'] ?? 'Activo') === 'Activo'
        && semsad_validar_token($cfg, $token, $correo, (string)($usuario['contrasena'] ?? ''));

    // Si el enlace no sirve, se guarda el intento fallido en la auditoría y se responde con error.
    if (!$valido) {
        semsad_auditar($conexion, $correo, $idUsuario, 'Cambio fallido');
        semsad_responder([
            'status'  => 'error',
            'mensaje' => 'El enlace no es válido, ya fue usado o venció. Solicita uno nuevo.',
            'codigo'  => 'enlace_invalido',
        ]);
    }

    // Al guardar la contraseña nueva, el enlace anterior deja de servir (la firma depende de la contraseña actual).
    $conexion->prepare('UPDATE usuario SET contrasena = :p WHERE id_usuario = :id')
             ->execute([':p' => password_hash($nueva, PASSWORD_BCRYPT), ':id' => $idUsuario]);

    semsad_auditar($conexion, $correo, $idUsuario, 'Cambio exitoso');
    // Respuesta JSON de éxito.
    semsad_responder(['status' => 'success', 'mensaje' => '¡Cambio de clave exitoso! Ya puedes iniciar sesión con tu nueva contraseña.']);
} catch (Throwable $e) {
    // Error inesperado: se guarda en el log, se audita el intento y se responde 500.
    error_log('[SEMSAD] restablecer_contrasena: ' . $e->getMessage());
    if (isset($conexion) && $conexion instanceof PDO) {
        semsad_auditar($conexion, $correo, null, 'Cambio fallido');
    }
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error interno del servidor. Inténtalo de nuevo.' . semsad_detalle_error($e),
    ], 500);
}
