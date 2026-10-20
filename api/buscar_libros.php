<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $busqueda = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';

    $sql = "SELECT 
                mb.id_material,
                mb.titulo,
                COALESCE(mb.codigo_isbn_issn, 'Sin Código') AS isbn,
                COALESCE(tm.nombre, 'General') AS tipo_material,
                COALESCE(c.nombre, 'General') AS categoryLabel,
                COALESCE(e.nombre, 'Sin Editorial') AS editorial,
                (SELECT COUNT(*) FROM ejemplar ej WHERE ej.id_material = mb.id_material AND ej.estado_ejemplar != 'Baja') AS total,
                (SELECT COUNT(*) FROM ejemplar ej WHERE ej.id_material = mb.id_material AND " . semsad_sql_ejemplar_libre('ej') . ") AS stock,
                COALESCE(
                    (SELECT GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos) SEPARATOR ', ')
                     FROM material_autor ma JOIN autor a ON ma.id_autor = a.id_autor
                     WHERE ma.id_material = mb.id_material),
                    'Varios Autores'
                ) AS author,
                COALESCE(
                    (SELECT u.sala FROM ejemplar ej2 JOIN ubicacionfisica u ON ej2.id_ubicacion = u.id_ubicacion WHERE ej2.id_material = mb.id_material LIMIT 1),
                    'Estante Principal'
                ) AS shelf
            FROM materialbibliografico mb
            LEFT JOIN tipomaterial tm ON mb.id_tipo_material = tm.id_tipo_material
            LEFT JOIN categoria c ON mb.id_categoria = c.id_categoria
            LEFT JOIN editorial e ON mb.id_editorial = e.id_editorial
            WHERE 1=1";

    $params = [];

    if (!empty($busqueda)) {
        $sql .= " AND (LOWER(mb.titulo) LIKE :q OR LOWER(mb.codigo_isbn_issn) LIKE :q OR LOWER(c.nombre) LIKE :q)";
        $params[':q'] = "%$busqueda%";
    }

    $sql .= " ORDER BY mb.id_material ASC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mapear los datos al formato exacto que espera el Frontend del Estudiante
    $datosFormateados = array_map(function($libro) {
        $catLabel = $libro['categoryLabel'];
        $categoryId = 'all';
        $theme = 'blue';

        if (stripos($catLabel, 'Sistemas') !== false || stripos($catLabel, 'Ingeniería') !== false) {
            $categoryId = 'software';
            $theme = 'blue';
        } else if (stripos($catLabel, 'Telecomunicaciones') !== false) {
            $categoryId = 'telecom';
            $theme = 'green';
        } else if (stripos($catLabel, 'Exactas') !== false || stripos($catLabel, 'Datos') !== false) {
            $categoryId = 'db';
            $theme = 'red';
        }

        return [
            'id'             => (int)$libro['id_material'],
            'id_material'    => (int)$libro['id_material'],
            'classification' => '000.0',
            'title'          => $libro['titulo'],
            'titulo'         => $libro['titulo'],
            'author'         => $libro['author'],
            'autores'        => $libro['author'],
            'category'       => $categoryId,
            'categoryLabel'  => $catLabel,
            'categoryTheme'  => $theme,
            'stock'          => (int)$libro['stock'],
            'disponible'     => (int)$libro['stock'],
            'total'          => (int)$libro['total'],
            'shelf'          => $libro['shelf'],
            'ubicacion'      => $libro['shelf'],
            'isbn'           => $libro['isbn'],
            'coverImg'       => 'https://placehold.co/300x400/1e293b/ffffff?text=' . urlencode($libro['titulo'])
        ];
    }, $libros);

    semsad_responder([
        'status' => 'success',
        'total'  => count($datosFormateados),
        'datos'  => $datosFormateados,
        'libros' => $datosFormateados
    ]);

} catch (PDOException $e) {
    error_log('[SEMSAD] buscar_libros: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al consultar libros: ' . $e->getMessage()
    ], 500);
}
