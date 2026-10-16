<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id_multa']) \vert{}\vert{} !isset($data['id_usuario'])) {
    echo json_encode([
        "status" => "error",
        "mensaje" => "Faltan datos obligatorios (id_multa, id_usuario)."
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

    ob_clean();

    $tablaMulta = 'Multa';
    $tables =$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as$t) {
        if (strtolower($t) === 'multa') { $tablaMulta =$t; break; }
    }

    $colsStmt =$pdo->query("SHOW COLUMNS FROM `$tablaMulta`");
    $colsMulta = array_map('strtolower',$colsStmt->fetchAll(PDO::FETCH_COLUMN));

    $colEstado = in_array('estado_pago',$colsMulta) ? 'estado_pago' : (in_array('estado_multa', $colsMulta) ? 'estado_multa' : 'estado');$pdo->beginTransaction();

    $stmt1 =$pdo->prepare("UPDATE `$tablaMulta` SET `{$colEstado}` = 'Pagado' WHERE id_multa = :id_multa");
    $stmt1->execute([':id_multa' =>$id_multa]);

    if (in_array('id_usuario', $colsMulta)) {
        $stmt2 =$pdo->prepare("SELECT COUNT(*) AS pendientes FROM `$tablaMulta` WHERE id_usuario = :id_usuario AND `{$colEstado}` IN ('Pendiente', 'PENDIENTE')");
        $stmt2->execute([':id_usuario' =>$id_usuario]);
    } else {
        $stmt2 =$pdo->prepare("SELECT COUNT(*) AS pendientes FROM `$tablaMulta` m INNER JOIN Prestamo p ON m.id_prestamo = p.id_prestamo WHERE p.id_usuario = :id_usuario AND m.`{$colEstado}` IN ('Pendiente', 'PENDIENTE')");
        $stmt2->execute([':id_usuario' =>$id_usuario]);
    }

    $pendientes =$stmt2->fetch(PDO::FETCH_ASSOC)['pendientes'];

    if ($pendientes == 0) {
        $stmt3 =$pdo->prepare("UPDATE Usuario SET estado = 'Activo' WHERE id_usuario = :id_usuario");
        $stmt3->execute([':id_usuario' =>$id_usuario]);
    }

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "mensaje" => "Pago de multa registrado correctamente." . ($pendientes == 0 ? " El usuario ha sido desbloqueado y reactivado." : "")
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {$pdo->rollBack();
    }
    ob_clean();
    echo json_encode([
        "status" => "error",
        "mensaje" => "Error al procesar pago: " . $e->getMessage()
    ]);
}
?>
