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
// CONFIGURACIÓN DE RESEND API (CON TU API KEY INTEGRADA)
// =========================================================================
define('RESEND_API_KEY', 're_ScKYq1Bb_JiDNbFfHd8STf2d2hzXhHVC3');

/**
 * Función para despachar correos garantizados usando el API REST de Resend por HTTPS
 */
function enviarCorreoAPI($toEmail, $subject, $bodyHtml) {
    $url = 'https://api.resend.com/emails';

    $payload = [
        'from'    => 'Biblioteca SEMSAD <onboarding@resend.dev>',
        'to'      => [$toEmail],
        'subject' => $subject,
        'html'    => $bodyHtml
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
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
        // 1. Verificar si el correo ya existe
        $stmtUser = $conexion->prepare("SELECT id_usuario FROM usuario WHERE LOWER(correo) = :correo");
        $stmtUser->execute([':correo' => $correo]);
        if ($stmtUser->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo electrónico ya se encuentra registrado.']);
            exit;
        }

        // 2. Verificar si el teléfono ya está registrado
        $stmtTel = $conexion->prepare("SELECT id_usuario FROM usuario WHERE telefono = :telefono AND telefono != ''");
        $stmtTel->execute([':telefono' => $telefono]);
        if ($stmtTel->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El número de teléfono ya está registrado en otra cuenta.']);
            exit;
        }

        // 3. Generar OTP de 6 dígitos (Válido por 15 minutos)
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expiracion = date('Y-m-d H:i:s', time() + (15 * 60));

        // 4. Guardar código temporal en la base de datos
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE correo = :correo");
        $stmtDel->execute([':correo' => $correo]);

        $stmtIns = $conexion->prepare("INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:correo, :codigo, :expiracion)");
        $stmtIns->execute([
            ':correo'     => $correo,
            ':codigo'     => $otp,
            ':expiracion' => $expiracion
        ]);

        // 5. Plantilla HTML profesional del correo privado
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

        // 6. Enviar vía Resend API
        $exito = enviarCorreoAPI($correo, $asunto, $cuerpoHtml);

        if ($exito) {
            echo json_encode([
                'status'  => 'success',
                'mensaje' => '¡Código de verificación enviado! Revisa la bandeja de entrada o Spam de ' . $correo
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Error al despachar el correo electrónico. Intente nuevamente en unos instantes.'
            ]);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de servidor: ' . $e->getMessage()]);
    }
}
?>
