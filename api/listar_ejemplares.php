<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

$id_material = (int)($_GET['id_material'] ?? 0);
if ($id_material <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de material no válido.'], 400);

try {
    $columnsReserva = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasIdEjemplar = in_array('id_ejemplar', $columnsReserva, true);
    $sql = "
        SELECT e.id_ejemplar, e.id_material, e.estado_ejemplar, e.condicion_fisica,
               u.sala, u.estante, u.nivel,
               EXISTS (
                   SELECT 1 FROM Detalle_Prestamo dp
                   INNER JOIN Prestamo p ON p.id_prestamo = dp.id_prestamo
                   WHERE dp.id_ejemplar = e.id_ejemplar AND p.estado IN ('Activo', 'Con Mora')
               ) AS prestamo_activo
    ";
    if ($hasIdEjemplar) {
        $sql .= ",
            EXISTS (
                SELECT 1 FROM Reserva r
                WHERE r.id_ejemplar = e.id_ejemplar AND r.estado = 'Pendiente'
            ) AS reserva_asignada
        ";
    } else {
        $sql .= ",
            EXISTS (
                SELECT 1 FROM Reserva r
                WHERE r.id_material = e.id_material AND r.estado = 'Pendiente'
            ) AS reserva_asignada
        ";
    }
    $sql .= "
        FROM Ejemplar e
        INNER JOIN UbicacionFisica u ON u.id_ubicacion = e.id_ubicacion
        WHERE e.id_material = :m
        ORDER BY e.id_ejemplar ASC
    ";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':m' => $id_material]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $state = (string)$row['estado_ejemplar'];
        $busy = (int)$row['prestamo_activo'] === 1 || (int)$row['reserva_asignada'] === 1
            || in_array($state, ['Prestado', 'Reservado'], true);
        $items[] = [
            'id_ejemplar' => (int)$row['id_ejemplar'],
            'id_material' => (int)$row['id_material'],
            'estado_ejemplar' => $state,
            'condicion_fisica' => (string)($row['condicion_fisica'] ?? ''),
            'ubicacion' => trim(($row['sala'] ?? '') . ' · ' . ($row['estante'] ?? '') . ' · ' . ($row['nivel'] ?? ''), ' ·'),
            'prestamo_activo' => (int)$row['prestamo_activo'] === 1,
            'reserva_asignada' => (int)$row['reserva_asignada'] === 1,
            'puede_cambiar' => !$busy
        ];
    }
    semsad_responder(['status' => 'success', 'ejemplares' => $items]);
} catch (Throwable $e) {
    error_log('[SEMSAD] listar_ejemplares: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron listar los ejemplares.'], 500);
}
?>