<?php
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));

if ($correo === '' || !semsad_correo_valido($correo)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa un correo electrónico válido.']);
}

// Respuesta única: no revela si el correo existe, si la cuenta está suspendida o si el envío salió bien.
$mensajeGenerico = 'Si el correo está registrado y activo, te enviamos un enlace para crear una nueva contraseña. Revisa tu bandeja de entrada o SPAM (caduca en 15 minutos).';

// Límite por conexión: 5 solicitudes cada 15 minutos
$claveIp = 'recup:ip:' . semsad_ip();
if (semsad_limite_espera($conexion, $claveIp) > 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Demasiadas solicitudes desde tu conexión. Espera unos minutos e inténtalo de nuevo.'], 429);
}
semsad_limite_fallo($conexion, $claveIp, 5, 900, 900);

try {
    $q = $conexion->prepare('SELECT id_usuario, contrasena, estado FROM usuario WHERE LOWER(correo) = :c LIMIT 1');
    $q->execute([':c' => $correo]);
    $usuario = $q->fetch();

    // 1. La cuenta no existe: queda registrado para el administrador, pero el visitante no lo sabe
    if (!$usuario) {
        semsad_auditar($conexion, $correo, null, 'Correo No Encontrado');
        semsad_responder(['status' => 'success', 'mensaje' => $mensajeGenerico]);
    }

    // 2. Cuenta suspendida o bloqueada
    if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
        semsad_auditar($conexion, $correo, (int)$usuario['id_usuario'], 'Cuenta no activa');
        semsad_responder(['status' => 'success', 'mensaje' => $mensajeGenerico]);
    }

    // 3. Evita el abuso: un enlace por minuto para cada correo
    if (semsad_espera_recuperacion($conexion, $correo) > 0) {
        semsad_responder(['status' => 'success', 'mensaje' => $mensajeGenerico]);
    }

    // 4. Enlace firmado de un solo uso (15 minutos)
    $token = semsad_crear_token($cfg, $correo, (string)($usuario['contrasena'] ?? ''), 900);
    $link  = rtrim($cfg['frontend_url'], '/') . '/login/restablecer.html?correo=' . urlencode($correo) . '&token=' . urlencode($token);
    $linkH = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $asunto  = '=?UTF-8?B?' . base64_encode('Restablecer Contraseña - Biblioteca SEMSAD') . '?=';
    $cuerpo  = "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'></head>
<body style='font-family: Arial, sans-serif; background-color: #f4f5f7; padding: 20px; margin: 0;'>
  <div style='max-width: 480px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 24px; border: 1px solid #e5e7eb;'>
    <h2 style='color: #1d4ed8; margin-top: 0;'>Biblioteca SEMSAD</h2>
    <p style='color: #374151; font-size: 15px;'>Hola,</p>
    <p style='color: #374151; font-size: 15px;'>Recibimos una solicitud para restablecer tu contraseña. Haz clic en el botón para crear una nueva:</p>
    <div style='text-align: center; margin: 25px 0;'>
      <a href='$linkH' style='background-color: #1d4ed8; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 6px; display: inline-block;'>Restablecer mi contraseña</a>
    </div>
    <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
    <p style='font-size: 12px; color: #9ca3af; margin-bottom: 0;'>Este enlace caduca en <b>15 minutos</b> y solo se puede usar una vez. Si no lo solicitaste, ignora este mensaje: tu contraseña no cambiará.</p>
  </div>
</body></html>";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
             . 'From: Biblioteca SEMSAD <' . $cfg['mail_from'] . ">\r\n"
             . "X-Mailer: PHP/" . phpversion();

    $enviado = @mail($correo, $asunto, $cuerpo, $headers);

    if (!$enviado && empty($cfg['otp_demo'])) {
        error_log('[SEMSAD] mail() no pudo enviar el enlace de recuperación a ' . $correo);
        semsad_auditar($conexion, $correo, (int)$usuario['id_usuario'], 'Correo no enviado');
        semsad_responder(['status' => 'success', 'mensaje' => $mensajeGenerico]);
    }

    semsad_auditar($conexion, $correo, (int)$usuario['id_usuario'], 'Solicitud enviada');

    $respuesta = ['status' => 'success', 'mensaje' => $mensajeGenerico];
    if (!empty($cfg['otp_demo'])) {
        $respuesta['enlace_demo'] = $link;   // solo en modo prueba (otp_demo = true en config.php)
    }
    semsad_responder($respuesta);
} catch (Throwable $e) {
    error_log('[SEMSAD] solicitar_recuperacion: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'Error interno del servidor. Inténtalo de nuevo en unos minutos.' . semsad_detalle_error($e)], 500);
}
