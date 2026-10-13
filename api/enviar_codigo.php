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
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese una dirección de correo electrónico válida.']);
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

        // 2. Verificar que el teléfono no esté duplicado
        $stmtTel = $conexion->prepare("SELECT id_usuario FROM usuario WHERE telefono = :telefono AND telefono != ''");
        $stmtTel->execute([':telefono' => $telefono]);
        if ($stmtTel->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El número de teléfono ya está registrado en otra cuenta.']);
            exit;
        }

        // 3. Generar OTP de 6 dígitos y tiempo de expiración (15 minutos)
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expiracion = date('Y-m-d H:i:s', time() + (15 * 60));

        // 4. Guardar en base de datos
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE correo = :correo");
        $stmtDel->execute([':correo' => $correo]);

        $stmtIns = $conexion->prepare("INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:correo, :codigo, :expiracion)");
        $stmtIns->execute([
            ':correo'     => $correo,
            ':codigo'     => $otp,
            ':expiracion' => $expiracion
        ]);

        // 5. Enviar correo real por mail()
        $asunto = "Codigo de Verificacion - Biblioteca SEMSAD";
        $mensajeHtml = "
        <html>
        <head><title>Verificación de Cuenta</title></head>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 500px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                <h2 style='color: #2563eb;'>Biblioteca SEMSAD</h2>
                <p>Hola,</p>
                <p>Tu código de verificación OTP para completar tu registro es:</p>
                <div style='background-color: #f1f5f9; padding: 15px; text-align: center; border-radius: 8px; font-size: 26px; font-weight: bold; letter-spacing: 5px; color: #1e293b;'>
                    {$otp}
                </div>
                <p style='font-size: 12px; color: #64748b; margin-top: 20px;'>Este código expirará en 15 minutos.</p>
            </div>
        </body>
        </html>
        ";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Biblioteca SEMSAD <no-reply@semsad.edu.bo>\r\n";

        @mail($correo, $asunto, $mensajeHtml, $headers);

        echo json_encode([
            'status'  => 'success',
            'mensaje' => 'Código enviado a ' . $correo . '. Revisa tu bandeja de entrada o Spam.'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de servidor: ' . $e->getMessage()]);
    }
}
?>
