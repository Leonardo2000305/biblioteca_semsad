<?php
/**
 * DIAGNÓSTICO (solo personal): lista los préstamos pendientes y dice si cada uno está "completo".
 * Abrir con la sesión de admin iniciada:  /backend/diagnostico_prestamos.php
 * No modifica nada.
 */
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $filas = $conexion->query(
        "SELECT p.id_prestamo, p.id_usuario, p.estado, p.fecha_limite,
                u.correo AS usuario_correo,
                dp.id_ejemplar, e.id_material, e.estado_ejemplar, m.titulo
           FROM Prestamo p
      LEFT JOIN Usuario u ON u.id_usuario = p.id_usuario
      LEFT JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
      LEFT JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
      LEFT JOIN MaterialBibliografico m ON m.id_material = e.id_material
          WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora')
       ORDER BY p.id_prestamo DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $salida = [];
    foreach ($filas as $f) {
        $problemas = [];
        if (empty($f['usuario_correo']))   $problemas[] = 'el usuario no existe';
        if (empty($f['id_ejemplar']))      $problemas[] = 'no tiene ejemplar asociado';
        elseif (empty($f['estado_ejemplar'])) $problemas[] = 'el ejemplar no existe';
        elseif (empty($f['titulo']))       $problemas[] = 'el libro del ejemplar no existe';
        $f['problemas'] = $problemas;
        $f['completo']  = !$problemas;
        $salida[] = $f;
    }

    $ejemplares = $conexion->query(
        "SELECT e.id_material, m.titulo, e.estado_ejemplar, COUNT(*) AS cantidad
           FROM Ejemplar e LEFT JOIN MaterialBibliografico m ON m.id_material = e.id_material
       GROUP BY e.id_material, m.titulo, e.estado_ejemplar ORDER BY e.id_material"
    )->fetchAll(PDO::FETCH_ASSOC);

    semsad_responder([
        'status'     => 'success',
        'pendientes' => count($salida),
        'incompletos'=> count(array_filter($salida, fn($x) => !$x['completo'])),
        'prestamos'  => $salida,
        'ejemplares' => $ejemplares,
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] diagnostico_prestamos: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo generar el diagnóstico.'], 500);
}
