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
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Capturar datos desde JSON, POST o formulario
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = array_merge($_POST, $_GET, $_REQUEST);
    } else {
        $input = array_merge($input, $_POST, $_GET, $_REQUEST);
    }

    // 2. Obtener correo
    $correo = '';
    foreach (['correo', 'email', 'usuario', 'loginCorreo'] as $key) {
        if (!empty($input[$key])) {
            $correo = trim(strtolower($input[$key]));
            break;
        }
    }

    // 3. Obtener contraseña
    $contrasena = '';
    foreach (['contrasena', 'password', 'clave', 'loginContrasena'] as $key) {
        if (!empty($input[$key])) {
            $contrasena = $input[$key];
            break;
        }
    }

    if (empty($correo) || empty($contrasena)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese su correo y contraseña manualmente.']);
        exit;
    }

    try {
        // Consultar el usuario en Alwaysdata
        $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, contrasena, id_rol, estado, codigo_empleado FROM usuario WHERE correo = :correo");
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo ingresado no existe en la base de datos.']);
            exit;
        }

        if ($usuario['estado'] !== 'Activo') {
            echo json_encode(['status' => 'error', 'mensaje' => 'Esta cuenta está inactiva.']);
            exit;
        }

        $esValido = false;

        // A. Comprobar contraseña encriptada BCRYPT
        if (!empty($usuario['contrasena']) && password_verify($contrasena, $usuario['contrasena'])) {
            $esValido = true;
        } 
        // B. Comprobar contraseña previa en texto plano o Código (ej. ADM-001)
        else if ($contrasena === $usuario['contrasena'] || $contrasena === $usuario['codigo_empleado']) {
            $esValido = true;

            // Auto-encriptar la clave a BCRYPT en MySQL para mayor seguridad futura
            $nuevoHash = password_hash($contrasena, PASSWORD_BCRYPT);
            $updateStmt = $conexion->prepare("UPDATE usuario SET contrasena = :hash WHERE id_usuario = :id");
            $updateStmt->execute([':hash' => $nuevoHash, ':id' => $usuario['id_usuario']]);
        }

        if ($esValido) {
            $_SESSION['id_usuario'] = $usuario['id_usuario'];
            $_SESSION['nombres']    = $usuario['nombres'];
            $_SESSION['correo']     = $usuario['correo'];
            $_SESSION['id_rol']     = $usuario['id_rol'];

            // Redirección por Rol (1 o 2 = Administrador / Bibliotecario)
            $redirect = ($usuario['id_rol'] == 1 || $usuario['id_rol'] == 2) ? '../principal/admin.html' : '../principal/index.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Bienvenido! Redirigiendo...',
                'redirect' => $redirect
            ]);
        } else {
            echo json_encode(['status' => 'error', 'mensaje' => 'La contraseña ingresada es incorrecta.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión MySQL: ' . $e->getMessage()]);
    }
}
?>
