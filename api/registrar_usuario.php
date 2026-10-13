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
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $nombres = isset($input['nombres']) ? trim($input['nombres']) : '';
    $apellidos = isset($input['apellidos']) ? trim($input['apellidos']) : '';
    $correo = isset($input['correo']) ? trim($input['correo']) : '';
    $codigo = isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';

    if (empty($nombres) || empty($apellidos) || empty($correo) || empty($codigo)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Todos los campos son obligatorios.']);
        exit;
    }

    try {
        // Validar si el correo o el código ya están registrados
        $checkSql = "SELECT id_usuario FROM Usuario WHERE correo = :correo OR codigo_empleado = :codigo";
        $checkStmt = $conexion->prepare($checkSql);
        $checkStmt->execute([':correo' => $correo, ':codigo' => $codigo]);

        if ($checkStmt->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo o código/CI ya se encuentra registrado.']);
            exit;
        }

        // Registrar como Estudiante (id_rol = 4 por defecto)
        $sql = "INSERT INTO Usuario (nombres, apellidos, correo, id_rol, codigo_empleado, nivel_permiso, estado) 
                VALUES (:nombres, :apellidos, :correo, 4, :codigo, 1, 'Activo')";
        
        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            ':nombres' => $nombres,
            ':apellidos' => $apellidos,
            ':correo' => $correo,
            ':codigo' => $codigo
        ]);

        echo json_encode([
            'status' => 'success',
            'mensaje' => '¡Registro exitoso! Ya puedes iniciar sesión con tu código o correo.'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al guardar el usuario: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
}
?>
