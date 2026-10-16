<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

try {
    // El catálogo se consulta en modo lectura. No corregimos estados de inventario dentro de un GET.
    $id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
    $sql = "
        SELECT
            mb.id_material,
            mb.titulo,
            mb.codigo_isbn_issn,
            mb.agno_publicacion,
            COALESCE(
                (SELECT GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos)
                                     ORDER BY ma.orden_autor SEPARATOR ', ')
                 FROM Material_Autor ma
                 INNER JOIN Autor a ON a.id_autor = ma.id_autor
                 WHERE ma.id_material = mb.id_material),
                'Autor no especificado'
            ) AS autores,
            COALESCE((SELECT COUNT(*) FROM Ejemplar e
                      WHERE e.id_material = mb.id_material AND e.estado_ejemplar <> 'Baja'), 0) AS total_ejemplares,
            COALESCE((SELECT COUNT(*) FROM Ejemplar e
                      WHERE e.id_material = mb.id_material AND e.estado_ejemplar = 'Disponible'), 0) AS stock_disponible,
            COALESCE((SELECT COUNT(*) FROM Reserva r
                      WHERE r.id_material = mb.id_material AND r.estado = 'Pendiente'), 0) AS reservas_pendientes
        FROM MaterialBibliografico mb
        ORDER BY mb.id_material DESC
    ";
    $materials = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $prestadosPorUsuario = [];
    $reservasPorUsuario = [];
    if ($id_usuario > 0) {
        $stmt = $conexion->prepare("
            SELECT DISTINCT e.id_material
            FROM Prestamo p
            INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
            INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
            WHERE p.id_usuario = :u AND p.estado IN ('Activo', 'Con Mora')
        ");
        $stmt->execute([':u' => $id_usuario]);
        $prestadosPorUsuario = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmtR = $conexion->prepare("
            SELECT id_material, prioridad
            FROM Reserva
            WHERE id_usuario = :u AND estado = 'Pendiente'
        ");
        $stmtR->execute([':u' => $id_usuario]);
        foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reservasPorUsuario[(int)$r['id_material']] = [
                'id_reserva' => (int)$r['id_reserva'],
                'prioridad' => (int)$r['prioridad']
            ];
        }
    }

    $libros = [];
    foreach ($materials as $m) {
        $idMaterial = (int)$m['id_material'];
        $reservaPropia = $reservasPorUsuario[$idMaterial] ?? null;
        $prioridad = is_array($reservaPropia) ? $reservaPropia['prioridad'] : null;
        $idReservaPropia = is_array($reservaPropia) ? $reservaPropia['id_reserva'] : null;
        $autorTexto = (string)($m['autores'] ?? 'Autor no especificado');
        $stock = (int)$m['stock_disponible'];
        $queue = (int)$m['reservas_pendientes'];

        $libros[] = [
            'id_material' => $idMaterial,
            'id' => $idMaterial,
            'titulo' => (string)($m['titulo'] ?? 'Sin título'),
            'autores' => $autorTexto,
            'autor' => $autorTexto,
            'isbn' => (string)($m['codigo_isbn_issn'] ?? 'S/N'),
            'codigo_isbn_issn' => (string)($m['codigo_isbn_issn'] ?? 'S/N'),
            'agno_publicacion' => $m['agno_publicacion'] ?? 'N/A',
            'stock_disponible' => $stock,
            'disponible' => $stock,
            'total_ejemplares' => (int)$m['total_ejemplares'],
            'reservado_por_mi' => in_array($idMaterial, $prestadosPorUsuario, true),
            'tengo_prestamo_activo' => in_array($idMaterial, $prestadosPorUsuario, true),
            'tengo_reserva_pendiente' => $prioridad !== null,
            'prioridad_reserva' => $prioridad,
            'id_reserva_pendiente' => $idReservaPropia,
            'hay_reservas_pendientes' => $queue > 0,
            'reservas_pendientes' => $queue
        ];
    }

    semsad_responder(['status' => 'success', 'libros' => $libros, 'data' => $libros, 'datos' => $libros]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_libros: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al obtener el catálogo de libros.'], 500);
}
?>