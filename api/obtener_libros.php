<?php
require_once 'conexion.php';
semsad_iniciar_sesion();

header('Content-Type: application/json; charset=utf-8');

try {
    $id_usuario = (int)(
        $_GET['id_usuario'] ?? 
        $_GET['usuario_id'] ?? 
        $_SESSION['id_usuario'] ?? 0
    );

    $sql = "SELECT 
                m.id_material,
                m.titulo,
                m.autores,
                m.isbn,
                m.agno_publicacion,
                COUNT(CASE WHEN e.estado_ejemplar = 'Disponible' THEN 1 END) AS stock_disponible,
                COUNT(e.id_ejemplar) AS total_ejemplares
            FROM MaterialBibliografico m
            LEFT JOIN Ejemplar e ON m.id_material = e.id_material
            GROUP BY m.id_material, m.titulo, m.autores, m.isbn, m.agno_publicacion
            ORDER BY m.titulo ASC";

    $stmt = $conexion->query($sql);
    $libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $prestadosPorUsuario = [];
    if ($id_usuario > 0) {
        $stmtP = $conexion->prepare("
            SELECT DISTINCT e.id_material 
            FROM Prestamo p
            INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            WHERE p.id_usuario = :u AND p.estado = 'Activo'
        ");
        $stmtP->execute([':u' => $id_usuario]);
        $prestadosPorUsuario = $stmtP->fetchAll(PDO::FETCH_COLUMN);
    }

    foreach ($libros as &$libro) {
        $libro['stock_disponible'] = (int)$libro['stock_disponible'];
        $libro['total_ejemplares'] = (int)$libro['total_ejemplares'];
        $libro['reservado_por_mi'] = in_array($libro['id_material'], $prestadosPorUsuario);
    }

    semsad_responder([
        'status' => 'success',
        'libros' => $libros,
        'data'   => $libros
    ]);

} catch (PDOException $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al obtener los libros: ' . $e->getMessage()], 500);
}
