<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

try {
    $conexion->beginTransaction();

    // Cada préstamo genera una sola multa. Los importes se calculan a partir de la adquisición
    // cuando tiene costo; se conserva el cálculo existente de Bs 5/día si no hay precio registrado.
    $sql = "
        SELECT p.id_prestamo, p.id_usuario, p.fecha_limite,
               dp.id_detalle, e.id_ejemplar, e.id_adquisicion,
               COALESCE(a.costo_total, 0) AS costo_total,
               (SELECT COUNT(*)
                FROM Ejemplar e2
                WHERE e2.id_adquisicion = e.id_adquisicion
                  AND e2.estado_ejemplar <> 'Baja') AS copias_adquisicion
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        LEFT JOIN Adquisicion a ON a.id_adquisicion = e.id_adquisicion
        WHERE p.estado = 'Activo'
          AND p.fecha_limite < NOW()
          AND NOT EXISTS (
              SELECT 1 FROM Multa m WHERE m.id_prestamo = p.id_prestamo
          )
        ORDER BY p.fecha_limite ASC
        FOR UPDATE
    ";
    $rows = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $insert = $conexion->prepare("
        INSERT INTO Multa (id_prestamo, id_detalle, monto, estado_pago, tipo_multa)
        VALUES (:p, :d, :m, 'Pendiente', 'Por Mora')
    ");
    $updateLoan = $conexion->prepare("UPDATE Prestamo SET estado = 'Con Mora' WHERE id_prestamo = :p AND estado = 'Activo'");
    $suspendUser = $conexion->prepare("UPDATE Usuario SET estado = 'Suspendido' WHERE id_usuario = :u AND estado = 'Activo'");

    $creadas = 0;
    $totalGenerado = 0.0;
    $usuariosSuspendidos = [];

    foreach ($rows as $row) {
        $limite = strtotime((string)$row['fecha_limite']);
        $dias = max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $limite))) / 86400));
        $costoTotal = (float)($row['costo_total'] ?? 0);
        $copias = (int)($row['copias_adquisicion'] ?? 0);
        $costoUnitario = ($costoTotal > 0 && $copias > 0) ? $costoTotal / $copias : 0;
        $monto = $costoUnitario > 0
            ? min(200, $costoUnitario * 0.05 * $dias)
            : min(200, 5 * $dias);
        $monto = round(max(0.01, $monto), 2);

        $insert->execute([
            ':p' => (int)$row['id_prestamo'],
            ':d' => (int)$row['id_detalle'],
            ':m' => $monto
        ]);
        $updateLoan->execute([':p' => (int)$row['id_prestamo']]);
        $creadas++;
        $totalGenerado += $monto;

        if ($dias >= 15) {
            $suspendUser->execute([':u' => (int)$row['id_usuario']]);
            $usuariosSuspendidos[(int)$row['id_usuario']] = true;
        }
    }

    $conexion->commit();
    semsad_responder([
        'status' => 'success',
        'mensaje' => $creadas > 0
            ? "Se calcularon $creadas multa(s) pendiente(s) por un total de Bs " . number_format($totalGenerado, 2, '.', '') . "."
            : 'No hay nuevos préstamos vencidos por procesar.',
        'multas_creadas' => $creadas,
        'monto_total' => round($totalGenerado, 2),
        'usuarios_suspendidos' => count($usuariosSuspendidos)
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] actualizar_moras: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron calcular las multas. No se confirmó ningún cambio.'], 500);
}
?>