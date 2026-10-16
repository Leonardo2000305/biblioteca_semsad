<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Tu sesión no está activa. Inicia sesión nuevamente.'], 401);
}

try {
    // El historial se filtra siempre por la sesión autenticada, no por un ID recibido por URL.
    $sql = "
        SELECT
            p.id_prestamo,
            p.fecha_salida,
            p.fecha_limite,
            p.fecha_devolucion_real,
            p.estado,
            m.titulo AS libro_titulo,
            m.codigo_isbn_issn AS libro_isbn,
            COALESCE(
                (SELECT GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos) ORDER BY ma.orden_autor SEPARATOR ', ')
                 FROM Material_Autor ma
                 INNER JOIN Autor a ON a.id_autor = ma.id_autor
                 WHERE ma.id_material = m.id_material),
                'Autor no especificado'
            ) AS libro_autores
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        INNER JOIN MaterialBibliografico m ON m.id_material = e.id_material
        WHERE p.id_usuario = :u
        ORDER BY p.id_prestamo DESC
    ";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':u' => $id_usuario]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hoy = strtotime(date('Y-m-d'));
    $misPrestamos = [];

    foreach ($rows as $f) {
        $limiteTs = !empty($f['fecha_limite']) ? strtotime($f['fecha_limite']) : false;
        $estadoOriginal = (string)($f['estado'] ?? 'Activo');
        $estadoMostrar = $estadoOriginal;
        if ($estadoOriginal === 'Con Mora' || ($estadoOriginal === 'Activo' && $limiteTs !== false && $limiteTs < $hoy)) {
            $estadoMostrar = 'Vencido';
        }

        $misPrestamos[] = [
            'id_prestamo' => (int)$f['id_prestamo'],
            'titulo' => (string)($f['libro_titulo'] ?? 'Libro'),
            'autores' => (string)($f['libro_autores'] ?? 'Autor no especificado'),
            'isbn' => (string)($f['libro_isbn'] ?? 'S/N'),
            'fecha_salida' => !empty($f['fecha_salida']) ? date('d/m/Y H:i', strtotime($f['fecha_salida'])) : '',
            'fecha_limite' => $limiteTs !== false ? date('d/m/Y', $limiteTs) : 'S/F',
            'fecha_devolucion_real' => !empty($f['fecha_devolucion_real']) ? date('d/m/Y H:i', strtotime($f['fecha_devolucion_real'])) : '',
            'estado' => $estadoMostrar,
            'estado_original' => $estadoOriginal
        ];
    }

    // Las reservas pendientes aparecen en el mismo historial, siempre filtradas por el usuario autenticado.
    $stmtR = $conexion->prepare("
        SELECT r.id_reserva, r.id_material, r.prioridad, mb.titulo
        FROM Reserva r
        INNER JOIN MaterialBibliografico mb ON mb.id_material = r.id_material
        WHERE r.id_usuario = :u AND r.estado = 'Pendiente'
        ORDER BY r.prioridad ASC, r.id_reserva ASC
    ");
    $stmtR->execute([':u' => $id_usuario]);
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $misPrestamos[] = [
            'id_prestamo' => null,
            'id_reserva' => (int)$r['id_reserva'],
            'titulo' => (string)($r['titulo'] ?? 'Libro'),
            'autores' => '',
            'isbn' => '',
            'fecha_salida' => 'Lista de espera · Posición ' . (int)$r['prioridad'],
            'fecha_limite' => 'En espera',
            'fecha_devolucion_real' => '',
            'estado' => 'Reserva pendiente',
            'estado_original' => 'Pendiente',
            'prioridad' => (int)$r['prioridad']
        ];
    }

    semsad_responder(['status' => 'success', 'prestamos' => $misPrestamos, 'data' => $misPrestamos]);
} catch (Throwable $e) {
    error_log('[SEMSAD] mis_prestamos: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener tu historial de préstamos.'], 500);
}
?>