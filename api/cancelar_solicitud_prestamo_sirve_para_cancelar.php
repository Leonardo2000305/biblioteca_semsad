<?php
// ARCHIVO: cancelar_solicitud_prestamo_sirve_para_cancelar.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: cancelar una solicitud de préstamo que todavía no se entregó.
// QUÉ HACE: Puede cancelarla el propio estudiante (si cambió de opinión) o el personal (Administrador o Bibliotecario).
//   Solo cancela préstamos en estado 'Solicitado'; las copias reservadas vuelven a quedar Disponibles.
// CONECTA CON: principal/index.html (historial: Cancelar solicitud) y principal/admin.html (ventanilla: Cancelar).
require_once __DIR__ . '/usuario_actual_sirve_para_identificar_quien_pide.php';
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_iniciar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$idPrestamo = (int)($entrada['id_prestamo'] ?? 0);
if ($idPrestamo <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Falta el préstamo a cancelar.']);
}

// Quién pide la cancelación: solo la sesión del servidor.
$yo = semsad_usuario_actual($conexion);
if (!$yo) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}
$esPersonal = in_array($_SESSION['rol'] ?? '', ['Administrador', 'Bibliotecario'], true);

try {
    $conexion->beginTransaction();
    $q = $conexion->prepare('SELECT id_prestamo, id_usuario, estado FROM prestamo WHERE id_prestamo = :p FOR UPDATE');
    $q->execute([':p' => $idPrestamo]);
    $p = $q->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La solicitud no existe.']);
    }
    // Un estudiante solo puede cancelar lo suyo; el personal puede cancelar cualquier solicitud.
    if (!$esPersonal && (int)$p['id_usuario'] !== (int)$yo['id_usuario']) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Esa solicitud no es de tu cuenta.'], 403);
    }
    if ($p['estado'] !== 'Solicitado') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Solo se pueden cancelar solicitudes que aún no se entregaron.']);
    }

    $conexion->prepare("UPDATE prestamo SET estado = 'Cancelado' WHERE id_prestamo = :p")->execute([':p' => $idPrestamo]);
    // Las copias reservadas por esta solicitud vuelven a quedar disponibles.
    $conexion->prepare(
        "UPDATE ejemplar e INNER JOIN detalle_prestamo dp ON dp.id_ejemplar = e.id_ejemplar
            SET e.estado_ejemplar = 'Disponible'
          WHERE dp.id_prestamo = :p AND e.estado_ejemplar = 'Reservado'"
    )->execute([':p' => $idPrestamo]);

    $quien = $esPersonal ? 'el personal' : 'el estudiante';
    semsad_bitacora('Cancelación de solicitud', 'Préstamos', "Solicitud #$idPrestamo cancelada por $quien", $esPersonal, $yo['id_usuario'], $yo['correo'] ?? null);

    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => 'Solicitud cancelada. El libro volvió a quedar disponible.']);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) { $conexion->rollBack(); }
    error_log('[SEMSAD] cancelar_solicitud: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo cancelar la solicitud.' . semsad_detalle_error($e)], 500);
}
