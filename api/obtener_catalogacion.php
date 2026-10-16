<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $columnasMaterial = $conexion->query("SHOW COLUMNS FROM MaterialBibliografico")->fetchAll(PDO::FETCH_COLUMN, 0);
    $tipos = $conexion->query("SELECT id_tipo_material, nombre FROM TipoMaterial ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $editoriales = $conexion->query("SELECT id_editorial, nombre FROM Editorial ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $categorias = $conexion->query("
        SELECT c.id_categoria, c.nombre
        FROM Categoria c
        WHERE NOT EXISTS (
            SELECT 1 FROM Categoria hija WHERE hija.id_categoria_padre = c.id_categoria
        )
        ORDER BY c.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    semsad_responder([
        'status' => 'success',
        'tipos' => $tipos,
        'editoriales' => $editoriales,
        'categorias' => $categorias,
        'metadatos_avanzados' => [
            'edicion' => in_array('edicion', $columnasMaterial, true),
            'idioma' => in_array('idioma', $columnasMaterial, true)
        ]
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_catalogacion: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron cargar las opciones de catalogación.'], 500);
}
?>