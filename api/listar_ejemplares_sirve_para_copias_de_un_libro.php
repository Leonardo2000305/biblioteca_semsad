<?php
// ARCHIVO: listar_ejemplares_sirve_para_copias_de_un_libro.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Mostrar en la pantalla de administración las copias (ejemplares) de un libro, con su estado y si están prestadas.
// QUÉ HACE: Solo personal. Recibe por GET el id del libro (?id_material=N). Si falta o no es válido, responde error.
// Si es válido, devuelve cada copia con su estado, su condición física y si está prestada. Responde un JSON.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de administración de libros (revisar qué archivo HTML la llama).
/**
 * Lista los ejemplares (copias) de un material para la pantalla de administración.
 * GET ?id_material=N  →  { status, ejemplares: [{id_ejemplar, estado_ejemplar, condicion_fisica, prestado}] }
 * Solo personal de la biblioteca.
 */
require_once 'conexion_sirve_para_conectar_base_datos.php';
// Solo personal de la biblioteca puede ver las copias.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

// Lee el id del libro desde la URL y lo convierte a número.
$idMaterial = (int)($_GET['id_material'] ?? 0);
// Validación: sin un id válido no se hace ninguna consulta.
if ($idMaterial <= 0) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Indique el material.']);
    exit;
}

try {
    // Consulta de las copias del libro. La columna prestado es verdadera si la copia tiene un préstamo Activo o Con Mora. Revisar: no cuenta los préstamos Vencidos.
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
    // Responde con la lista de copias.
    echo json_encode(['status' => 'success', 'ejemplares' => $ejemplares]);
} catch (PDOException $e) {
    // Si la consulta falla, se guarda el error en el log y se responde con un mensaje sencillo.
    error_log('listar_ejemplares: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudieron cargar los ejemplares.']);
}
