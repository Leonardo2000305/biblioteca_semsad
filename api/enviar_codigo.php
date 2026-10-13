<?php
error_reporting(0);
ini_set('display_errors', 0);

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

    // VALIDACIÓN UNIVERSAL DE CORREO (Acepta Gmail, Hotmail, Outlook, Yahoo, etc.)
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese una dirección de correo electrónico válida.']);
        exit;
    }

    try {
        // 1. Verificar si el correo ya está registrado en la base de datos
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

        // 3. Generar OTP de 6 dígitos
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // 4. Limpiar códigos previos e insertar el nuevo OTP
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE correo = :correo");
        $stmtDel->execute([':correo' => $correo]);

        $stmtIns = $conexion->prepare("INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:correo, :codigo, :expiracion)");
        $stmtIns->execute([
            ':correo'     => $correo,
            ':codigo'     => $otp,
            ':expiracion' => $expiracion
        ]);

        echo json_encode([
            'status'  => 'success',
            'mensaje' => 'Código de verificación generado. Su OTP es: ' . $otp
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de servidor: ' . $e->getMessage()]);
    }
}
?>
