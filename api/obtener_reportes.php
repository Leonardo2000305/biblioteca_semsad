<?php
/**
 * REPORTES E INDICADORES (solo personal): resumen del día, libros más solicitados,
 * morosos, stock crítico e inventario por estado. Solo lee, no modifica nada.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/ayudas_prestamo.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

/** Ejecuta una consulta de lectura; si falla (tabla o columna que no existe) devuelve $porDefecto. */
function semsad_leer(PDO $c, string $sql, array $params = [], $porDefecto = [])
{
    try {
        $q = $c->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[SEMSAD] obtener_reportes: ' . $e->getMessage());
        return $porDefecto;
    }
}

try {
    semsad_marcar_vencidos($conexion);
    $hoy = date('Y-m-d');
    $activos = "'" . implode("','", SEMSAD_ESTADOS_ACTIVOS) . "'";

    $unico = fn(string $sql, array $p = [], $def = 0) => (semsad_leer($conexion, $sql, $p, null)[0] ?? ['v' => $def])['v'] ?? $def;

    $resumen = [
        'prestamos_hoy'        => (int)$unico("SELECT COUNT(*) AS v FROM Prestamo WHERE DATE(fecha_salida) = :h", [':h' => $hoy]),
        'devoluciones_hoy'     => (int)$unico("SELECT COUNT(*) AS v FROM Prestamo WHERE DATE(fecha_devolucion_real) = :h", [':h' => $hoy]),
        'prestamos_activos'    => (int)$unico("SELECT COUNT(*) AS v FROM Prestamo WHERE estado IN ($activos)"),
        'multas_pendientes'    => (int)$unico("SELECT COUNT(*) AS v FROM Multa WHERE estado_pago = 'Pendiente'"),
        'multas_monto_pend'    => (float)$unico("SELECT COALESCE(SUM(monto),0) AS v FROM Multa WHERE estado_pago = 'Pendiente'"),
        'multas_cobradas'      => (float)$unico("SELECT COALESCE(SUM(monto),0) AS v FROM Multa WHERE estado_pago = 'Pagado'"),
        'usuarios_bloqueados'  => (int)$unico("SELECT COUNT(*) AS v FROM Usuario WHERE estado IN ('Bloqueado','Suspendido')"),
    ];

    $masSolicitados = semsad_leer($conexion,
        "SELECT m.titulo, m.autores, COUNT(*) AS veces
           FROM Detalle_Prestamo dp
     INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
     INNER JOIN MaterialBibliografico m ON m.id_material = e.id_material
       GROUP BY m.id_material, m.titulo, m.autores
       ORDER BY veces DESC, m.titulo ASC LIMIT 10");

    $morosos = [];
    $filas = semsad_leer($conexion,
        "SELECT p.id_prestamo, p.fecha_limite, u.nombres, u.apellidos, u.correo, m.titulo
           FROM Prestamo p
      LEFT JOIN Usuario u ON u.id_usuario = p.id_usuario
      LEFT JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
      LEFT JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
      LEFT JOIN MaterialBibliografico m ON m.id_material = e.id_material
          WHERE p.estado IN ($activos) AND p.fecha_limite < NOW()
       ORDER BY p.fecha_limite ASC");
    foreach ($filas as $f) {
        $mora = semsad_calcular_mora($f['fecha_limite']);
        $morosos[] = [
            'usuario' => trim(($f['nombres'] ?? '') . ' ' . ($f['apellidos'] ?? '')) ?: 'Usuario no encontrado',
            'correo'  => $f['correo'] ?? '',
            'libro'   => $f['titulo'] ?? 'Libro no encontrado',
            'limite'  => date('Y-m-d', strtotime($f['fecha_limite'])),
            'dias'    => $mora['dias'],
            'monto'   => $mora['monto'],
        ];
    }

    $stockCritico = semsad_leer($conexion,
        "SELECT m.titulo,
                SUM(e.estado_ejemplar = 'Disponible') AS disponibles,
                COUNT(e.id_ejemplar) AS total
           FROM MaterialBibliografico m
      LEFT JOIN Ejemplar e ON e.id_material = m.id_material
       GROUP BY m.id_material, m.titulo
         HAVING COALESCE(SUM(e.estado_ejemplar = 'Disponible'), 0) <= 1
       ORDER BY disponibles ASC, m.titulo ASC");

    $reservas = semsad_leer($conexion,
        "SELECT r.prioridad AS turno, m.titulo, u.nombres, u.apellidos, u.correo
           FROM Reserva r
      LEFT JOIN MaterialBibliografico m ON m.id_material = r.id_material
      LEFT JOIN Usuario u ON u.id_usuario = r.id_usuario
          WHERE r.estado = 'Pendiente'
       ORDER BY m.titulo ASC, r.prioridad ASC");

    $inventario = ['Disponible' => 0, 'Prestado' => 0, 'Reservado' => 0, 'En Mantenimiento' => 0, 'Baja' => 0];
    foreach (semsad_leer($conexion, "SELECT estado_ejemplar AS estado, COUNT(*) AS n FROM Ejemplar GROUP BY estado_ejemplar") as $f) {
        $inventario[$f['estado']] = (int)$f['n'];
    }

    semsad_responder([
        'status'          => 'success',
        'generado'        => date('Y-m-d H:i'),
        'resumen'         => $resumen,
        'mas_solicitados' => $masSolicitados,
        'morosos'         => $morosos,
        'stock_critico'   => $stockCritico,
        'inventario'      => $inventario,
        'reservas'        => $reservas,
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_reportes: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron generar los reportes.'], 500);
}
