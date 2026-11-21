<?php
// ARCHIVO: comprobantes_sirve_para_consultar.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: dar los datos de un comprobante de pago de multa o de un comprobante de préstamo.
// QUÉ HACE: GET tipo=pago|prestamo&id=N devuelve un comprobante; GET tipo=...&listar=1 devuelve la lista.
//   El personal ve todos; un estudiante solo ve los suyos.
// CONECTA CON: principal/comprobante.html y la sección Comprobantes de principal/admin.html
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_iniciar_sesion();

if (empty($_SESSION['id_usuario'])) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Debes iniciar sesión.'], 401);
}
$yo      = (int)$_SESSION['id_usuario'];
$esStaff = in_array($_SESSION['rol'] ?? '', ['Administrador', 'Bibliotecario'], true);
$tipo    = $_GET['tipo'] ?? '';
$id      = (int)($_GET['id'] ?? 0);

// Nombre completo de quien aparece en el comprobante (sin datos sensibles).
$nombre = fn($n, $a) => trim(($n ?? '') . ' ' . ($a ?? '')) ?: '—';

try {
    if ($tipo === 'pago') {
        $base = "SELECT m.id_multa, m.monto, m.metodo_pago, m.fecha_pago, m.monto_recibido, m.cambio,
                        p.id_usuario, u.nombres, u.apellidos, u.correo,
                        cob.nombres AS cob_nombres, cob.apellidos AS cob_apellidos,
                        (SELECT mb.titulo FROM detalle_prestamo dp
                           INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
                           INNER JOIN materialbibliografico mb ON mb.id_material = e.id_material
                          WHERE dp.id_prestamo = p.id_prestamo LIMIT 1) AS titulo
                   FROM multa m
                   INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
                   INNER JOIN usuario u ON u.id_usuario = p.id_usuario
                   LEFT JOIN usuario cob ON cob.id_usuario = m.id_cobrador
                  WHERE m.estado_pago = 'Pagado' AND m.fecha_pago IS NOT NULL";

        if ($id > 0) {
            $q = $conexion->prepare($base . ' AND m.id_multa = :id');
            $q->execute([':id' => $id]);
            $f = $q->fetch(PDO::FETCH_ASSOC);
            if (!$f) semsad_responder(['status' => 'error', 'mensaje' => 'No existe ese comprobante de pago.'], 404);
            if (!$esStaff && (int)$f['id_usuario'] !== $yo) semsad_responder(['status' => 'error', 'mensaje' => 'Ese comprobante no es tuyo.'], 403);
            semsad_responder(['status' => 'success', 'comprobante' => [
                'tipo'        => 'pago',
                'codigo'      => 'PAGO-' . str_pad((string)$f['id_multa'], 6, '0', STR_PAD_LEFT),
                'usuario'     => $nombre($f['nombres'], $f['apellidos']),
                'correo'      => $f['correo'],
                'concepto'    => 'Multa por mora' . ($f['titulo'] ? ' · ' . $f['titulo'] : ''),
                'monto'       => (float)$f['monto'],
                'metodo'      => $f['metodo_pago'] ?: '—',
                'fecha'       => $f['fecha_pago'],
                'recibido'    => $f['monto_recibido'] !== null ? (float)$f['monto_recibido'] : null,
                'cambio'      => $f['cambio'] !== null ? (float)$f['cambio'] : null,
                'responsable' => $nombre($f['cob_nombres'], $f['cob_apellidos']),
            ]]);
        }

        $sql = $base . ($esStaff ? '' : ' AND p.id_usuario = :yo') . ' ORDER BY m.fecha_pago DESC LIMIT 200';
        $q = $conexion->prepare($sql);
        $q->execute($esStaff ? [] : [':yo' => $yo]);
        $lista = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $lista[] = [
                'id'      => (int)$f['id_multa'],
                'codigo'  => 'PAGO-' . str_pad((string)$f['id_multa'], 6, '0', STR_PAD_LEFT),
                'usuario' => $nombre($f['nombres'], $f['apellidos']),
                'monto'   => (float)$f['monto'],
                'metodo'  => $f['metodo_pago'] ?: '—',
                'fecha'   => $f['fecha_pago'],
            ];
        }
        semsad_responder(['status' => 'success', 'lista' => $lista]);
    }

    if ($tipo === 'prestamo') {
        // Solo cuenta como entregado: préstamos que ya salieron de la biblioteca (no solicitudes ni cancelados).
        $base = "SELECT p.id_prestamo, p.id_usuario, p.fecha_salida, p.fecha_limite, p.estado,
                        u.nombres, u.apellidos, u.correo,
                        (SELECT mb.titulo FROM detalle_prestamo dp
                           INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
                           INNER JOIN materialbibliografico mb ON mb.id_material = e.id_material
                          WHERE dp.id_prestamo = p.id_prestamo LIMIT 1) AS titulo
                   FROM prestamo p
                   INNER JOIN usuario u ON u.id_usuario = p.id_usuario
                  WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora', 'Devuelto')";

        if ($id > 0) {
            $q = $conexion->prepare($base . ' AND p.id_prestamo = :id');
            $q->execute([':id' => $id]);
            $f = $q->fetch(PDO::FETCH_ASSOC);
            if (!$f) semsad_responder(['status' => 'error', 'mensaje' => 'Ese préstamo no tiene comprobante (aún no fue entregado).'], 404);
            if (!$esStaff && (int)$f['id_usuario'] !== $yo) semsad_responder(['status' => 'error', 'mensaje' => 'Ese comprobante no es tuyo.'], 403);
            semsad_responder(['status' => 'success', 'comprobante' => [
                'tipo'         => 'prestamo',
                'codigo'       => 'PRE-' . str_pad((string)$f['id_prestamo'], 6, '0', STR_PAD_LEFT),
                'usuario'      => $nombre($f['nombres'], $f['apellidos']),
                'correo'       => $f['correo'],
                'libro'        => $f['titulo'] ?: '—',
                'fecha_entrega'=> $f['fecha_salida'],
                'fecha_limite' => $f['fecha_limite'],
                'estado'       => $f['estado'],
            ]]);
        }

        $sql = $base . ($esStaff ? '' : ' AND p.id_usuario = :yo') . ' ORDER BY p.fecha_salida DESC LIMIT 200';
        $q = $conexion->prepare($sql);
        $q->execute($esStaff ? [] : [':yo' => $yo]);
        $lista = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $lista[] = [
                'id'      => (int)$f['id_prestamo'],
                'codigo'  => 'PRE-' . str_pad((string)$f['id_prestamo'], 6, '0', STR_PAD_LEFT),
                'usuario' => $nombre($f['nombres'], $f['apellidos']),
                'libro'   => $f['titulo'] ?: '—',
                'fecha'   => $f['fecha_salida'],
                'estado'  => $f['estado'],
            ];
        }
        semsad_responder(['status' => 'success', 'lista' => $lista]);
    }

    semsad_responder(['status' => 'error', 'mensaje' => 'Tipo de comprobante no válido.'], 400);
} catch (PDOException $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo cargar el comprobante.' . semsad_detalle_error($e)], 500);
}
