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

    $nombres   = isset($input['nombres']) ? trim($input['nombres']) : '';
    $apellidos = isset($input['apellidos']) ? trim($input['apellidos']) : '';
    $correo    = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $codigo    = isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';
    $telefono  = isset($input['telefono']) ? trim($input['telefono']) : '';
    $otp       = isset($input['codigo_otp']) ? trim($input['codigo_otp']) : '';

    if (empty($nombres) || empty($apellidos) || empty($correo) || empty($codigo) || empty($telefono) || empty($otp)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Todos los campos y el código de verificación son obligatorios.']);
        exit;
    }

    try {
        // Verificar validez del código OTP
        $stmtOtp = $conexion->prepare("SELECT id FROM codigo_verificacion WHERE correo = :correo AND codigo = :codigo AND expiracion >= NOW()");
        $stmtOtp->execute([':correo' => $correo, ':codigo' => $otp]);
        if (!$stmtOtp->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El código de verificación es incorrecto o ya expiró.']);
            exit;
        }

        // Insertar estudiante en tabla usuario
        $sql = "INSERT INTO usuario (nombres, apellidos, correo, id_rol, codigo_empleado, telefono, nivel_permiso, estado) 
                VALUES (:nombres, :apellidos, :correo, 4, :codigo, :telefono, 1, 'Activo')";
        
        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            ':nombres'   => $nombres,
            ':apellidos' => $apellidos,
            ':correo'    => $correo,
            ':codigo'    => $codigo,
            ':telefono'  => $telefono
        ]);

        // Borrar el código utilizado
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
