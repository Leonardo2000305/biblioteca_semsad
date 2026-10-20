<?php
/**
 * Registra el cobro de una multa (solo personal) y reactiva la cuenta si ya no debe nada.
 * Entrada JSON: { id_multa, id_usuario }
 */
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada   = semsad_leer_json();
$idMulta   = (int)($entrada['id_multa'] ?? 0);
$idUsuario = (int)($entrada['id_usuario'] ?? 0);

if ($idMulta <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios (id_multa).']);
}

try {
    $conexion->beginTransaction();

    // El dueño real de la multa sale de la base, no de lo que mande la página
    $q = $conexion->prepare(
        'SELECT m.estado_pago, p.id_usuario
           FROM multa m LEFT JOIN prestamo p ON p.id_prestamo = m.id_prestamo
          WHERE m.id_multa = :m FOR UPDATE'
    );
    $q->execute([':m' => $idMulta]);
    $multa = $q->fetch(PDO::FETCH_ASSOC);
    if (!$multa) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La multa no existe.']);
    }
    if ($multa['estado_pago'] !== 'Pendiente') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Esta multa ya fue cobrada.']);
    }
    $duenio = (int)($multa['id_usuario'] ?: $idUsuario);

    $conexion->prepare("UPDATE multa SET estado_pago = 'Pagado' WHERE id_multa = :m")->execute([':m' => $idMulta]);

    $q = $conexion->prepare(
        "SELECT COUNT(*) FROM multa m INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
          WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'"
    );
    $q->execute([':u' => $duenio]);
    $pendientes = (int)$q->fetchColumn();

    $reactivado = false;
    if ($pendientes === 0 && $duenio > 0) {
        // Solo se reactivan cuentas bloqueadas/suspendidas por mora, no las desactivadas a mano
        $u = $conexion->prepare("UPDATE usuario SET estado = 'Activo' WHERE id_usuario = :u AND estado IN ('Bloqueado', 'Suspendido')");
        $u->execute([':u' => $duenio]);
        $reactivado = $u->rowCount() > 0;
    }

    $conexion->commit();
    semsad_responder([
        'status'  => 'success',
        'mensaje' => 'Pago de multa registrado correctamente.' . ($reactivado ? ' La cuenta del usuario fue reactivada.' : ''),
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('[SEMSAD] registrar_pago_multa: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar el pago.'], 500);
}
