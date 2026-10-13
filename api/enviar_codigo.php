<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', 'True');
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

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $correo     = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $codigo_emp = isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';
    $telefono   = isset($input['telefono']) ? trim($input['telefono']) : '';

    if (empty($correo) || empty($codigo_emp) || empty($telefono)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Complete su Correo, CI y Teléfono antes de pedir el código.']);
        exit;
    }

    // 1. Dominio institucional obligatorio
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || !preg_match('/@([a-z0-9-]+\.)?semsad\.edu\.bo$/i', $correo)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Solo se permiten correos institucionales (@est.semsad.edu.bo).']);
        exit;
    }

    // 2. Formato de teléfono
    if (!preg_match('/^[0-9]{7,10}$/', $telefono)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese un teléfono válido (7 a 10 dígitos).']);
        exit;
    }

    try {
        // 3. Verificación estricta de duplicados
        $checkCorreo = $conexion->prepare("SELECT id_usuario FROM usuario WHERE correo = :correo");
        $checkCorreo->execute([':correo' => $correo]);
        if ($checkCorreo->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo electrónico ya está registrado en el sistema.']);
            exit;
        }

        $checkCodigo = $conexion->prepare("SELECT id_usuario FROM usuario WHERE codigo_empleado = :codigo");
        $checkCodigo->execute([':codigo' => $codigo_emp]);
        if ($checkCodigo->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'La CI / Código de estudiante ya está registrada.']);
            exit;
        }

        $checkTel = $conexion->prepare("SELECT id_usuario FROM usuario WHERE telefono = :tel");
        $checkTel->execute([':tel' => $telefono]);
        if ($checkTel->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => '¡Registro bloqueado! Este número de teléfono ya pertenece a otra cuenta.']);
            exit;
        }

        // Generar OTP de 6 dígitos con caducidad de 5 minutos
        $codigo_otp = sprintf("%06d", mt_rand(100000, 999999));
        $expiracion = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        // Limpiar antiguos e insertar nuevo código OTP
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE correo = :correo");
        $stmtDel->execute([':correo' => $correo]);

        $stmtIns = $conexion->prepare("INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:correo, :codigo, :expiracion)");
        $stmtIns->execute([':correo' => $correo, ':codigo' => $codigo_otp, ':expiracion' => $expiracion]);

        // Envío de correo electrónico
        $asunto = "Codigo de Verificacion - Biblioteca SEMSAD";
        $mensaje = "Hola,\n\nTu codigo de verificacion para registrarte en la Biblioteca SEMSAD es: $codigo_otp\n\nEste codigo caduca en 5 minutos.";
        $headers = "From: no-reply@semsad.edu.bo\r\n" .
                   "Reply-To: soporte@semsad.edu.bo\r\n" .
                   "X-Mailer: PHP/" . phpversion();

        @mail($correo, $asunto, $mensaje, $headers);

        echo json_encode([
            'status'  => 'success',
            'mensaje' => 'Código de verificación enviado a ' . $correo . '. Revisa tu correo o SPAM.'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error en base de datos: ' . $e->getMessage()]);
    }
}
?>
