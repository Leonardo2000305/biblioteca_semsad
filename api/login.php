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
    // 1. Obtener datos ya sea JSON o Form POST tradicional
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) {
        $input = $_POST;
    }

    // 2. Extraer correo soportando múltiples nombres de campo
    $correo = '';
    if (!empty($input['correo'])) $correo = $input['correo'];
    elseif (!empty($input['email'])) $correo = $input['email'];
    elseif (!empty($input['usuario'])) $correo = $input['usuario'];
    $correo = trim(strtolower($correo));

    // 3. Extraer contraseña soportando múltiples nombres de campo
    $contrasena = '';
    if (!empty($input['contrasena'])) $contrasena = $input['contrasena'];
    elseif (!empty($input['password'])) $contrasena = $input['password'];
    elseif (!empty($input['clave'])) $contrasena = $input['clave'];

    if (empty($correo) || empty($contrasena)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese su correo y contraseña.']);
        exit;
    }

    try {
        // Consultar el usuario en la base de datos
        $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, contrasena, id_rol, estado, codigo_empleado FROM usuario WHERE correo = :correo");
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Correo o contraseña incorrectos.']);
            exit;
        }

        if ($usuario['estado'] !== 'Activo') {
            echo json_encode(['status' => 'error', 'mensaje' => 'Su cuenta se encuentra inactiva. Contacte al administrador.']);
            exit;
        }

        $esValido = false;

        // A. Verificación de hash BCRYPT
        if (password_verify($contrasena, $usuario['contrasena'])) {
            $esValido = true;
        } 
        // B. Compatibilidad con cuentas antiguas (Texto plano o código de empleado)
        else if ($contrasena === $usuario['contrasena'] || $contrasena === $usuario['codigo_empleado']) {
            $esValido = true;

            // Auto-encriptar a BCRYPT para actualizar la cuenta automáticamente
            $nuevoHash = password_hash($contrasena, PASSWORD_BCRYPT);
            $updateStmt = $conexion->prepare("UPDATE usuario SET contrasena = :hash WHERE id_usuario = :id");
            $updateStmt->execute([':hash' => $nuevoHash, ':id' => $usuario['id_usuario']]);
        }

        if ($esValido) {
            $_SESSION['id_usuario'] = $usuario['id_usuario'];
            $_SESSION['nombres']    = $usuario['nombres'];
            $_SESSION['correo']     = $usuario['correo'];
            $_SESSION['id_rol']     = $usuario['id_rol'];

            // Redirección según rol (1 o 2 = Admin, otros = Estudiante)
            $redirect = ($usuario['id_rol'] == 1 || $usuario['id_rol'] == 2) ? '../principal/admin.html' : '../principal/index.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Inicio de sesión exitoso!',
                'redirect' => $redirect
            ]);
        } else {
            echo json_encode(['status' => 'error', 'mensaje' => 'Correo o contraseña incorrectos.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error en el servidor: ' . $e->getMessage()]);
    }
}
?>
