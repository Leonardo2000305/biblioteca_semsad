<?php
// ARCHIVO: caja_sirve_para_cierre_diario.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: controlar el dinero cobrado por multas cada día y hacer el cierre de caja.
// QUÉ HACE:
//   GET ?fecha=AAAA-MM-DD            -> cobros del día, totales por QR y efectivo y el cierre (si existe).
//   GET ?listar=1                    -> últimos cierres.
//   POST {accion:'cerrar', fecha, efectivo_fisico}           -> registra el cierre del día (una sola vez).
//   POST {accion:'corregir', id_cierre, efectivo_fisico, motivo} -> corrige el efectivo contado; queda auditado.
//   Los totales los calcula el servidor. El QR nunca cuenta como efectivo físico.
// CONECTA CON: principal/admin.html (sección Cierre de caja)
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
$yo = (int)($_SESSION['id_usuario'] ?? 0);

function fechaValida(?string $f): bool {
    return is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && checkdate((int)substr($f, 5, 2), (int)substr($f, 8, 2), (int)substr($f, 0, 4));
}
// Una fecha de caja no puede ser futura: se compara con la fecha de hoy de la base (zona horaria de La Paz).
function fechaCajaNoFutura(PDO $c, string $f): bool {
    $hoy = (string)$c->query('SELECT CURDATE()')->fetchColumn();
    return $f <= $hoy;
}
function importeValido($v): ?float {
    if ($v === null || $v === '') return null;
    $n = str_replace(',', '.', (string)$v);
    if (!is_numeric($n) || (float)$n < 0) return null;
    return round((float)$n, 2);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['listar'])) {
            $q = $conexion->query("SELECT c.id_cierre, c.fecha, c.total_general, c.total_qr, c.total_efectivo, c.efectivo_fisico, c.diferencia,
                                          c.fecha_registro, c.motivo_correccion, c.fecha_correccion,
                                          r.nombres, r.apellidos
                                     FROM cierre_caja c LEFT JOIN usuario r ON r.id_usuario = c.id_responsable
                                    ORDER BY c.fecha DESC LIMIT 60");
            semsad_responder(['status' => 'success', 'cierres' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        }

        $fecha = $_GET['fecha'] ?? date('Y-m-d');
        if (!fechaValida($fecha)) semsad_responder(['status' => 'error', 'mensaje' => 'Fecha no válida.'], 400);
        if (!fechaCajaNoFutura($conexion, $fecha)) semsad_responder(['status' => 'error', 'mensaje' => 'No se puede cerrar ni consultar la caja de una fecha futura.'], 400);

        // Cobros del día: solo multas pagadas con fecha de cobro.
        $q = $conexion->prepare("SELECT m.id_multa, m.monto, m.metodo_pago, m.fecha_pago, m.monto_recibido, m.cambio,
                                        u.nombres, u.apellidos, cob.nombres AS cob_nombres, cob.apellidos AS cob_apellidos,
                                        (SELECT mb.titulo FROM detalle_prestamo dp
                                           INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
                                           INNER JOIN materialbibliografico mb ON mb.id_material = e.id_material
                                          WHERE dp.id_prestamo = p.id_prestamo LIMIT 1) AS titulo
                                   FROM multa m
                                   INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
                                   INNER JOIN usuario u ON u.id_usuario = p.id_usuario
                                   LEFT JOIN usuario cob ON cob.id_usuario = m.id_cobrador
                                  WHERE m.estado_pago = 'Pagado' AND DATE(m.fecha_pago) = :f
                                  ORDER BY m.fecha_pago");
        $q->execute([':f' => $fecha]);
        $ops = []; $tQR = 0.0; $tEf = 0.0; $tOtros = 0.0; $nQR = 0; $nEf = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $monto = (float)$o['monto'];
            if ($o['metodo_pago'] === 'QR')           { $tQR += $monto; $nQR++; }
            elseif ($o['metodo_pago'] === 'Efectivo') { $tEf += $monto; $nEf++; }
            else                                      { $tOtros += $monto; }
            $ops[] = [
                'id'          => (int)$o['id_multa'],
                'hora'        => substr((string)$o['fecha_pago'], 11, 5),
                'usuario'     => trim($o['nombres'] . ' ' . $o['apellidos']),
                'concepto'    => 'Multa' . ($o['titulo'] ? ' · ' . $o['titulo'] : ''),
                'metodo'      => $o['metodo_pago'] ?: '—',
                'monto'       => $monto,
                'responsable' => trim(($o['cob_nombres'] ?? '') . ' ' . ($o['cob_apellidos'] ?? '')) ?: '—',
            ];
        }

        $c = $conexion->prepare("SELECT c.*, r.nombres, r.apellidos FROM cierre_caja c LEFT JOIN usuario r ON r.id_usuario = c.id_responsable WHERE c.fecha = :f");
        $c->execute([':f' => $fecha]);
        $cierre = $c->fetch(PDO::FETCH_ASSOC) ?: null;

        semsad_responder([
            'status'          => 'success',
            'fecha'           => $fecha,
            'operaciones'     => $ops,
            'total_qr'        => round($tQR, 2),
            'total_efectivo'  => round($tEf, 2),
            'total_otros'     => round($tOtros, 2),
            'total_general'   => round($tQR + $tEf + $tOtros, 2),
            'num_qr'          => $nQR,
            'num_efectivo'    => $nEf,
            'cierre'          => $cierre,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
    }

    $in = semsad_leer_json();
    $accion = $in['accion'] ?? '';
    $fisico = importeValido($in['efectivo_fisico'] ?? null);
    if ($fisico === null) semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa un importe válido de efectivo contado.']);

    if ($accion === 'cerrar') {
        $fecha = $in['fecha'] ?? '';
        if (!fechaValida($fecha)) semsad_responder(['status' => 'error', 'mensaje' => 'Fecha no válida.'], 400);
        if (!fechaCajaNoFutura($conexion, $fecha)) semsad_responder(['status' => 'error', 'mensaje' => 'No se puede cerrar ni consultar la caja de una fecha futura.'], 400);

        $conexion->beginTransaction();
        // Un solo cierre por día: se revisa dentro de la transacción y la clave única lo garantiza.
        $ya = $conexion->prepare("SELECT id_cierre FROM cierre_caja WHERE fecha = :f FOR UPDATE");
        $ya->execute([':f' => $fecha]);
        if ($ya->fetchColumn()) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'Ese día ya tiene cierre. Usa "Corregir" si hay un error.']);
        }

        // Totales calculados por el servidor a partir de los cobros del día.
        $t = $conexion->prepare("SELECT
                COALESCE(SUM(CASE WHEN metodo_pago = 'QR' THEN monto END), 0) AS qr,
                COALESCE(SUM(CASE WHEN metodo_pago = 'Efectivo' THEN monto END), 0) AS ef,
                COALESCE(SUM(CASE WHEN metodo_pago IS NULL OR metodo_pago NOT IN ('QR','Efectivo') THEN monto END), 0) AS otros,
                COALESCE(SUM(monto), 0) AS total
            FROM multa WHERE estado_pago = 'Pagado' AND DATE(fecha_pago) = :f");
        $t->execute([':f' => $fecha]);
        $tot = $t->fetch(PDO::FETCH_ASSOC);
        $ef = round((float)$tot['ef'], 2);
        $dif = round($fisico - $ef, 2);

        $ins = $conexion->prepare("INSERT INTO cierre_caja (fecha, id_responsable, total_qr, total_efectivo, total_otros, total_general, efectivo_fisico, diferencia, fecha_registro)
                                   VALUES (:f, :r, :qr, :ef, :ot, :tg, :fis, :dif, NOW())");
        $ins->execute([':f' => $fecha, ':r' => $yo ?: null, ':qr' => round((float)$tot['qr'], 2), ':ef' => $ef,
                       ':ot' => round((float)$tot['otros'], 2), ':tg' => round((float)$tot['total'], 2), ':fis' => $fisico, ':dif' => $dif]);
        $conexion->commit();

        $estado = $dif == 0 ? 'cuadra' : ($dif < 0 ? 'faltante de Bs. ' . number_format(-$dif, 2, ',', '.') : 'sobrante de Bs. ' . number_format($dif, 2, ',', '.'));
        semsad_bitacora('Cierre de caja', 'Caja', "Cierre del $fecha: efectivo esperado Bs. " . number_format($ef, 2, ',', '.') . ", contado Bs. " . number_format($fisico, 2, ',', '.') . " ($estado)", true, $yo ?: null);
        semsad_responder(['status' => 'success', 'mensaje' => 'Cierre registrado: ' . $estado . '.', 'diferencia' => $dif]);
    }

    if ($accion === 'corregir') {
        $idCierre = (int)($in['id_cierre'] ?? 0);
        $motivo = trim((string)($in['motivo'] ?? ''));
        if ($idCierre <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Falta el cierre a corregir.']);
        if (mb_strlen($motivo) < 5) semsad_responder(['status' => 'error', 'mensaje' => 'Escribe el motivo de la corrección (mínimo 5 letras).']);

        $conexion->beginTransaction();
        $q = $conexion->prepare("SELECT fecha, total_efectivo, efectivo_fisico, diferencia FROM cierre_caja WHERE id_cierre = :i FOR UPDATE");
        $q->execute([':i' => $idCierre]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if (!$c) { $conexion->rollBack(); semsad_responder(['status' => 'error', 'mensaje' => 'El cierre no existe.'], 404); }

        $dif = round($fisico - (float)$c['total_efectivo'], 2);
        $conexion->prepare("UPDATE cierre_caja SET efectivo_fisico = :fis, diferencia = :dif, motivo_correccion = :m, fecha_correccion = NOW(), id_corrector = :u WHERE id_cierre = :i")
                 ->execute([':fis' => $fisico, ':dif' => $dif, ':m' => mb_substr($motivo, 0, 255), ':u' => $yo ?: null, ':i' => $idCierre]);
        $conexion->commit();

        // La corrección queda en la bitácora con el valor anterior y el nuevo.
        semsad_bitacora('Corrección de cierre de caja', 'Caja',
            "Cierre del {$c['fecha']}: efectivo contado de Bs. " . number_format((float)$c['efectivo_fisico'], 2, ',', '.') . " a Bs. " . number_format($fisico, 2, ',', '.') . ". Motivo: $motivo",
            true, $yo ?: null);
        semsad_responder(['status' => 'success', 'mensaje' => 'Cierre corregido y auditado.', 'diferencia' => $dif]);
    }

    semsad_responder(['status' => 'error', 'mensaje' => 'Acción no válida.'], 400);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] caja: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo completar la operación de caja.' . semsad_detalle_error($e)], 500);
}
