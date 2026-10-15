<?php
ob_start();
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));

if ($correo === '') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingrese su correo electrónico.']);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingrese un correo electrónico válido.']);
}

try {
    // 1. Verificar si el usuario existe
    $stmt = $conexion->prepare('SELECT id_usuario FROM usuario WHERE LOWER(correo) = :c LIMIT 1');
    $stmt->execute([':c' => $correo]);
    if (!$stmt->fetch()) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'No existe ninguna cuenta registrada con este correo.']);
    }

    // 2. Generar código OTP de 6 dígitos
    $otp        = (string)random_int(100000, 999999);
    $expiracion = date('Y-m-d H:i:s', time() + 300);

    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
    $conexion->prepare('INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:c, :o, :e)')
             ->execute([':c' => $correo, ':o' => $otp, ':e' => $expiracion]);

    // 3. Envío mediante mail() nativo (Misma lógica exacta que enviar_codigo.php)
    $asunto  = 'Restablecer Contrasena - Biblioteca SEMSAD';
    $cuerpo  = "Hola,\n\nTu codigo de verificacion para restablecer tu contrasena en la Biblioteca SEMSAD es: $otp\n\nEste codigo caduca en 5 minutos.";
    $headers = "From: no-reply@semsad.edu.bo\r\nReply-To: soporte@semsad.edu.bo\r\nX-Mailer: PHP/" . phpversion();
    $enviado = @mail($correo, $asunto, $cuerpo, $headers);

    $mensaje = 'Código de recuperación enviado a ' . $correo . '. Revisa tu correo o SPAM.';
    if (!empty($cfg['otp_demo'])) {
        $mensaje .= ' (Modo prueba — tu código es: ' . $otp . ')';
    } elseif (!$enviado) {
        error_log('[SEMSAD] mail() no pudo enviar el código de recuperación a ' . $correo);
    }

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => $mensaje]);

} catch (PDOException $e) {
    error_log('[SEMSAD] solicitar_recuperacion: ' . $e->getMessage());
    if (ob_get_length()) ob_clean();
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error en base de datos: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
