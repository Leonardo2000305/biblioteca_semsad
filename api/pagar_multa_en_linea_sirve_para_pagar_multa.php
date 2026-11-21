<?php
// ARCHIVO: pagar_multa_en_linea_sirve_para_pagar_multa.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: registrar el pago en línea de una multa del propio estudiante.
// QUÉ HACE: Solo la sesión identifica al usuario, y solo puede pagar SUS multas pendientes.
//   Recibe el id de la multa, el método ('tarjeta' o 'paypal') y, para tarjeta, solo la marca (VISA, Mastercard...)
//   y los últimos 4 dígitos. NUNCA recibe ni guarda el número completo, el vencimiento ni el CVV.
//   Mientras $cfg['pagos_demo'] sea true, el cobro es SIMULADO: no se mueve dinero real y el método queda marcado como demostración.
//   Para cobrar de verdad hay que conectar un proveedor de pagos (Stripe, PayPal) con sus claves; no se hace aquí.
// CONECTA CON: principal/pagar_multa.html.
require_once __DIR__ . '/usuario_actual_sirve_para_identificar_quien_pide.php';
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_iniciar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$yo = semsad_usuario_actual($conexion);
if (!$yo) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}

$entrada  = semsad_leer_json();
$idMulta  = (int)($entrada['id_multa'] ?? 0);
$metodo   = (string)($entrada['metodo'] ?? '');
$marca    = substr(preg_replace('/[^A-Za-z ]/', '', (string)($entrada['marca'] ?? '')), 0, 20);
$ultimos4 = preg_replace('/\D/', '', (string)($entrada['ultimos4'] ?? ''));
$ultimos4 = strlen($ultimos4) === 4 ? $ultimos4 : '';

if ($idMulta <= 0 || !in_array($metodo, ['tarjeta', 'paypal'], true)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Elige un método de pago válido.']);
}
if ($metodo === 'tarjeta' && $ultimos4 === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan los datos de la tarjeta.']);
}
// Pago en línea: solo se acepta con una pasarela real conectada (pasarela_real = true y pagos_demo = false).
// La demostración NO liquida multas ni desbloquea cuentas: una simulación no puede cobrar dinero.
if (empty($cfg['pasarela_real']) || !empty($cfg['pagos_demo'])) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El pago en línea no está disponible. Paga en ventanilla con QR o efectivo.'], 503);
}

try {
    $conexion->beginTransaction();
    // El dueño de la multa sale de la base: solo se puede pagar una multa propia.
    $q = $conexion->prepare(
        'SELECT m.id_multa, m.monto, m.estado_pago, p.id_usuario
           FROM multa m INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
          WHERE m.id_multa = :m FOR UPDATE'
    );
    $q->execute([':m' => $idMulta]);
    $m = $q->fetch(PDO::FETCH_ASSOC);

    if (!$m || (int)$m['id_usuario'] !== (int)$yo['id_usuario']) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Esa multa no está en tu cuenta.'], 403);
    }
    if ($m['estado_pago'] !== 'Pendiente') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Esta multa ya fue pagada.']);
    }

    $etiqueta = $metodo === 'paypal'
        ? 'Demostración · PayPal'
        : 'Demostración · ' . ($marca !== '' ? $marca : 'Tarjeta') . ' ****' . $ultimos4;
    $conexion->prepare("UPDATE multa SET estado_pago = 'Pagado', metodo_pago = :mp WHERE id_multa = :m")
             ->execute([':mp' => substr($etiqueta, 0, 40), ':m' => $idMulta]);

    // Si ya no debe nada, la cuenta bloqueada por mora vuelve a activarse (igual que en ventanilla).
    $q = $conexion->prepare(
        "SELECT COUNT(*) FROM multa m INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
          WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'"
    );
    $q->execute([':u' => (int)$yo['id_usuario']]);
    $reactivado = false;
    if ((int)$q->fetchColumn() === 0) {
        $u = $conexion->prepare("UPDATE usuario SET estado = 'Activo', bloqueo_por_mora = 0 WHERE id_usuario = :u AND estado IN ('Bloqueado', 'Suspendido') AND bloqueo_por_mora = 1");
        $u->execute([':u' => (int)$yo['id_usuario']]);
        $reactivado = $u->rowCount() > 0;
    }

    semsad_bitacora('Pago de multa en línea', 'Multas', "Multa #$idMulta pagada por $etiqueta" . ($reactivado ? '; cuenta reactivada' : ''), false, (int)$yo['id_usuario'], $yo['correo'] ?? null);
    $conexion->commit();

    semsad_responder([
        'status'     => 'success',
        'mensaje'    => 'Pago registrado (modo demostración: no se cobró dinero real).' . ($reactivado ? ' Tu cuenta fue reactivada.' : ''),
        'comprobante'=> 'SEM-' . str_pad((string)$idMulta, 6, '0', STR_PAD_LEFT) . '-' . date('His'),
        'metodo'     => $etiqueta,
        'monto'      => round((float)$m['monto'], 2),
    ]);
} catch (PDOException $e) {
    if ($conexion->inTransaction()) { $conexion->rollBack(); }
    error_log('[SEMSAD] pagar_multa_en_linea: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar el pago.' . semsad_detalle_error($e)], 500);
}
