<?php
// ARCHIVO: estadisticas_sirve_para_graficos.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: dar los números de Reportes > Estadísticas (tarjetas, gráficos, comparación) para un período.
// QUÉ HACE: GET ?desde&hasta[&ant_desde&ant_hasta]. El período es el que se elige; el anterior se compara
//   (si no se envía, es el período de igual longitud justo antes). Los gráficos muestran los últimos 6 meses
//   terminando en el mes de "hasta". Todo sale de la base de datos real. Solo personal.
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
if ($dias > 740) semsad_responder(['status' => 'error', 'mensaje' => 'El rango máximo es de 2 años.'], 400);

// Período anterior: el que envíe la página, o el de igual longitud justo antes.
if (!empty($_GET['ant_desde']) && !empty($_GET['ant_hasta']) && $valida($_GET['ant_desde']) && $valida($_GET['ant_hasta'])) {
    $prevDesde = $_GET['ant_desde'];
    $prevHasta = $_GET['ant_hasta'];
} else {
    $prevHasta = date('Y-m-d', strtotime($desde . ' -1 day'));
    $prevDesde = date('Y-m-d', strtotime($desde . ' -' . $dias . ' days'));
}

try {
    $entregados = "p.estado NOT IN ('Solicitado', 'Cancelado')";

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

    // Serie mensual: últimos 6 meses terminando en el mes de "hasta" (meses sin datos quedan en 0).
    $mesFin = substr($hasta, 0, 7);
    $inicioSerie = date('Y-m-01', strtotime($mesFin . '-01 -5 months'));
    $finSerie = date('Y-m-t', strtotime($mesFin . '-01'));
    $agrupa = function (string $sql) use ($conexion, $inicioSerie, $finSerie) {
        $q = $conexion->prepare($sql);
        $q->execute([':d1' => $inicioSerie, ':d2' => $finSerie]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR);
    };
    $prest = $agrupa("SELECT DATE_FORMAT(p.fecha_salida,'%Y-%m') m, COUNT(*) FROM prestamo p WHERE $entregados AND DATE(p.fecha_salida) BETWEEN :d1 AND :d2 GROUP BY m");
    $devol = $agrupa("SELECT DATE_FORMAT(p.fecha_devolucion_real,'%Y-%m') m, COUNT(*) FROM prestamo p WHERE p.fecha_devolucion_real IS NOT NULL AND DATE(p.fecha_devolucion_real) BETWEEN :d1 AND :d2 GROUP BY m");
    $multa = $agrupa("SELECT DATE_FORMAT(fecha_pago,'%Y-%m') m, SUM(monto) FROM multa WHERE estado_pago = 'Pagado' AND DATE(fecha_pago) BETWEEN :d1 AND :d2 GROUP BY m");
    $meses = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime($mesFin . '-01 -' . $i . ' months'));
        $meses[] = ['mes' => $m, 'prestamos' => (int)($prest[$m] ?? 0), 'devoluciones' => (int)($devol[$m] ?? 0), 'multas' => round((float)($multa[$m] ?? 0), 2)];
    }

    // Recaudación por método en el período.
    $q = $conexion->prepare("SELECT
            COALESCE(SUM(CASE WHEN metodo_pago = 'QR' THEN monto END), 0) AS qr,
            COALESCE(SUM(CASE WHEN metodo_pago = 'Efectivo' THEN monto END), 0) AS efectivo,
            COALESCE(SUM(CASE WHEN metodo_pago IS NULL OR metodo_pago NOT IN ('QR','Efectivo') THEN monto END), 0) AS otros,
            COUNT(*) AS cobros
        FROM multa WHERE estado_pago = 'Pagado' AND DATE(fecha_pago) BETWEEN :d1 AND :d2");
    $q->execute([':d1' => $desde, ':d2' => $hasta]);
    $cob = $q->fetch(PDO::FETCH_ASSOC);

    // Multas pendientes ahora (no depende del período).
    $pend = $conexion->query("SELECT COUNT(*) n, COALESCE(SUM(monto),0) total FROM multa WHERE estado_pago = 'Pendiente'")->fetch(PDO::FETCH_ASSOC);

    // Libros más solicitados en el período.
    $q = $conexion->prepare("SELECT mb.titulo, COUNT(*) veces
                               FROM detalle_prestamo dp
                               INNER JOIN prestamo p ON p.id_prestamo = dp.id_prestamo
                               INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
                               INNER JOIN materialbibliografico mb ON mb.id_material = e.id_material
                              WHERE $entregados AND DATE(p.fecha_salida) BETWEEN :d1 AND :d2
                              GROUP BY mb.id_material, mb.titulo ORDER BY veces DESC LIMIT 10");
    $q->execute([':d1' => $desde, ':d2' => $hasta]);
    $top = $q->fetchAll(PDO::FETCH_ASSOC);

    // Vencidos según la fecha límite (no solo el estado): un préstamo Activo con fecha pasada también cuenta.
    $vencidos = (int)$conexion->query("SELECT COUNT(*) FROM prestamo WHERE estado IN ('Activo', 'Vencido', 'Con Mora') AND fecha_limite < NOW() AND fecha_devolucion_real IS NULL")->fetchColumn();

    semsad_responder([
        'status'           => 'success',
        'desde'            => $desde,
        'hasta'            => $hasta,
        'periodo_anterior' => ['desde' => $prevDesde, 'hasta' => $prevHasta],
        'actual'           => $actual,
        'anterior'         => $anterior,
        'vencidos_ahora'   => $vencidos,
        'meses'            => $meses,
        'recaudacion'      => ['qr' => round((float)$cob['qr'], 2), 'efectivo' => round((float)$cob['efectivo'], 2),
                               'otros' => round((float)$cob['otros'], 2), 'cobros' => (int)$cob['cobros']],
        'multas_pendientes'=> ['cantidad' => (int)$pend['n'], 'monto' => round((float)$pend['total'], 2)],
        'mas_solicitados'  => array_map(fn($r) => ['titulo' => $r['titulo'], 'veces' => (int)$r['veces']], $top),
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] estadisticas: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron calcular las estadísticas.' . semsad_detalle_error($e)], 500);
}
