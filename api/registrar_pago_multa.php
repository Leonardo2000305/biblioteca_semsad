<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once '../db.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id_multa']) || !isset($data['id_usuario'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Datos incompletos para registrar el pago."
    ]);
    exit();
}

$id_multa = intval($data['id_multa']);
$id_usuario = intval($data['id_usuario']);

try {
    $pdo->beginTransaction();

    // 1. Cambiar estado de la multa a 'Pagado'
    $stmtMulta = $pdo->prepare("UPDATE Multa SET estado_pago = 'Pagado' WHERE id_multa = :id_multa");
    $stmtMulta->execute([':id_multa' => $id_multa]);

    // 2. Comprobar si el usuario tiene otras multas pendientes
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) AS pendientes FROM Multa m 
                                INNER JOIN Prestamo p ON m.id_prestamo = p.id_prestamo 
                                WHERE p.id_usuario = :id_usuario AND m.estado_pago = 'Pendiente'");
    $stmtCheck->execute([':id_usuario' => $id_usuario]);
    $pendientes = $stmtCheck->fetch(PDO::FETCH_ASSOC)['pendientes'];

    // 3. Si no adeuda más multas, reactivar cuenta a 'Activo'
    if ($pendientes == 0) {
        $stmtUser = $pdo->prepare("UPDATE Usuario SET estado = 'Activo' WHERE id_usuario = :id_usuario");
        $stmtUser->execute([':id_usuario' => $id_usuario]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Pago registrado correctamente. " . ($pendientes == 0 ? "Usuario reactivado a estado Activo." : "Aún registra otras multas pendientes.")
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        "status" => "error",
        "message" => "Error al procesar el pago: " . $e->getMessage()
    ]);
}
?>
