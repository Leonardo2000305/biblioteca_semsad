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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id_prestamo = (int)($_POST['id_prestamo'] ?? $input['id_prestamo'] ?? 0);

if ($id_prestamo <= 0) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Identificador de préstamo no válido.']);
    exit;
}

try {
    $conexion->beginTransaction();

    // 1. Obtener ejemplares asociados
    $stmtEj = $conexion->prepare("SELECT id_ejemplar FROM Detalle_Prestamo WHERE id_prestamo = :p");
    $stmtEj->execute([':p' => $id_prestamo]);
    $ejemplares = $stmtEj->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ejemplares)) {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'No se encontró el detalle de ejemplar para este préstamo.']);
        exit;
    }

    // 2. Marcar préstamo como Devuelto
    $fechaAhora = date('Y-m-d H:i:s');
    $stmtP = $conexion->prepare("UPDATE Prestamo SET estado = 'Devuelto', fecha_devolucion_real = :f WHERE id_prestamo = :p");
    $stmtP->execute([':f' => $fechaAhora, ':p' => $id_prestamo]);

    // 3. Liberar ejemplar en el inventario (Volver estado a Disponible)
    $inQuery = implode(',', array_map('intval', $ejemplares));
    $conexion->exec("UPDATE Ejemplar SET estado_ejemplar = 'Disponible' WHERE id_ejemplar IN ($inQuery)");

    $conexion->commit();

    echo json_encode([
        'status'  => 'success',
        'mensaje' => 'Devolución registrada con éxito. El libro vuelve a estar disponible para préstamos.'
    ]);

} catch (PDOException $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    echo json_encode(['status' => 'error', 'mensaje' => 'Error al registrar devolución: ' . $e->getMessage()]);
}
?>
