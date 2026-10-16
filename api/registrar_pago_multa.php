<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once '../db.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id_multa']) || !isset($data['id_usuario'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Parámetros insuficientes para procesar el pago."
    ]);
    exit();
}

$id_multa = intval($data['id_multa']);
$id_usuario = intval($data['id_usuario']);

try {
    $pdo->beginTransaction();

    // 1. Marcar multa como Pagado
    $stmt1 = $pdo->prepare("UPDATE Multa SET estado_pago = 'Pagado' WHERE id_multa = :id_multa");
    $stmt1->execute([':id_multa' => $id_multa]);

    // 2. Verificar si el usuario aún debe otras multas
    $stmt2 = $pdo->prepare("SELECT COUNT(*) AS pendientes 
                            FROM Multa m 
                            INNER JOIN Prestamo p ON m.id_prestamo = p.id_prestamo 
                            WHERE p.id_usuario = :id_usuario AND m.estado_pago = 'Pendiente'");
    $stmt2->execute([':id_usuario' => $id_usuario]);
    $pendientes = $stmt2->fetch(PDO::FETCH_ASSOC)['pendientes'];

    // 3. Si no tiene más multas, reactivar a estado 'Activo'
    if ($pendientes == 0) {
        $stmt3 = $pdo->prepare("UPDATE Usuario SET estado = 'Activo' WHERE id_usuario = :id_usuario");
        $stmt3->execute([':id_usuario' => $id_usuario]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "mensaje" => "Pago registrado exitosamente. " . ($pendientes == 0 ? "Usuario reactivado a estado Activo." : "Aún cuenta con multas pendientes.")
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => "Error al registrar el pago: " . $e->getMessage()
    ]);
}
?>
