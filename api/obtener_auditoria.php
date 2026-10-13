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
    // Obtener los últimos 20 intentos de recuperación de contraseña
    $sql = "SELECT id, correo_ingresado, fecha_hora, estado, ip_origen 
            FROM auditoria_recuperacion 
            ORDER BY id DESC LIMIT 20";
    
    $stmt = $conexion->prepare($sql);
    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data'   => $registros
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al obtener auditoría: ' . $e->getMessage()]);
}
?>
