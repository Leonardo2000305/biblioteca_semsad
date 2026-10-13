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

    $nombres   = isset($input['nombres']) ? trim($input['nombres']) : '';
    $apellidos = isset($input['apellidos']) ? trim($input['apellidos']) : '';
    $correo    = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $codigo    = isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '';
    $telefono  = isset($input['telefono']) ? trim($input['telefono']) : '';

    // 1. Validar campos obligatorios
    if (empty($nombres) || empty($apellidos) || empty($correo) || empty($codigo) || empty($telefono)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Todos los campos son obligatorios.']);
        exit;
    }

    // 2. Validar dominio de correo institucional (@est.semsad.edu.bo o @semsad.edu.bo)
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || !preg_match('/@([a-z0-9-]+\.)?semsad\.edu\.bo$/i', $correo)) {
        echo json_encode([
            'status' => 'error', 
            'mensaje' => 'Registro denegado. Solo se permiten correos institucionales (@est.semsad.edu.bo o @semsad.edu.bo).'
        ]);
        exit;
    }

    // 3. Validar formato del teléfono (7 a 10 dígitos)
    if (!preg_match('/^[0-9]{7,10}$/', $telefono)) {
        echo json_encode([
            'status' => 'error', 
            'mensaje' => 'Ingrese un número de teléfono / celular válido (mínimo 7 dígitos).'
        ]);
        exit;
    }

    try {
        // 4. REGLAS DE SEGURIDAD ESTRICTAS (TABLAS EN MINÚSCULA)

        // A) Validar si el Correo ya existe
        $stmtCorreo = $conexion->prepare("SELECT id_usuario FROM usuario WHERE correo = :correo");
        $stmtCorreo->execute([':correo' => $correo]);
        if ($stmtCorreo->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo electrónico ya está registrado en otra cuenta.']);
            exit;
        }

        // B) Validar si la CI / Código ya existe
        $stmtCodigo = $conexion->prepare("SELECT id_usuario FROM usuario WHERE codigo_empleado = :codigo");
        $stmtCodigo->execute([':codigo' => $codigo]);
        if ($stmtCodigo->fetch()) {
            echo json_encode(['status' => 'error', 'mensaje' => 'La CI / Código de estudiante ya está registrada en otra cuenta.']);
            exit;
        }

        // C) CANDADO ANTI-CLONACIÓN: Validar si el número de teléfono ya pertenece a otra cuenta
        $stmtTel = $conexion->prepare("SELECT id_usuario FROM usuario WHERE telefono = :telefono");
        $stmtTel->execute([':telefono' => $telefono]);
        if ($stmtTel->fetch()) {
            echo json_encode([
                'status' => 'error', 
                'mensaje' => '¡Registro bloqueado! El número de teléfono ingresado ya está asociado a otra cuenta.'
            ]);
            exit;
        }

        // 5. Insertar el nuevo estudiante
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

        echo json_encode([
            'status'  => 'success',
            'mensaje' => '¡Cuenta creada correctamente! Redirigiendo al login...'
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error en base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
}
?>
