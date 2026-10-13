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
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Consultar lista de usuarios sin campos sensibles ni contraseñas
    $sql = "SELECT id_usuario, nombres, apellidos, correo, codigo_empleado, telefono, estado, id_rol 
            FROM usuario 
            ORDER BY id_usuario DESC";
    
    $stmt = $conexion->prepare($sql);
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data'   => $usuarios
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al obtener usuarios: ' . $e->getMessage()]);
}
?>
