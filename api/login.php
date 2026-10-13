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
    $contrasena = isset($input['contrasena']) ? $input['contrasena'] : '';

    if (empty($correo) || empty($contrasena)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese su correo y contraseña.']);
        exit;
    }

    try {
        // Consultar usuario por correo
        $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, contrasena, id_rol, estado FROM usuario WHERE correo = :correo");
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

        // VERIFICACIÓN SEGURA CON BCRYPT
        if (password_verify($contrasena, $usuario['contrasena'])) {
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
            echo json_encode(['status' => 'error', 'mensaje' => 'Correo o contraseña incorrectos.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error en el servidor: ' . $e->getMessage()]);
    }
}
?>
