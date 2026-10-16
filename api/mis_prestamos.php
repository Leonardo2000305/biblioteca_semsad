<?php
require_once 'conexion.php';
require_once __DIR__ . '/usuario_actual.php';
semsad_iniciar_sesion();
header('Content-Type: application/json; charset=utf-8');

try {
    // La sesión del servidor manda; los datos del navegador solo cuentan si coinciden (ver usuario_actual.php)
    $usuarioActual = semsad_usuario_actual($conexion, (int)($_GET['id_usuario'] ?? 0), (string)($_GET['correo'] ?? ''));
    $id_usuario = $usuarioActual ? (int)$usuarioActual['id_usuario'] : 0;

    if ($id_usuario <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Usuario no identificado.'], 400);
    }

    $conexion->exec("
        UPDATE Prestamo 
        SET estado = 'Vencido' 
        WHERE estado = 'Activo' AND fecha_limite < NOW()
    ");

    $sql = "SELECT 
                p.id_prestamo,
                m.titulo AS libro_titulo,
                m.autores AS libro_autores,
                m.isbn AS libro_isbn,
                p.fecha_salida,
                p.fecha_limite,
                p.estado
            FROM Prestamo p
            INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            INNER JOIN MaterialBibliografico m ON e.id_material = m.id_material
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
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al obtener tus préstamos: ' . $e->getMessage()], 500);
}
