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

    if (strlen($contrasena) < 6) {
        echo json_encode(['status' => 'error', 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.']);
        exit;
    }

    try {
        // 1. Validar el código OTP
        $stmtOtp = $conexion->prepare("SELECT id FROM codigo_verificacion WHERE correo = :correo AND codigo = :codigo AND expiracion >= NOW()");
        $stmtOtp->execute([':correo' => $correo, ':codigo' => $otp]);
        if (!$stmtOtp->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El código de verificación es incorrecto o expiró.']);
            exit;
        }

        // 2. ENCRIPTACIÓN SEGURA DE CONTRASEÑA CON BCRYPT
        $hash_contrasena = password_hash($contrasena, PASSWORD_BCRYPT);

        // 3. Insertar nuevo usuario con contraseña encriptada
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

        // 4. Borrar código OTP consumido
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
