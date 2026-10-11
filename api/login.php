<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $correo = isset($_POST['correo']) ? trim($_POST['correo']) : (isset($input['correo']) ? trim($input['correo']) : '');
    $codigo_empleado = isset($_POST['codigo_empleado']) ? trim($_POST['codigo_empleado']) : (isset($input['codigo_empleado']) ? trim($input['codigo_empleado']) : '');

    if (empty($correo) || empty($codigo_empleado)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Por favor, ingrese el correo y el código de empleado.']);
        exit;
    }

    try {
        $sql = "SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.codigo_empleado, u.estado, r.nombre AS rol 
                FROM Usuario u 
                INNER JOIN Rol r ON u.id_rol = r.id_rol 
                WHERE u.correo = :correo AND u.codigo_empleado = :codigo_empleado";
        
        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            ':correo' => $correo,
            ':codigo_empleado' => $codigo_empleado
        ]);
        
        $usuario = $stmt->fetch();

        if ($usuario) {
            if ($usuario['estado'] !== 'Activo') {
                echo json_encode(['status' => 'error', 'mensaje' => 'Usuario inactivo, suspendido o bloqueado por mora.']);
                exit;
            }

            $_SESSION['id_usuario'] = $usuario['id_usuario'];
            $_SESSION['nombre'] = $usuario['nombres'] . ' ' . $usuario['apellidos'];
            $_SESSION['rol'] = $usuario['rol'];
            $_SESSION['ci'] = $usuario['codigo_empleado']; // Añadido para el frontend

            echo json_encode([
                'status' => 'success',
                'mensaje' => 'Inicio de sesión exitoso.',
                'usuario' => [
                    'id_usuario' => $usuario['id_usuario'],
                    'nombre' => $_SESSION['nombre'],
                    'correo' => $usuario['correo'],
                    'rol' => $usuario['rol'],
                    'ci' => $_SESSION['ci']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'mensaje' => 'Credenciales incorrectas.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error en el servidor: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
}
?>
