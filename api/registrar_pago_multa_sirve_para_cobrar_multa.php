<?php
// ARCHIVO: registrar_pago_multa_sirve_para_cobrar_multa.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: registrar que el usuario pagó una multa en ventanilla.
// QUÉ HACE: Recibe el id de la multa y el método (QR o Efectivo). En efectivo, el dinero recibido debe cubrir el monto.
//   Si estaba Pendiente, la marca como Pagado, guarda el método y reactiva la cuenta si ya no debe nada.
//   Responde en JSON con el resultado.
// CONECTA CON: principal/admin.html (cobro de multas); usa conexion_sirve_para_conectar_base_datos.php
/**
 * Registra el cobro de una multa (solo personal) y reactiva la cuenta si ya no debe nada.
 * Entrada JSON: { id_multa, id_usuario }
 */
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

// Solo se acepta POST para cobrar una multa.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

// Lee el JSON con id_multa e id_usuario (este último solo se usa de respaldo).
$entrada   = semsad_leer_json();
$idMulta   = (int)($entrada['id_multa'] ?? 0);
$idUsuario = (int)($entrada['id_usuario'] ?? 0);
$metodo    = (string)($entrada['metodo'] ?? '');
$recibido  = isset($entrada['monto_recibido']) ? round((float)str_replace(',', '.', (string)$entrada['monto_recibido']), 2) : null;

// Validación: el id de la multa es obligatorio.
if ($idMulta <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios (id_multa).']);
}
// Validación: el método debe ser QR (confirmado a mano en la caseta) o Efectivo.
if (!in_array($metodo, ['QR', 'Efectivo'], true)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Elige un método de pago: QR o Efectivo.']);
}

try {
    // Inicia una transacción: el cobro y la posible reactivación se guardan juntos o no se guardan.
    $conexion->beginTransaction();

    // FOR UPDATE bloquea la fila de la multa mientras se cobra, para que no se cobre dos veces a la vez.
    // El dueño real de la multa sale de la base, no de lo que mande la página
    $q = $conexion->prepare(
        'SELECT m.estado_pago, m.monto, p.id_usuario
           FROM multa m LEFT JOIN prestamo p ON p.id_prestamo = m.id_prestamo
          WHERE m.id_multa = :m FOR UPDATE'
    );
    $q->execute([':m' => $idMulta]);
    $multa = $q->fetch(PDO::FETCH_ASSOC);
    // Si la multa no existe, se deshace la transacción y se avisa.
    if (!$multa) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La multa no existe.']);
    }
    // Si la multa ya fue cobrada, no se vuelve a cobrar.
    if ($multa['estado_pago'] !== 'Pendiente') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Esta multa ya fue cobrada.']);
    }
    // Control de caja: si hoy ya se cerró la caja, no se registran más cobros (el cierre no puede cambiar en silencio).
    $cierreHoy = $conexion->query("SELECT 1 FROM cierre_caja WHERE fecha = CURDATE()")->fetchColumn();
    if ($cierreHoy) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La caja de hoy ya fue cerrada. No se pueden registrar cobros después del cierre; si hubo un error, corrige el cierre.']);
    }
    $duenio = (int)($multa['id_usuario'] ?: $idUsuario);
    // El monto sale de la base de datos, no de la página.
    $monto = round((float)$multa['monto'], 2);

    // En efectivo, el dinero recibido tiene que cubrir la multa; si no alcanza, no se registra nada.
    $cambio = 0.0;
    if ($metodo === 'Efectivo') {
        if ($recibido === null || $recibido + 0.0001 < $monto) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'El dinero recibido no alcanza para cubrir la multa de Bs. ' . number_format($monto, 2, ',', '.') . '.']);
        }
        $cambio = round($recibido - $monto, 2);
    }

    // Marca la multa como Pagado y guarda el método usado.
    // Guarda también la fecha, quién cobró y el dinero recibido (para comprobantes y cierre de caja).
    $conexion->prepare("UPDATE multa SET estado_pago = 'Pagado', metodo_pago = :mp, fecha_pago = NOW(), id_cobrador = :c, monto_recibido = :r, cambio = :ch WHERE id_multa = :m")
             ->execute([
                 ':mp' => $metodo,
                 ':c'  => (int)($_SESSION['id_usuario'] ?? 0) ?: null,
                 ':r'  => $metodo === 'Efectivo' ? $recibido : null,
                 ':ch' => $metodo === 'Efectivo' ? $cambio : null,
                 ':m'  => $idMulta,
             ]);

    // Cuenta las multas que todavía debe este usuario.
    $q = $conexion->prepare(
        "SELECT COUNT(*) FROM multa m INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
          WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'"
    );
    $q->execute([':u' => $duenio]);
    $pendientes = (int)$q->fetchColumn();

    $reactivado = false;
    // Si ya no debe ninguna multa, se revisa si la cuenta debe reactivarse.
    if ($pendientes === 0 && $duenio > 0) {
        // Solo se reactivan cuentas bloqueadas/suspendidas por mora, no las desactivadas a mano
        $u = $conexion->prepare("UPDATE usuario SET estado = 'Activo', bloqueo_por_mora = 0 WHERE id_usuario = :u AND estado IN ('Bloqueado', 'Suspendido') AND bloqueo_por_mora = 1");
        $u->execute([':u' => $duenio]);
        $reactivado = $u->rowCount() > 0;
    }

    // Confirma la transacción: se guardan el pago y la reactivación.
    $conexion->commit();
    // Respuesta JSON de éxito. Avisa si la cuenta fue reactivada.
    $detalle = $metodo === 'Efectivo'
        ? sprintf('Efectivo: recibido Bs. %s, cambio Bs. %s', number_format($recibido, 2, ',', '.'), number_format($cambio, 2, ',', '.'))
        : 'QR confirmado por el personal';
    semsad_bitacora('Cobro de multa', 'Multas', "Multa #$idMulta de Bs. " . number_format($monto, 2, ',', '.') . " cobrada ($detalle)" . ($reactivado ? '; cuenta reactivada' : ''), true, $duenio);
    semsad_responder([
        'status'  => 'success',
        'mensaje' => 'Pago de multa registrado correctamente.' . ($reactivado ? ' La cuenta del usuario fue reactivada.' : ''),
        'monto'   => $monto,
        'cambio'  => $cambio,
        'metodo'  => $metodo,
    ]);
} catch (Throwable $e) {
    // Error: si la transacción sigue abierta, se deshacen los cambios.
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // Guarda el error en el log del servidor.
    error_log('[SEMSAD] registrar_pago_multa: ' . $e->getMessage());
    // Responde error 500 con un mensaje genérico.
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar el pago.'], 500);
}
