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

    $nombres        = isset($input['nombres']) ? trim($input['nombres']) : '';
    $apellidos      = isset($input['apellidos']) ? trim($input['apellidos']) : '';
    $correo         = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $codigo_empleado= isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';
    $telefono       = isset($input['telefono']) ? trim($input['telefono']) : '';
    $clave          = isset($input['clave']) ? trim($input['clave']) : '';
    $codigo_otp     = isset($input['codigo_otp']) ? trim($input['codigo_otp']) : '';

    if (empty($nombres) || empty($apellidos) || empty($correo) || empty($codigo_empleado) || empty($clave) || empty($codigo_otp)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Todos los datos requeridos deben ser completados.']);
        exit;
    }

    try {
        // 1. Validar Código OTP
        $stmtOTP = $conexion->prepare("SELECT id FROM codigo_verificacion WHERE LOWER(correo) = :correo AND codigo = :codigo AND expiracion >= NOW() LIMIT 1");
        $stmtOTP->execute([
            ':correo' => $correo,
            ':codigo' => $codigo_otp
        ]);

        if (!$stmtOTP->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El código de verificación es incorrecto o ha expirado.']);
            exit;
        }

        // 2. Detectar dinámicamente los nombres de columnas
        $stmtCols = $conexion->query("SHOW COLUMNS FROM usuario");
        $cols = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];

        $colClave = 'clave';
        foreach (['password', 'clave', 'contrasena', 'contraseña', 'pass'] as $posible) {
            if (in_array($posible, $cols)) {
                $colClave = $posible;
                break;
            }
        }

        $colCodigo = 'codigo_empleado';
        foreach (['codigo_empleado', 'ci', 'dni', 'carnet'] as $posible) {
            if (in_array($posible, $cols)) {
                $colCodigo = $posible;
                break;
            }
        }

        // 3. Hash BCRYPT de la contraseña
        $hashClave = password_hash($clave, PASSWORD_BCRYPT);

        // 4. Inserción adaptativa
        $sql = "INSERT INTO usuario (nombres, apellidos, correo, {$colCodigo}, telefono, {$colClave}, rol) 
                VALUES (:nombres, :apellidos, :correo, :codigo, :telefono, :clave, 'estudiante')";
        
        $stmtIns = $conexion->prepare($sql);
        $stmtIns->execute([
            ':nombres'   => $nombres,
            ':apellidos' => $apellidos,
            ':correo'    => $correo,
            ':codigo'    => $codigo_empleado,
            ':telefono'  => $telefono,
            ':clave'     => $hashClave
        ]);

        // 5. Borrar el OTP utilizado
        $stmtDel = $conexion->prepare("DELETE FROM codigo_verificacion WHERE LOWER(correo) = :correo");
        $stmtDel->execute([':correo' => $correo]);

        echo json_encode([
            'status'  => 'success',
            'mensaje' => '¡Registro exitoso! Tu cuenta ha sido creada.'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de servidor: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
}
?>
