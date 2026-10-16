<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

require_once '../db.php';

try {
    $sql = "SELECT 
                m.id_multa,
                m.id_prestamo,
                m.monto,
                m.estado_pago,
                COALESCE(m.tipo_multa, 'Por Mora') AS tipo_multa,
                u.id_usuario,
                u.nombres,
                u.apellidos,
                u.correo,
                u.codigo_empleado,
                u.estado AS estado_usuario,
                p.fecha_limite
            FROM Multa m
            INNER JOIN Prestamo p ON m.id_prestamo = p.id_prestamo
            INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
            WHERE m.estado_pago = 'Pendiente'
            ORDER BY m.id_multa DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $multas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $multas
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error al consultar multas: " . $e->getMessage()
    ]);
}
?>
