<?php
require_once 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);

$correo = trim($data['correo'] ?? '');
$codigo_empleado = trim($data['codigo_empleado'] ?? '');

if (empty($correo) || empty($codigo_empleado)) {
    echo json_encode(["status" => "error", "mensaje" => "Todos los campos son obligatorios."]);
    exit;
}

// Generar OTP simulado de 6 dígitos
$otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// En un entorno real se envía por mail() o PHPMailer
echo json_encode([
    "status" => "success",
    "mensaje" => "Código de verificación enviado. (Código demo: $otp)",
    "otp_demo" => $otp
]);
?>
