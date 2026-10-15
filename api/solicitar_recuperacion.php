<?php
ob_start();
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));

if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingrese un correo electrónico válido.']);
}

try {
    // 1. Verificar si el usuario existe en la BD
    $stmt = $conexion->prepare('SELECT id_usuario FROM usuario WHERE LOWER(correo) = :c LIMIT 1');
    $stmt->execute([':c' => $correo]);
    if (!$stmt->fetch()) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'No existe ninguna cuenta registrada con este correo.']);
    }

    // 2. Generar código OTP de 6 dígitos (Vigencia 5 min)
    $otp        = (string)random_int(100000, 999999);
    $expiracion = date('Y-m-d H:i:s', time() + 300);

    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
    $conexion->prepare('INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:c, :o, :e)')
             ->execute([':c' => $correo, ':o' => $otp, ':e' => $expiracion]);

    // 3. Dominio real de Vercel (SIN GUION) apuntando a restablecer.html
    $linkWeb = 'https://bibliotecasemsad.vercel.app/restablecer.html?correo=' . urlencode($correo) . '&codigo=' . urlencode($otp);

    // 4. Plantilla de Correo HTML con soporte UTF-8 completo
    $asunto  = '=?UTF-8?B?' . base64_encode('Restablecer Contraseña - Biblioteca SEMSAD') . '?=';
    $cuerpo  = "
    <!DOCTYPE html>
    <html lang='es'>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; background-color: #f4f5f7; padding: 20px; margin: 0;'>
        <div style='max-width: 480px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 24px; border: 1px solid #e5e7eb;'>
            <h2 style='color: #1d4ed8; margin-top: 0;'>Biblioteca SEMSAD</h2>
            <p style='color: #374151; font-size: 15px;'>Hola,</p>
            <p style='color: #374151; font-size: 15px;'>Has solicitado restablecer tu contraseña. Haz clic en el siguiente botón para crear una nueva:</p>
            
            <div style='text-align: center; margin: 25px 0;'>
                <a href='$linkWeb' style='background-color: #1d4ed8; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; border-radius: 6px; display: inline-block;'>
                    Restablecer mi Contraseña
                </a>
            </div>

            <p style='color: #6b7280; font-size: 13px; text-align: center;'>Código manual de verificación: <b style='color: #1e40af;'>$otp</b></p>
            <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
            <p style='font-size: 12px; color: #9ca3af; margin-bottom: 0;'>Este enlace caduca en <b>5 minutos</b>. Si no solicitaste el cambio, ignora este mensaje.</p>
        </div>
    </body>
    </html>
    ";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Biblioteca SEMSAD <no-reply@semsad.edu.bo>\r\n";
    $headers .= "Reply-To: soporte@semsad.edu.bo\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    $enviado = @mail($correo, $asunto, $cuerpo, $headers);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => 'Instrucciones enviadas a tu correo. Revisa tu bandeja de entrada o SPAM.']);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error interno del servidor.'], 500);
}
