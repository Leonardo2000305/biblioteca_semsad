<?php
// ARCHIVO: avisos_sirve_para_vencimiento_y_correo.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: listar los préstamos que vencen pronto o ya vencieron, y enviarles un recordatorio por correo.
// QUÉ HACE:
//   GET  -> préstamos entregados con fecha límite en los próximos 2 días (proximo) o ya pasada (vencido).
//   POST {ids:[...], reenviar:false} -> envía el correo a esos préstamos. Marca cada envío en aviso_enviado_at
//          para no duplicar; un aviso ya enviado se omite salvo que se pida reenviar.
// CONECTA CON: principal/admin.html (sección Avisos de vencimiento)
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
// $cfg (remitente de correo) viene de conexion_sirve_para_conectar_base_datos.php.

$filas = function (PDO $c, string $extra = '', array $par = []) {
    $sql = "SELECT p.id_prestamo, p.fecha_salida, p.fecha_limite, p.estado, p.aviso_enviado_at,
                   u.nombres, u.apellidos, u.correo,
                   (SELECT GROUP_CONCAT(DISTINCT mb.titulo SEPARATOR ', ') FROM detalle_prestamo dp
                      INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
                      INNER JOIN materialbibliografico mb ON mb.id_material = e.id_material
                     WHERE dp.id_prestamo = p.id_prestamo) AS titulo,
                   DATEDIFF(p.fecha_limite, NOW()) AS dias
              FROM prestamo p INNER JOIN usuario u ON u.id_usuario = p.id_usuario
             WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora') $extra";
    $q = $c->prepare($sql);
    $q->execute($par);
    return $q->fetchAll(PDO::FETCH_ASSOC);
};

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $proximos = $filas($conexion, 'AND p.fecha_limite >= NOW() AND p.fecha_limite <= NOW() + INTERVAL 2 DAY ORDER BY p.fecha_limite');
        $vencidos = $filas($conexion, 'AND p.fecha_limite < NOW() ORDER BY p.fecha_limite');
        $normalizar = fn($grupo) => fn($f) => [
            'id'            => (int)$f['id_prestamo'],
            'usuario'       => trim($f['nombres'] . ' ' . $f['apellidos']),
            'correo'        => $f['correo'],
            'libro'         => $f['titulo'] ?: '—',
            'fecha_salida'  => $f['fecha_salida'],
            'fecha_limite'  => $f['fecha_limite'],
            'estado'        => $f['estado'],
            'dias'          => (int)$f['dias'],
            'grupo'         => $grupo,
            'aviso_enviado' => $f['aviso_enviado_at'],
        ];
        semsad_responder([
            'status'   => 'success',
            'proximos' => array_map($normalizar('proximo'), $proximos),
            'vencidos' => array_map($normalizar('vencido'), $vencidos),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
    }

    $in = semsad_leer_json();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])), fn($v) => $v > 0)));
    $reenviar = !empty($in['reenviar']);
    if (!$ids) semsad_responder(['status' => 'error', 'mensaje' => 'Selecciona al menos un préstamo.']);
    if (count($ids) > 200) semsad_responder(['status' => 'error', 'mensaje' => 'Envía como máximo 200 avisos a la vez.']);

    $enviados = 0; $omitidos = 0; $fallidos = 0;
    foreach ($ids as $id) {
        $r = $filas($conexion, 'AND p.id_prestamo = :id', [':id' => $id]);
        if (!$r) { $omitidos++; continue; }
        $f = $r[0];
        if (empty($f['correo'])) { $omitidos++; continue; }
        if ($f['aviso_enviado_at'] && !$reenviar) { $omitidos++; continue; }

        $nombre = htmlspecialchars(trim($f['nombres'] . ' ' . $f['apellidos']), ENT_QUOTES, 'UTF-8');
        $libro  = htmlspecialchars($f['titulo'] ?: 'tu libro', ENT_QUOTES, 'UTF-8');
        $limite = date('d/m/Y', strtotime($f['fecha_limite']));
        $vencido = (int)$f['dias'] < 0 || $f['estado'] !== 'Activo';
        $asunto = $vencido ? 'Tu préstamo está vencido - Biblioteca SEMSAD' : 'Recordatorio: devolución de libro - Biblioteca SEMSAD';
        $mensaje = $vencido
            ? "Te recordamos que el plazo para devolver <b>$libro</b> venció el <b>$limite</b>. Por favor acércate a la biblioteca para devolverlo; la mora sigue generando multa."
            : "Te recordamos que debes devolver <b>$libro</b> a más tardar el <b>$limite</b>. Si necesitas más tiempo, acércate a la biblioteca antes de esa fecha.";
        $cuerpo = "<html><body style='font-family:Arial,sans-serif;color:#1e293b'>"
                . "<h2 style='color:#1d4ed8'>Biblioteca SEMSAD</h2>"
                . "<p>Hola $nombre,</p><p>$mensaje</p>"
                . "<p style='color:#64748b;font-size:12px'>Este es un aviso automático; no respondas a este correo.</p></body></html>";
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                 . 'From: Biblioteca SEMSAD <' . $cfg['mail_from'] . ">\r\n"
                 . 'X-Mailer: PHP/' . phpversion();

        $ok = @mail($f['correo'], '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $headers);
        if (!$ok) { $fallidos++; error_log('[SEMSAD] aviso de vencimiento no enviado a ' . $f['correo']); continue; }

        $conexion->prepare("UPDATE prestamo SET aviso_enviado_at = NOW() WHERE id_prestamo = :id")->execute([':id' => $id]);
        $enviados++;
    }

    semsad_bitacora('Aviso de vencimiento', 'Préstamos', "Avisos por correo: $enviados enviados, $omitidos omitidos, $fallidos sin envío", false, (int)($_SESSION['id_usuario'] ?? 0) ?: null);
    $msg = "$enviados aviso(s) enviado(s)." . ($omitidos ? " $omitidos omitido(s) (ya enviados o sin correo)." : '') . ($fallidos ? " $fallidos no se pudieron enviar: revisa la configuración de correo del servidor." : '');
    semsad_responder(['status' => $fallidos && !$enviados ? 'error' : 'success', 'mensaje' => $msg, 'enviados' => $enviados, 'omitidos' => $omitidos, 'fallidos' => $fallidos]);
} catch (Throwable $e) {
    error_log('[SEMSAD] avisos: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron procesar los avisos.' . semsad_detalle_error($e)], 500);
}
