<?php
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));
$token   = trim((string)($entrada['token'] ?? ''));
$nueva   = (string)($entrada['password'] ?? $entrada['clave'] ?? '');

if ($correo === '' || $token === '' || $nueva === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos para cambiar la contraseña. Abre de nuevo el enlace de tu correo.']);
}
if (strlen($nueva) < 6) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
}
if (strlen($nueva) > 72) {   // bcrypt ignora lo que pase de 72 bytes
    semsad_responder(['status' => 'error', 'mensaje' => 'La nueva contraseña no puede superar los 72 caracteres.']);
}

try {
    $q = $conexion->prepare('SELECT id_usuario, contrasena, estado FROM usuario WHERE LOWER(correo) = :c LIMIT 1');
    $q->execute([':c' => $correo]);
    $usuario = $q->fetch();
    $idUsuario = $usuario ? (int)$usuario['id_usuario'] : null;

    $valido = $usuario
        && ($usuario['estado'] ?? 'Activo') === 'Activo'
        && semsad_validar_token($cfg, $token, $correo, (string)($usuario['contrasena'] ?? ''));

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
    semsad_responder(['status' => 'success', 'mensaje' => '¡Cambio de clave exitoso! Ya puedes iniciar sesión con tu nueva contraseña.']);
} catch (Throwable $e) {
    error_log('[SEMSAD] restablecer_contrasena: ' . $e->getMessage());
    if (isset($conexion) && $conexion instanceof PDO) {
        semsad_auditar($conexion, $correo, null, 'Cambio fallido');
    }
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error: ' . $e->getMessage() : 'Error interno del servidor. Inténtalo de nuevo.',
    ], 500);
}
