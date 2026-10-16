<?php
require_once __DIR__ . '/conexion.php';

// Configurar encabezado JSON
header('Content-Type: application/json; charset=utf-8');

$q = strtolower(trim($_GET['q'] ?? $_GET['buscar'] ?? $_GET['query'] ?? ''));
$categoria = (int)($_GET['categoria'] ?? 0);

try {
    $sql = "
        SELECT 
            m.id_material,
            m.titulo,
            COALESCE(m.codigo_isbn_issn, 'Sin Código') AS isbn,
            m.codigo_isbn_issn,
            m.agno_publicacion,
            COALESCE(e.nombre, 'Sin Editorial') AS editorial,
            c.nombre AS categoria,
            tm.nombre AS tipo_material,
            COALESCE(
                (SELECT GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos) SEPARATOR ', ')
                 FROM Material_Autor ma
                 JOIN Autor a ON ma.id_autor = a.id_autor
                 WHERE ma.id_material = m.id_material),
                'Autor Desconocido'
            ) AS autores,
            (SELECT COUNT(*) FROM Ejemplar ej WHERE ej.id_material = m.id_material AND ej.estado_ejemplar != 'Baja') AS stock_total,
            (SELECT COUNT(*) FROM Ejemplar ej WHERE ej.id_material = m.id_material AND ej.estado_ejemplar = 'Disponible') AS stock_disponible,
            COALESCE(
                (SELECT CONCAT(u.sala, ' - ', u.estante, ' (', u.nivel, ')')
                 FROM Ejemplar ej 
                 JOIN UbicacionFisica u ON ej.id_ubicacion = u.id_ubicacion 
                 WHERE ej.id_material = m.id_material LIMIT 1),
                'Sin Ubicación'
            ) AS ubicacion
        FROM MaterialBibliografico m
        LEFT JOIN Editorial e ON m.id_editorial = e.id_editorial
        INNER JOIN Categoria c ON m.id_categoria = c.id_categoria
        INNER JOIN TipoMaterial tm ON m.id_tipo_material = tm.id_tipo_material
        WHERE 1=1
    ";

    $params = [];

    if ($q !== '') {
        $sql .= " AND (LOWER(m.titulo) LIKE :q OR LOWER(m.codigo_isbn_issn) LIKE :q OR m.id_material IN (
            SELECT ma.id_material FROM Material_Autor ma JOIN Autor a ON ma.id_autor = a.id_autor
            WHERE LOWER(a.nombres) LIKE :q OR LOWER(a.apellidos) LIKE :q
        ))";
        $params[':q'] = "%$q%";
    }

    if ($categoria > 0) {
        $sql .= " AND m.id_categoria = :cat";
        $params[':cat'] = $categoria;
    }

    $sql .= " ORDER BY m.id_material ASC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $libros = [];
    foreach ($rows as $r) {
        $anioVal = $r['agno_publicacion'] ? (string)$r['agno_publicacion'] : 'N/A';
        $libros[] = [
            'id_material'      => (int)$r['id_material'],
            'id'               => (int)$r['id_material'],
            'titulo'           => $r['titulo'],
            'isbn'             => $r['isbn'],
            'codigo_isbn_issn' => $r['codigo_isbn_issn'],
            'agno_publicacion' => $anioVal,
            'año_publicacion'  => $anioVal,
            'año'              => $anioVal,
            'anio'             => $anioVal,
            'year'             => $anioVal,
            'editorial'        => $r['editorial'],
            'categoria'        => $r['categoria'],
            'tipo_material'    => $r['tipo_material'],
            'autores'          => $r['autores'],
            'autor'            => $r['autores'],
            'stock_total'      => (int)$r['stock_total'],
            'total'            => (int)$r['stock_total'],
            'stock_disponible' => (int)$r['stock_disponible'],
            'disponible'       => (int)$r['stock_disponible'],
            'ubicacion'        => $r['ubicacion'],
            'estado'           => ((int)$r['stock_disponible'] > 0) ? 'Disponible' : 'Agotado'
        ];
    }

    semsad_responder([
        'status' => 'success',
        'libros' => $libros,
        'data'   => $libros
    ]);

} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_libros: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al consultar libros: ' . $e->getMessage()
    ], 500);
}
