<?php
error_reporting(0);
ini_set('display_errors', 0);

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
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once 'conexion.php';
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Error de conexión a la base de datos.']);
    exit;
}

// Inicializar contador de intentos en la sesión si no existe
if (!isset($_SESSION['intentos_login'])) {
    $_SESSION['intentos_login'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si ya alcanzó o superó los 5 intentos
    if ($_SESSION['intentos_login'] >= 5) {
        echo json_encode([
            'status'  => 'error',
            'mensaje' => 'Has alcanzado el límite de 5 intentos fallidos. Por favor, recupera tu contraseña o intenta más tarde.'
        ]);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $correo     = trim($input['correo'] ?? $input['email'] ?? $input['usuario'] ?? '');
    $contrasena = trim($input['contrasena'] ?? $input['password'] ?? $input['clave'] ?? $input['codigo_empleado'] ?? '');

    if (empty($correo) || empty($contrasena)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Por favor, ingrese su correo y contraseña.']);
        exit;
    }

    try {
        $stmt = $conexion->prepare("SELECT * FROM usuario WHERE LOWER(correo) = LOWER(:correo)");
        $stmt->execute([':correo' => $correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        $esValido = false;

        if ($usuario && isset($usuario['estado']) && $usuario['estado'] === 'Activo') {
            $dbPass   = $usuario['contrasena'] ?? '';
            $dbCodigo = $usuario['codigo_empleado'] ?? '';

            if ($contrasena === $dbPass || $contrasena === $dbCodigo) {
                $esValido = true;
            } else if (!empty($dbPass) && password_verify($contrasena, $dbPass)) {
                $esValido = true;
            }
        }

        if ($esValido) {
            // ÉXITO: Reiniciar el contador de intentos
            $_SESSION['intentos_login'] = 0;
            $_SESSION['id_usuario']     = $usuario['id_usuario'];
            $_SESSION['nombres']        = $usuario['nombres'];
            $_SESSION['correo']         = $usuario['correo'];
            $_SESSION['id_rol']         = $usuario['id_rol'];

            $redirect = ($usuario['id_rol'] == 1 || $usuario['id_rol'] == 2) ? '../principal/admin.html' : '../principal/index.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Inicio de sesión exitoso!',
                'redirect' => $redirect
            ]);
        } else {
            // ERROR: Incrementar contador de fallos
            $_SESSION['intentos_login']++;
            $intentosRestantes = 5 - $_SESSION['intentos_login'];

            if ($intentosRestantes > 0) {
                $textoIntentos = ($intentosRestantes === 1) ? '1 intento más.' : "$intentosRestantes intentos más.";
                $mensajeError  = "El usuario o la contraseña es incorrecta. Tienes $textoIntentos";
            } else {
                $mensajeError = "Has alcanzado el límite de 5 intentos fallidos. Por favor, recupera tu contraseña.";
            }

            echo json_encode([
                'status'  => 'error',
                'mensaje' => $mensajeError
            ]);
        }

    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error interno al procesar el ingreso.']);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
}
?>
