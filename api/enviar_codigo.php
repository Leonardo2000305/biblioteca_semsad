<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('America/La_Paz');

if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_samesite', 'None');
    @ini_set('session.cookie_secure', 'True');
    session_start();
}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'conexion.php';

// =========================================================================
// CONFIGURACIÓN DE ENVÍO SMTP REAL A CORREOS EXTERNOS (GMAIL, HOTMAIL, ETC)
// =========================================================================
define('SMTP_HOST', 'smtp.alwaysdata.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'robertleonardoyujra@gmail.com');
define('SMTP_PASS', 'Jueg012345');
define('SMTP_FROM', 'robertleonardoyujra@gmail.com');
define('SMTP_NAME', 'Biblioteca SEMSAD');

/**
 * Función para despachar el correo electrónico real
 */
function enviarCorreoReal($to, $subject, $bodyHtml) {
    $enviadoSocket = false;
    $socket = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 8);

    if ($socket) {
        fgets($socket, 512);
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        while ($line = fgets($socket, 512)) {
            if (substr($line, 3, 1) == ' ') break;
        }

        fputs($socket, "STARTTLS\r\n");
        fgets($socket, 512);
        @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        while ($line = fgets($socket, 512)) {
            if (substr($line, 3, 1) == ' ') break;
        }

        fputs($socket, "AUTH LOGIN\r\n");
        fgets($socket, 512);
        fputs($socket, base64_encode(SMTP_USER) . "\r\n");
        fgets($socket, 512);
        fputs($socket, base64_encode(SMTP_PASS) . "\r\n");
        $authRes = fgets($socket, 512);

        if (substr($authRes, 0, 3) == '235') {
            fputs($socket, "MAIL FROM: <" . SMTP_FROM . ">\r\n");
            fgets($socket, 512);
            fputs($socket, "RCPT TO: <$to>\r\n");
            fgets($socket, 512);
            fputs($socket, "DATA\r\n");
            fgets($socket, 512);

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . SMTP_NAME . " <" . SMTP_FROM . ">\r\n";
            $headers .= "To: <$to>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";

            $data = $headers . "\r\n" . $bodyHtml . "\r\n.\r\n";
            fputs($socket, $data);
            fgets($socket, 512);

            fputs($socket, "QUIT\r\n");
            $enviadoSocket = true;
        }
        fclose($socket);
    }

    // Doble garantía de envío por función mail() del servidor si el socket requiere respaldo
    if (!$enviadoSocket) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . SMTP_NAME . " <" . SMTP_FROM . ">\r\n";
        $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
        @mail($to, $subject, $bodyHtml, $headers);
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $correo   = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $codigo   = isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';
    $telefono = isset($input['telefono']) ? trim($input['telefono']) : '';

    if (empty($correo) || empty($codigo) || empty($telefono)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Todos los datos son obligatorios.']);
        exit;
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese una dirección de correo válida.']);
        exit;
    }

    try {
        // 1. Verificar que el correo no exista ya registrado
        $stmtUser = $conexion->prepare("SELECT id_usuario FROM usuario WHERE LOWER(correo) = :correo");
        $stmtUser->execute([':correo' => $correo]);
        if ($stmtUser->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo electrónico ya se encuentra registrado.']);
            exit;
        }

        // 2. Verificar que el teléfono no esté en uso
        $stmtTel = $conexion->prepare("SELECT id_usuario FROM usuario WHERE telefono = :telefono AND telefono != ''");
        $stmtTel->execute([':telefono' => $telefono]);
        if ($stmtTel->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El número de teléfono ya está registrado en otra cuenta.']);
            exit;
        }

        // 3. Generar código OTP aleatorio de 6 dígitos (Válido por 15 minutos)
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expiracion = date('Y-m-d H:i:s', time() + (15 * 60));

        // 4. Registrar en la BD el código temporal
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE correo = :correo");
        $stmtDel->execute([':correo' => $correo]);

        $stmtIns = $conexion->prepare("INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:correo, :codigo, :expiracion)");
        $stmtIns->execute([
            ':correo'     => $correo,
            ':codigo'     => $otp,
            ':expiracion' => $expiracion
        ]);

        // 5. Estructurar plantilla HTML para el mensaje
        $asunto = "Código de Verificación - Biblioteca SEMSAD";
        $cuerpoHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; background-color: #ffffff;'>
            <h2 style='color: #2563eb; text-align: center; margin-bottom: 8px;'>Biblioteca SEMSAD</h2>
            <p style='color: #475569; text-align: center; margin-top: 0;'>Verificación de Seguridad</p>
            <hr style='border: none; border-top: 1px solid #f1f5f9; margin: 20px 0;'>
            <p style='color: #334155;'>Hola,</p>
            <p style='color: #334155;'>Tu código de verificación privado para completar tu registro es:</p>
            <div style='background-color: #f8fafc; border: 2px dashed #2563eb; border-radius: 8px; padding: 16px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1e293b; margin: 20px 0;'>
                {$otp}
            </div>
            <p style='color: #64748b; font-size: 13px;'>Este código caducará en 15 minutos. No compartas este código con nadie.</p>
        </div>";

        // 6. Enviar el correo REAL al correo ingresado
        enviarCorreoReal($correo, $asunto, $cuerpoHtml);

        // 7. Respuesta pública limpia (Sugerir revisar la bandeja de entrada o Spam)
        echo json_encode([
            'status'  => 'success',
            'mensaje' => '¡Código de verificación enviado! Revisa la bandeja de entrada o la carpeta de Spam de ' . $correo
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de servidor: ' . $e->getMessage()]);
    }
}
?>
