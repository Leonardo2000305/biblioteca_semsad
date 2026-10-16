<?php
require_once __DIR__ . '/conexion.php';

// Configurar encabezado JSON
header('Content-Type: application/json; charset=utf-8');

$q = strtolower(trim($_GET['q'] ?? ''));
$categoria = (int)($_GET['categoria'] ?? 0);

try {
    $sql = "
        SELECT 
            m.id_material,
            m.titulo,
            m.codigo_isbn_issn AS isbn,
            m.agno_publicacion,
            COALESCE(e.nombre, 'Sin Editorial') AS editorial,
            c.nombre AS categoria,
            tm.nombre AS tipo_material,
            COALESCE(GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos) SEPARATOR ', '), 'Autor Desconocido') AS autores,
            (SELECT COUNT(*) FROM Ejemplar ej WHERE ej.id_material = m.id_material AND ej.estado_ejemplar != 'Baja') AS stock_total,
            (SELECT COUNT(*) FROM Ejemplar ej WHERE ej.id_material = m.id_material AND ej.estado_ejemplar = 'Disponible') AS stock_disponible,
            COALESCE((SELECT u.estante FROM Ejemplar ej JOIN UbicacionFisica u ON ej.id_ubicacion = u.id_ubicacion WHERE ej.id_material = m.id_material LIMIT 1), 'Sin Ubicación') AS ubicacion
        FROM MaterialBibliografico m
        LEFT JOIN Editorial e ON m.id_editorial = e.id_editorial
        INNER JOIN Categoria c ON m.id_categoria = c.id_categoria
        INNER JOIN TipoMaterial tm ON m.id_tipo_material = tm.id_tipo_material
        LEFT JOIN Material_Autor ma ON m.id_material = ma.id_material
        LEFT JOIN Autor a ON ma.id_autor = a.id_autor
        WHERE 1=1
    ";

    $params = [];

    if ($q !== '') {
        $sql .= " AND (LOWER(m.titulo) LIKE :q OR LOWER(m.codigo_isbn_issn) LIKE :q OR LOWER(a.nombres) LIKE :q OR LOWER(a.apellidos) LIKE :q)";
        $params[':q'] = "%$q%";
    }

    if ($categoria > 0) {
        $sql .= " AND m.id_categoria = :cat";
        $params[':cat'] = $categoria;
    }

    $sql .= " GROUP BY m.id_material ORDER BY m.titulo ASC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    semsad_responder([
        'status' => 'success',
        'libros' => $libros
    ]);

} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_libros: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al consultar el catálogo de libros.'
    ], 500);
}
