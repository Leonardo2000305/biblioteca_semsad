<?php
/**
 * Lista los ejemplares (copias) de un material para la pantalla de administración.
 * GET ?id_material=N  →  { status, ejemplares: [{id_ejemplar, estado_ejemplar, condicion_fisica, prestado}] }
 * Solo personal de la biblioteca.
 */
require_once 'conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

$idMaterial = (int)($_GET['id_material'] ?? 0);
if ($idMaterial <= 0) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Indique el material.']);
    exit;
}

try {
    $stmt = $conexion->prepare("
        SELECT e.id_ejemplar, e.estado_ejemplar, e.condicion_fisica,
               EXISTS (
                   SELECT 1 FROM detalle_prestamo dp
                   INNER JOIN prestamo p ON p.id_prestamo = dp.id_prestamo
                   WHERE dp.id_ejemplar = e.id_ejemplar AND p.estado IN ('Activo', 'Con Mora')
               ) AS prestado
        FROM ejemplar e
        WHERE e.id_material = :m
        ORDER BY e.id_ejemplar");
    $stmt->execute([':m' => $idMaterial]);
    $ejemplares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'ejemplares' => $ejemplares]);
} catch (PDOException $e) {
    error_log('listar_ejemplares: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudieron cargar los ejemplares.']);
}
