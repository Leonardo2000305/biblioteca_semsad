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

    if (!$input) {
        $input = $_POST;
    }

    $correo     = trim($input['correo'] ?? $input['email'] ?? $input['usuario'] ?? '');
    $contrasena = trim($input['contrasena'] ?? $input['password'] ?? $input['clave'] ?? $input['codigo_empleado'] ?? '');

    if (empty($correo) || empty($contrasena)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese su correo y contraseña.']);
        exit;
    }

    try {
        $stmt = $conexion->prepare("SELECT * FROM usuario WHERE LOWER(correo) = LOWER(:correo)");
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo ingresado no existe en la base de datos.']);
            exit;
        }

        if (isset($usuario['estado']) && $usuario['estado'] !== 'Activo') {
            echo json_encode(['status' => 'error', 'mensaje' => 'Su cuenta se encuentra inactiva.']);
            exit;
        }

        $dbPass   = $usuario['contrasena'] ?? '';
        $dbCodigo = $usuario['codigo_empleado'] ?? '';

        $esValido = false;
        if ($contrasena === $dbPass || $contrasena === $dbCodigo) {
            $esValido = true;
        } else if (!empty($dbPass) && password_verify($contrasena, $dbPass)) {
            $esValido = true;
        }

        if ($esValido) {
            $_SESSION['id_usuario'] = $usuario['id_usuario'];
            $_SESSION['nombres']    = $usuario['nombres'];
            $_SESSION['correo']     = $usuario['correo'];
            $_SESSION['id_rol']     = $usuario['id_rol'];

            $redirect = ($usuario['id_rol'] == 1 || $usuario['id_rol'] == 2) ? '../principal/admin.html' : '../principal/index.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Inicio de sesión exitoso!',
                'redirect' => $redirect
            ]);
        } else {
            echo json_encode(['status' => 'error', 'mensaje' => 'La contraseña ingresada es incorrecta.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión: ' . $e->getMessage()]);
    }
}
?>
