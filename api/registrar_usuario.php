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

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $nombres    = isset($input['nombres']) ? trim($input['nombres']) : '';
    $apellidos  = isset($input['apellidos']) ? trim($input['apellidos']) : '';
    $correo     = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $codigo     = isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';
    $telefono   = isset($input['telefono']) ? trim($input['telefono']) : '';
    $contrasena = isset($input['contrasena']) ? $input['contrasena'] : '';
    $otp        = isset($input['codigo_otp']) ? trim($input['codigo_otp']) : '';

    if (empty($nombres) || empty($apellidos) || empty($correo) || empty($codigo) || empty($telefono) || empty($contrasena) || empty($otp)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Todos los campos y el código OTP son obligatorios.']);
        exit;
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese un correo electrónico válido.']);
        exit;
    }

    if (strlen($contrasena) < 6) {
        echo json_encode(['status' => 'error', 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.']);
        exit;
    }

    try {
        // 1. Obtener el código de verificación para este correo
        $stmtOtp = $conexion->prepare("SELECT id, expiracion FROM codigo_verificacion WHERE correo = :correo AND codigo = :codigo");
        $stmtOtp->execute([':correo' => $correo, ':codigo' => $otp]);
        $rowOtp = $stmtOtp->fetch(PDO::FETCH_ASSOC);

        if (!$rowOtp) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El código de verificación ingresado es incorrecto.']);
            exit;
        }

        // 2. Comprobar tiempo de expiración en PHP (previene errores de zona horaria en la BD)
        $tiempoExpiracion = strtotime($rowOtp['expiracion']);
        if ($tiempoExpiracion < time()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El código de verificación ha expirado. Por favor, solicita uno nuevo.']);
            exit;
        }

        // 3. Encriptar contraseña con BCRYPT
        $hash_contrasena = password_hash($contrasena, PASSWORD_BCRYPT);

        // 4. Insertar usuario en MySQL
        $sql = "INSERT INTO usuario (nombres, apellidos, correo, contrasena, id_rol, codigo_empleado, telefono, nivel_permiso, estado) 
                VALUES (:nombres, :apellidos, :correo, :contrasena, 4, :codigo, :telefono, 1, 'Activo')";
        
        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            ':nombres'    => $nombres,
            ':apellidos'  => $apellidos,
            ':correo'     => $correo,
            ':contrasena' => $hash_contrasena,
            ':codigo'     => $codigo,
            ':telefono'   => $telefono
        ]);

        // 5. Borrar código OTP consumido
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE correo = :correo");
        $stmtDel->execute([':correo' => $correo]);

        echo json_encode([
            'status'  => 'success',
            'mensaje' => '¡Cuenta creada con éxito! Redirigiendo al inicio de sesión...'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al registrar usuario: ' . $e->getMessage()]);
    }
}
?>
