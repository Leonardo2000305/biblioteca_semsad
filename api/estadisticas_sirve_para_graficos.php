<?php
// ARCHIVO: estadisticas_sirve_para_graficos.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: dar los números de las estadísticas de Reportes (gráficos y tarjetas) para un rango de fechas.
// QUÉ HACE: GET ?desde=AAAA-MM-DD&hasta=AAAA-MM-DD. Calcula el período anterior de igual longitud para comparar.
//   Todo sale de la base de datos real. Solo personal.
// CONECTA CON: principal/admin.html (sección Estadísticas de Reportes)
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

$valida = fn($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && checkdate((int)substr($f, 5, 2), (int)substr($f, 8, 2), (int)substr($f, 0, 4));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-29 days'));
if (!$valida($desde) || !$valida($hasta) || $desde > $hasta) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Revisa las fechas: "desde" debe ser anterior o igual a "hasta".'], 400);
}
$dias = (int)((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
if ($dias > 730) semsad_responder(['status' => 'error', 'mensaje' => 'El rango máximo es de 2 años.'], 400);
$prevHasta = date('Y-m-d', strtotime($desde . ' -1 day'));
$prevDesde = date('Y-m-d', strtotime($desde . ' -' . $dias . ' days'));

try {
    // Préstamos entregados: se excluyen solicitudes pendientes y canceladas.
    $entregados = "p.estado NOT IN ('Solicitado', 'Cancelado')";

    $serie = function (string $sql, string $d1, string $d2) use ($conexion) {
        $q = $conexion->prepare($sql);
        $q->execute([':d1' => $d1, ':d2' => $d2]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    // Rellena con cero los días sin movimiento, para que el gráfico tenga todos los días.
    $rellenar = function (array $datos, string $d1, int $n) {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = date('Y-m-d', strtotime("$d1 +$i days"));
            $out[] = ['fecha' => $d, 'valor' => round((float)($datos[$d] ?? 0), 2)];
        }
        return $out;
    };

    $prestamosDia = $serie("SELECT DATE(p.fecha_salida) d, COUNT(*) FROM prestamo p WHERE $entregados AND DATE(p.fecha_salida) BETWEEN :d1 AND :d2 GROUP BY d", $desde, $hasta);
    $devolDia     = $serie("SELECT DATE(p.fecha_devolucion_real) d, COUNT(*) FROM prestamo p WHERE p.fecha_devolucion_real IS NOT NULL AND DATE(p.fecha_devolucion_real) BETWEEN :d1 AND :d2 GROUP BY d", $desde, $hasta);
    $multasDia    = $serie("SELECT DATE(m.fecha_pago) d, SUM(m.monto) FROM multa m WHERE m.estado_pago = 'Pagado' AND DATE(m.fecha_pago) BETWEEN :d1 AND :d2 GROUP BY d", $desde, $hasta);

    // Recaudación por método en el rango.
    $q = $conexion->prepare("SELECT
            COALESCE(SUM(CASE WHEN metodo_pago = 'QR' THEN monto END), 0) AS qr,
            COALESCE(SUM(CASE WHEN metodo_pago = 'Efectivo' THEN monto END), 0) AS efectivo,
            COALESCE(SUM(CASE WHEN metodo_pago IS NULL OR metodo_pago NOT IN ('QR','Efectivo') THEN monto END), 0) AS otros,
            COUNT(*) AS cobros
        FROM multa WHERE estado_pago = 'Pagado' AND DATE(fecha_pago) BETWEEN :d1 AND :d2");
    $q->execute([':d1' => $desde, ':d2' => $hasta]);
    $cob = $q->fetch(PDO::FETCH_ASSOC);

    // Multas pendientes hoy (no depende del rango).
    $pend = $conexion->query("SELECT COUNT(*) n, COALESCE(SUM(monto),0) total FROM multa WHERE estado_pago = 'Pendiente'")->fetch(PDO::FETCH_ASSOC);

    // Libros más solicitados en el rango.
    $q = $conexion->prepare("SELECT mb.titulo, COUNT(*) veces
                               FROM detalle_prestamo dp
                               INNER JOIN prestamo p ON p.id_prestamo = dp.id_prestamo
                               INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
                               INNER JOIN materialbibliografico mb ON mb.id_material = e.id_material
                              WHERE $entregados AND DATE(p.fecha_salida) BETWEEN :d1 AND :d2
                              GROUP BY mb.id_material, mb.titulo ORDER BY veces DESC LIMIT 10");
    $q->execute([':d1' => $desde, ':d2' => $hasta]);
    $top = $q->fetchAll(PDO::FETCH_ASSOC);

    // Totales de un período: préstamos, devoluciones, multas cobradas y usuarios que pidieron libros.
    $totales = function (string $d1, string $d2) use ($conexion, $entregados) {
        $a = $conexion->prepare("SELECT COUNT(*) FROM prestamo p WHERE $entregados AND DATE(p.fecha_salida) BETWEEN :d1 AND :d2");
        $a->execute([':d1' => $d1, ':d2' => $d2]);
        $b = $conexion->prepare("SELECT COUNT(*) FROM prestamo p WHERE p.fecha_devolucion_real IS NOT NULL AND DATE(p.fecha_devolucion_real) BETWEEN :d1 AND :d2");
        $b->execute([':d1' => $d1, ':d2' => $d2]);
        $c = $conexion->prepare("SELECT COALESCE(SUM(monto),0) FROM multa WHERE estado_pago = 'Pagado' AND DATE(fecha_pago) BETWEEN :d1 AND :d2");
        $c->execute([':d1' => $d1, ':d2' => $d2]);
        $d = $conexion->prepare("SELECT COUNT(DISTINCT p.id_usuario) FROM prestamo p WHERE $entregados AND DATE(p.fecha_salida) BETWEEN :d1 AND :d2");
        $d->execute([':d1' => $d1, ':d2' => $d2]);
        return ['prestamos' => (int)$a->fetchColumn(), 'devoluciones' => (int)$b->fetchColumn(),
                'multas_cobradas' => round((float)$c->fetchColumn(), 2), 'usuarios_activos' => (int)$d->fetchColumn()];
    };
    $actual = $totales($desde, $hasta);
    $anterior = $totales($prevDesde, $prevHasta);

    // Préstamos vencidos ahora mismo (estado actual, no depende del rango).
    $vencidos = (int)$conexion->query("SELECT COUNT(*) FROM prestamo WHERE estado IN ('Vencido', 'Con Mora')")->fetchColumn();

    semsad_responder([
        'status'            => 'success',
        'desde'             => $desde,
        'hasta'             => $hasta,
        'periodo_anterior'  => ['desde' => $prevDesde, 'hasta' => $prevHasta],
        'actual'            => $actual,
        'anterior'          => $anterior,
        'vencidos_ahora'    => $vencidos,
        'prestamos_por_dia' => $rellenar($prestamosDia, $desde, $dias),
        'devoluciones_por_dia' => $rellenar($devolDia, $desde, $dias),
        'multas_por_dia'    => $rellenar($multasDia, $desde, $dias),
        'recaudacion'       => ['qr' => round((float)$cob['qr'], 2), 'efectivo' => round((float)$cob['efectivo'], 2),
                                'otros' => round((float)$cob['otros'], 2), 'cobros' => (int)$cob['cobros']],
        'multas_pendientes' => ['cantidad' => (int)$pend['n'], 'monto' => round((float)$pend['total'], 2)],
        'mas_solicitados'   => array_map(fn($r) => ['titulo' => $r['titulo'], 'veces' => (int)$r['veces']], $top),
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] estadisticas: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron calcular las estadísticas.' . semsad_detalle_error($e)], 500);
}
