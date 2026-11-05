<?php
// ARCHIVO: buscar_libros_sirve_para_buscador_catalogo.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Devolver la lista de libros del catálogo para el buscador que usa el estudiante.
// QUÉ HACE: Recibe un texto opcional por la URL (parámetro q) y busca en el título, el ISBN y el nombre de la categoría.
// Responde un JSON con cada libro, su disponibilidad (stock), total de copias, ubicación y portada. No exige iniciar sesión.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla del catálogo del estudiante (revisar qué archivo HTML la llama).
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_iniciar_sesion();

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

// Responde a la petición previa del navegador (OPTIONS) sin tocar la base de datos.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Lee el texto del buscador (?q=...), lo pasa a minúsculas y quita espacios. Si está vacío, se listan todos los libros.
    $busqueda = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';

    // Consulta principal: un registro por libro, con su tipo, categoría, editorial, autores, copias totales y copias libres (stock).
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

    // Si hay texto, se filtra: busca si aparece en el título, en el ISBN o en el nombre de la categoría (LIKE significa «contiene»).
    if (!empty($busqueda)) {
        $sql .= " AND (LOWER(mb.titulo) LIKE :q OR LOWER(mb.codigo_isbn_issn) LIKE :q OR LOWER(c.nombre) LIKE :q)";
        $params[':q'] = "%$busqueda%";
    }

    // Ordena los libros por su número de registro, de menor a mayor.
    $sql .= " ORDER BY mb.id_material ASC";

    // Prepara y ejecuta la consulta con sus parámetros, y guarda todas las filas en un arreglo.
    $stmt = $conexion->prepare($sql);
    $stmt->execute($params);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mapear los datos al formato exacto que espera el Frontend del Estudiante
    $datosFormateados = array_map(function($libro) {
        $catLabel = $libro['categoryLabel'];
        $categoryId = 'all';
        $theme = 'blue';

        // Traduce el nombre de la categoría a los identificadores que usa la web (categoría y color). No distingue mayúsculas.
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

        // Arma cada libro con los nombres de campo que espera la pantalla. Algunos campos se repiten en español e inglés por compatibilidad.
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

    // Respuesta de éxito: la lista va en dos nombres (datos y libros) y se envía también el total.
    semsad_responder([
        'status' => 'success',
        'total'  => count($datosFormateados),
        'datos'  => $datosFormateados,
        'libros' => $datosFormateados
    ]);

} catch (PDOException $e) {
    // Si la consulta falla, se guarda el error en el log del servidor y se responde con error 500.
    error_log('[SEMSAD] buscar_libros: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al consultar libros.' . semsad_detalle_error($e)
    ], 500);
}
