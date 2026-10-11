<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

    $sql = "SELECT 
                mb.id_material,
                mb.titulo,
                mb.codigo_isbn_issn as isbn,
                tm.nombre AS tipo_material,
                c.nombre AS categoryLabel,
                e.nombre AS editorial,
                COUNT(ej.id_ejemplar) AS total,
                SUM(CASE WHEN ej.estado_ejemplar = 'Disponible' THEN 1 ELSE 0 END) AS stock,
                (SELECT CONCAT(nombres, ' ', apellidos) FROM Autor a INNER JOIN Material_Autor ma ON a.id_autor = ma.id_autor WHERE ma.id_material = mb.id_material LIMIT 1) as author,
                (SELECT sala FROM UbicacionFisica uf INNER JOIN Ejemplar e2 ON e2.id_ubicacion = uf.id_ubicacion WHERE e2.id_material = mb.id_material LIMIT 1) as shelf
            FROM MaterialBibliografico mb
            LEFT JOIN TipoMaterial tm ON mb.id_tipo_material = tm.id_tipo_material
            LEFT JOIN Categoria c ON mb.id_categoria = c.id_categoria
            LEFT JOIN Editorial e ON mb.id_editorial = e.id_editorial
            LEFT JOIN Ejemplar ej ON mb.id_material = ej.id_material";

    if (!empty($busqueda)) {
        $sql .= " WHERE mb.titulo LIKE :q OR mb.codigo_isbn_issn LIKE :q OR c.nombre LIKE :q";
    }

    $sql .= " GROUP BY mb.id_material";

    $stmt = $conexion->prepare($sql);

    if (!empty($busqueda)) {
        $stmt->execute([':q' => "%$busqueda%"]);
    } else {
        $stmt->execute();
    }

    $libros = $stmt->fetchAll();

    // Mapear los datos al formato que espera el Frontend
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
            'id' => $libro['id_material'],
            'classification' => '000.0', // Ficticio si no está en BD
            'title' => $libro['titulo'],
            'author' => $libro['author'] ?: 'Varios Autores',
            'category' => $categoryId,
            'categoryLabel' => $catLabel,
            'categoryTheme' => $theme,
            'stock' => (int)$libro['stock'],
            'total' => (int)$libro['total'],
            'shelf' => $libro['shelf'] ?: 'Estante Principal',
            'isbn' => $libro['isbn'],
            'coverImg' => 'https://placehold.co/300x400/1e293b/ffffff?text=' . urlencode($libro['titulo'])
        ];
    }, $libros);

    echo json_encode([
        'status' => 'success',
        'total' => count($datosFormateados),
        'datos' => $datosFormateados
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'Error al consultar libros: ' . $e->getMessage()
    ]);
}
?>
