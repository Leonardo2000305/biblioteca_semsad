<?php
require_once __DIR__ . '/conexion.php';

// Catálogo público: se arma desde las tablas reales (materialbibliografico, autor, ejemplar).
try {
    $stmt = $conexion->query(
        "SELECT mb.id_material,
                mb.titulo,
                mb.codigo_isbn_issn AS isbn,
                mb.agno_publicacion AS anio_publicacion,
                (SELECT CONCAT(a.nombres, ' ', a.apellidos)
                   FROM autor a
             INNER JOIN material_autor ma ON ma.id_autor = a.id_autor
                  WHERE ma.id_material = mb.id_material
                  LIMIT 1) AS autor,
                COALESCE(SUM(CASE WHEN ej.estado_ejemplar = 'Disponible' THEN 1 ELSE 0 END), 0) AS disponible
           FROM materialbibliografico mb
      LEFT JOIN ejemplar ej ON ej.id_material = mb.id_material
       GROUP BY mb.id_material, mb.titulo, mb.codigo_isbn_issn, mb.agno_publicacion
       ORDER BY mb.id_material DESC"
    );
    semsad_responder(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_libros: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener el catálogo.'], 500);
}
