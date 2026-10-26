<?php
require_once 'conexion.php';
require_once __DIR__ . '/usuario_actual.php';
require_once __DIR__ . '/ayudas_prestamo.php';
semsad_iniciar_sesion();
header('Content-Type: application/json; charset=utf-8');

try {
    // Solo la sesión del servidor identifica al usuario (ver usuario_actual.php)
    $usuarioActual = semsad_usuario_actual($conexion);
    $id_usuario = $usuarioActual ? (int)$usuarioActual['id_usuario'] : 0;

    if ($id_usuario <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Usuario no identificado.'], 400);
    }

    semsad_marcar_vencidos($conexion);   // usa un estado que exista en la base (antes podía fallar)

    $exprAutores = semsad_expr_autores($conexion, 'm');   // columna autores, o Material_Autor si no existe
    $exprIsbn = semsad_expr_isbn($conexion, 'm');
    $sql = "SELECT 
                p.id_prestamo,
                m.titulo AS libro_titulo,
                $exprAutores AS libro_autores,
                $exprIsbn AS libro_isbn,
                p.fecha_salida,
                p.fecha_limite,
                p.estado
            FROM prestamo p
            INNER JOIN detalle_prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            INNER JOIN materialbibliografico m ON e.id_material = m.id_material
            WHERE p.id_usuario = :u
            ORDER BY p.id_prestamo DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([':u' => $id_usuario]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $misPrestamos = [];
    foreach ($filas as $f) {
        $misPrestamos[] = [
            'id_prestamo'   => (int)$f['id_prestamo'],
            'titulo'        => $f['libro_titulo'] ?? 'Libro',
            'autores'       => $f['libro_autores'] ?? 'S/N',
            'isbn'          => $f['libro_isbn'] ?? 'S/N',
            'fecha_salida'  => date('d/m/Y H:i', strtotime($f['fecha_salida'])),
            'fecha_limite'  => date('d/m/Y', strtotime($f['fecha_limite'])),
            'estado'        => $f['estado'] ?? 'Activo'
        ];
    }

    semsad_responder([
        'status' => 'success',
        'prestamos' => $misPrestamos,
        'data' => $misPrestamos
    ]);

} catch (Exception $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al obtener tus préstamos.' . semsad_detalle_error($e)], 500);
}
