<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

try {
    if (file_exists(__DIR__ . '/../db.php')) {
        require_once __DIR__ . '/../db.php';
    } elseif (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    } else {
        throw new Exception("No se encontró el archivo de conexión db.php");
    }

    // Verificar estructura real de la tabla Multa
    $colsStmt = $pdo->query("SHOW COLUMNS FROM Multa");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    $colEstado = in_array('estado_pago', $cols) ? 'm.estado_pago' : (in_array('estado_multa', $cols) ? 'm.estado_multa' : "'Pendiente'");
    $colTipo   = in_array('tipo_multa', $cols) ? 'm.tipo_multa' : "'Por Mora'";
    $colRel    = in_array('id_prestamo', $cols) ? 'm.id_prestamo = p.id_prestamo' : 'm.id_detalle = dp.id_detalle INNER JOIN Prestamo p ON dp.id_prestamo = p.id_prestamo';
    $joinExtra = in_array('id_prestamo', $cols) ? '' : 'INNER JOIN Detalle_Prestamo dp ON m.id_detalle = dp.id_detalle ';

    $sql = "SELECT 
                m.id_multa,
                m.monto,
                {$colEstado} AS estado_pago,
                {$colTipo} AS tipo_multa,
                u.id_usuario,
                u.nombres,
                COALESCE(u.apellidos, '') AS apellidos,
                u.correo,
                COALESCE(u.codigo_empleado, '') AS codigo_empleado,
                u.estado AS estado_usuario,
                p.fecha_limite
            FROM Multa m
            {$joinExtra}
            INNER JOIN Prestamo p ON {$colRel}
            INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
            WHERE {$colEstado} = 'Pendiente' OR {$colEstado} = 'PENDIENTE'
            ORDER BY m.id_multa DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $multas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $multas
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error en la consulta: " . $e->getMessage()
    ]);
}
?>
