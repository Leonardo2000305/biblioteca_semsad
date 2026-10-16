<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id_multa']) || !isset($data['id_usuario'])) {
    echo json_encode([
        "status" => "error",
        "mensaje" => "Parámetros insuficientes para procesar el pago."
    ]);
    exit();
}

$id_multa = intval($data['id_multa']);
$id_usuario = intval($data['id_usuario']);

try {
    if (file_exists(__DIR__ . '/../db.php')) {
        require_once __DIR__ . '/../db.php';
    } elseif (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    } else {
        throw new Exception("No se encontró el archivo de conexión db.php");
    }

    $colsStmt = $pdo->query("SHOW COLUMNS FROM Multa");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    $colEstado = in_array('estado_pago', $cols) ? 'estado_pago' : 'estado_multa';
    $valPagado = ($colEstado === 'estado_pago') ? 'Pagado' : 'Pagada';
    $valPendiente = ($colEstado === 'estado_pago') ? 'Pendiente' : 'Pendiente';

    $pdo->beginTransaction();

    // 1. Marcar la multa como pagada
    $stmt1 = $pdo->prepare("UPDATE Multa SET {$colEstado} = :pagado WHERE id_multa = :id_multa");
    $stmt1->execute([':pagado' => $valPagado, ':id_multa' => $id_multa]);

    // 2. Verificar si al usuario le quedan otras multas
    if (in_array('id_prestamo', $cols)) {
        $sqlCheck = "SELECT COUNT(*) AS pendientes FROM Multa m INNER JOIN Prestamo p ON m.id_prestamo = p.id_prestamo WHERE p.id_usuario = :id_usuario AND m.{$colEstado} = :pendiente";
    } else {
        $sqlCheck = "SELECT COUNT(*) AS pendientes FROM Multa m INNER JOIN Detalle_Prestamo dp ON m.id_detalle = dp.id_detalle INNER JOIN Prestamo p ON dp.id_prestamo = p.id_prestamo WHERE p.id_usuario = :id_usuario AND m.{$colEstado} = :pendiente";
    }

    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([':id_usuario' => $id_usuario, ':pendiente' => $valPendiente]);
    $pendientes = $stmtCheck->fetch(PDO::FETCH_ASSOC)['pendientes'];

    // 3. Reactivar usuario si ya no tiene deudas
    if ($pendientes == 0) {
        $stmtUser = $pdo->prepare("UPDATE Usuario SET estado = 'Activo' WHERE id_usuario = :id_usuario");
        $stmtUser->execute([':id_usuario' => $id_usuario]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "mensaje" => "Pago registrado correctamente. " . ($pendientes == 0 ? "Usuario reactivado a estado Activo." : "Aún registra otras multas pendientes.")
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        "status" => "error",
        "mensaje" => "Error al procesar el pago: " . $e->getMessage()
    ]);
}
?>
