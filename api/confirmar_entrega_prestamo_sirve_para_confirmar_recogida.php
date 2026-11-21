<?php
// ARCHIVO: confirmar_entrega_prestamo_sirve_para_confirmar_recogida.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: que el personal confirme que el libro SÍ fue entregado al estudiante.
// QUÉ HACE: Solo personal. Pasa la solicitud de 'Solicitado' a 'Activo', empieza el plazo de 7 días desde hoy,
//   marca las copias como Prestadas y registra qué bibliotecario confirmó la entrega.
// CONECTA CON: principal/admin.html (ventanilla: Confirmar entrega).
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
$entrada = semsad_leer_json();
$idPrestamo = (int)($entrada['id_prestamo'] ?? 0);
if ($idPrestamo <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Falta el préstamo a confirmar.']);
}

try {
    $conexion->beginTransaction();
    $q = $conexion->prepare(
        'SELECT p.id_prestamo, p.id_usuario, p.estado, u.estado AS estado_usuario, u.correo,
                TRIM(CONCAT_WS(" ", u.nombres, u.apellidos)) AS nombre
           FROM prestamo p LEFT JOIN usuario u ON u.id_usuario = p.id_usuario
          WHERE p.id_prestamo = :p FOR UPDATE'
    );
    $q->execute([':p' => $idPrestamo]);
    $p = $q->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La solicitud no existe.']);
    }
    if ($p['estado'] !== 'Solicitado') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Este préstamo ya fue confirmado o cancelado.']);
    }
    // Regla: no se entrega un libro a una cuenta que no esté activa.
    if (($p['estado_usuario'] ?? 'Activo') !== 'Activo') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La cuenta de ' . ($p['nombre'] ?: 'este usuario') . ' no está activa. Cancela la solicitud o reactiva la cuenta.']);
    }

    $ahora  = date('Y-m-d H:i:s');
    $limite = date('Y-m-d H:i:s', strtotime('+7 days'));
    $idBibliotecario = (int)($_SESSION['id_usuario'] ?? 0) ?: null;
    $conexion->prepare(
        "UPDATE prestamo SET estado = 'Activo', fecha_salida = :s, fecha_limite = :l, id_bibliotecario_entrega = :b WHERE id_prestamo = :p"
    )->execute([':s' => $ahora, ':l' => $limite, ':b' => $idBibliotecario, ':p' => $idPrestamo]);

    $conexion->prepare(
        "UPDATE ejemplar e INNER JOIN detalle_prestamo dp ON dp.id_ejemplar = e.id_ejemplar
            SET e.estado_ejemplar = 'Prestado'
          WHERE dp.id_prestamo = :p"
    )->execute([':p' => $idPrestamo]);

    semsad_bitacora('Confirmación de entrega', 'Préstamos', "Entrega confirmada del préstamo #$idPrestamo a " . ($p['nombre'] ?: $p['correo']) . ' (plazo hasta ' . date('d/m/Y', strtotime($limite)) . ')', false, (int)$p['id_usuario'], $p['correo'] ?? null);

    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => 'Entrega confirmada. El préstamo quedó activo hasta el ' . date('d/m/Y', strtotime($limite)) . '.']);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) { $conexion->rollBack(); }
    error_log('[SEMSAD] confirmar_entrega: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo confirmar la entrega.' . semsad_detalle_error($e)], 500);
}
