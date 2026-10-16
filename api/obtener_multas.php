<?php
ob_start();
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

    if (!isset($pdo)) {
        throw new Exception("La variable $pdo no está inicializada.");
    }

    ob_clean();

    // 1. Identificar nombre de la tabla Multa
    $tablaMulta = 'Multa';
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if (strtolower($t) === 'multa') { $tablaMulta = $t; break; }
    }

    // 2. Identificar columnas de la tabla Multa
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `$tablaMulta`");
    $colsMulta = array_map('strtolower', $colsStmt->fetchAll(PDO::FETCH_COLUMN));

    $colMonto = in_array('monto', $colsMulta) ? 'm.monto' : '0.00';
    $colEstadoMulta = in_array('estado_pago', $colsMulta) ? 'm.estado_pago' : (in_array('estado_multa', $colsMulta) ? 'm.estado_multa' : (in_array('estado', $colsMulta) ? 'm.estado' : "'Pendiente'"));
    $colTipoMulta = in_array('tipo_multa', $colsMulta) ? 'm.tipo_multa' : "'Por Mora'";

    // 3. Generar la consulta según las llaves foráneas disponibles
    if (in_array('id_prestamo', $colsMulta)) {
        $sql = "SELECT 
                    m.id_multa,
                    {$colMonto} AS monto,
                    {$colEstadoMulta} AS estado_pago,
                    {$colTipoMulta} AS tipo_multa,
                    u.id_usuario,
                    COALESCE(u.nombres, u.nombre, '') AS nombres,
                    COALESCE(u.apellidos, u.apellido, '') AS apellidos,
                    COALESCE(u.correo, u.email, '') AS correo,
                    COALESCE(u.codigo_empleado, u.ci, '') AS codigo_empleado,
                    COALESCE(u.estado, 'Activo') AS estado_usuario,
                    COALESCE(p.fecha_limite, p.fecha_devolucion_esperada, '') AS fecha_limite
                FROM `$tablaMulta` m
                INNER JOIN Prestamo p ON m.id_prestamo = p.id_prestamo
                INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
                WHERE {$colEstadoMulta} = 'Pendiente' OR {$colEstadoMulta} = 'PENDIENTE'
                ORDER BY m.id_multa DESC";
    } elseif (in_array('id_detalle', $colsMulta)) {
        $sql = "SELECT 
                    m.id_multa,
                    {$colMonto} AS monto,
                    {$colEstadoMulta} AS estado_pago,
                    {$colTipoMulta} AS tipo_multa,
                    u.id_usuario,
                    COALESCE(u.nombres, u.nombre, '') AS nombres,
                    COALESCE(u.apellidos, u.apellido, '') AS apellidos,
                    COALESCE(u.correo, u.email, '') AS correo,
                    COALESCE(u.codigo_empleado, u.ci, '') AS codigo_empleado,
                    COALESCE(u.estado, 'Activo') AS estado_usuario,
                    COALESCE(p.fecha_limite, p.fecha_devolucion_esperada, '') AS fecha_limite
                FROM `$tablaMulta` m
                INNER JOIN Detalle_Prestamo dp ON m.id_detalle = dp.id_detalle
                INNER JOIN Prestamo p ON dp.id_prestamo = p.id_prestamo
                INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
                WHERE {$colEstadoMulta} = 'Pendiente' OR {$colEstadoMulta} = 'PENDIENTE'
                ORDER BY m.id_multa DESC";
    } else {
        $sql = "SELECT 
                    m.id_multa,
                    {$colMonto} AS monto,
                    {$colEstadoMulta} AS estado_pago,
                    {$colTipoMulta} AS tipo_multa,
                    u.id_usuario,
                    COALESCE(u.nombres, u.nombre, '') AS nombres,
                    COALESCE(u.apellidos, u.apellido, '') AS apellidos,
                    COALESCE(u.correo, u.email, '') AS correo,
                    COALESCE(u.codigo_empleado, u.ci, '') AS codigo_empleado,
                    COALESCE(u.estado, 'Activo') AS estado_usuario,
                    '' AS fecha_limite
                FROM `$tablaMulta` m
                INNER JOIN Usuario u ON m.id_usuario = u.id_usuario
                WHERE {$colEstadoMulta} = 'Pendiente' OR {$colEstadoMulta} = 'PENDIENTE'
                ORDER BY m.id_multa DESC";
    }

    $stmt =$pdo->prepare($sql);$stmt->execute();
    $multas =$stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $multas
    ]);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode([
        "status" => "error",
        "message" => "Error PHP/BD: " . $e->getMessage()
    ]);
}
?>
