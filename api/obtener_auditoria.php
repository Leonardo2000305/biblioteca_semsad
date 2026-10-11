<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Consulta los últimos 30 intentos de recuperación ordenados del más reciente al más antiguo
    $sql = "SELECT id_log, correo_ingresado, DATE_FORMAT(fecha_hora, '%d/%m/%Y %H:%i:%s') as fecha_formateada, estado, ip_origen 
            FROM auditoria_recuperacion 
            ORDER BY fecha_hora DESC LIMIT 30";
    
    $stmt = $conexion->query($sql);
    $registros = $stmt->fetchAll();

    echo json_encode([
        'status' => 'success',
        'datos' => $registros
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al obtener auditoría: ' . $e->getMessage()]);
}
?>
