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

    $correo = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $clave  = isset($input['clave']) ? trim($input['clave']) : '';

    if (empty($correo) || empty($clave)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese su correo y contraseña.']);
        exit;
    }

    try {
        $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, clave, rol FROM usuario WHERE LOWER(correo) = :correo LIMIT 1");
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
            exit;
        }

        $claveValida = false;
        if (password_verify($clave, $usuario['clave'])) {
            $claveValida = true;
        } else if ($usuario['clave'] === $clave) {
            $claveValida = true;
            $nuevoHash = password_hash($clave, PASSWORD_BCRYPT);
            $stmtUpd = $conexion->prepare("UPDATE usuario SET clave = :hash WHERE id_usuario = :id");
            $stmtUpd->execute([':hash' => $nuevoHash, ':id' => $usuario['id_usuario']]);
        }

        if ($claveValida) {
            $_SESSION['id_usuario'] = $usuario['id_usuario'];
            $_SESSION['nombre_usuario'] = $usuario['nombres'] . ' ' . $usuario['apellidos'];
            $_SESSION['rol'] = $usuario['rol'];

            $redirect = ($usuario['rol'] === 'admin') ? 'admin/dashboard.html' : 'dashboard.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Inicio de sesión exitoso!',
                'redirect' => $redirect,
                'usuario'  => [
                    'nombres'   => $usuario['nombres'],
                    'apellidos' => $usuario['apellidos'],
                    'rol'       => $usuario['rol']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
}
?>
