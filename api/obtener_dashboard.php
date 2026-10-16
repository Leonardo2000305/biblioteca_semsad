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
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Total Títulos de Libros
    $stmt1 = $conexion->query("SELECT COUNT(*) FROM MaterialBibliografico");
    $total_libros = (int)$stmt1->fetchColumn();

    // Ejemplares Disponibles en inventario
    $stmt2 = $conexion->query("SELECT COUNT(*) FROM Ejemplar WHERE estado_ejemplar = 'Disponible'");
    $disponibles = (int)$stmt2->fetchColumn();

    // Préstamos Activos en Ventanilla
    $stmt3 = $conexion->query("SELECT COUNT(*) FROM Prestamo WHERE estado = 'Activo'");
    $prestamos_activos = (int)$stmt3->fetchColumn();

    echo json_encode([
        'status'            => 'success',
        'total_libros'      => $total_libros,
        'disponibles'       => $disponibles,
        'prestamos_activos' => $prestamos_activos
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status'  => 'error',
        'mensaje' => 'Error al consultar dashboard: ' . $e->getMessage()
    ]);
}
?>
