<?php
// Biblioteca SEMSAD: libro de pruebas académico, separado de cobros auténticos.
// GET ?listar=1 solo Administrador/Bibliotecario; GET ?id=... propio estudiante o personal.
// POST registra una simulación del usuario de sesión. Nunca marca multas como Pagado,
// nunca desbloquea usuarios ni modifica caja, estadísticas o préstamos reales.
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/usuario_actual_sirve_para_identificar_quien_pide.php';
semsad_iniciar_sesion();
$yo = semsad_usuario_actual($conexion);
if (!$yo) {
    semsad_responder(['status'=>'error','codigo'=>'sin_sesion','mensaje'=>'Inicia sesión para acceder a las pruebas.'],401);
}
$esPersonal = in_array((string)($_SESSION['rol'] ?? ''), ['Administrador','Bibliotecario'], true);
if (!semsad_tiene_tabla($conexion, 'pago_demo') || !semsad_tiene_tabla($conexion, 'pago_demo_detalle')) {
    semsad_responder([
        'status'=>'error','codigo'=>'migracion_pendiente',
        'mensaje'=>'El registro compartido de pagos DEMO requiere ejecutar db/migracion_pagos_demo.sql en AlwaysData.'
    ],503);
}
$usuarioId = (int)$yo['id_usuario'];
$metodoHttp = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($metodoHttp === 'GET') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    try {
        if ($id !== null && $id !== false && $id > 0) {
            $sql = "SELECT d.id_pago_demo AS id, d.codigo, d.id_usuario, d.metodo, d.monto,
                       d.estado, d.fecha, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario
                      FROM pago_demo d JOIN usuario u ON u.id_usuario=d.id_usuario
                     WHERE d.id_pago_demo=:id";
            $q = $conexion->prepare($sql);
            $q->execute([':id'=>$id]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p || (!$esPersonal && (int)$p['id_usuario'] !== $usuarioId)) {
                semsad_responder(['status'=>'error','mensaje'=>'Comprobante no encontrado.'],404);
            }
            $d = $conexion->prepare('SELECT id_multa, titulo, monto FROM pago_demo_detalle WHERE id_pago_demo=:id ORDER BY id_pago_demo_detalle');
            $d->execute([':id'=>$id]);
            $p['detalle'] = $d->fetchAll(PDO::FETCH_ASSOC);
            semsad_responder(['status'=>'success','comprobante'=>$p]);
        }
        if (isset($_GET['listar']) && $_GET['listar'] === '1' && !$esPersonal) {
            semsad_responder(['status'=>'error','mensaje'=>'Solo el personal puede consultar todos los pagos DEMO.'],403);
        }
        $sql = "SELECT d.id_pago_demo AS id, d.codigo, d.id_usuario, d.metodo, d.monto,
                    d.estado, d.fecha, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario,
                    (SELECT GROUP_CONCAT(x.titulo ORDER BY x.id_pago_demo_detalle SEPARATOR ', ')
                       FROM pago_demo_detalle x WHERE x.id_pago_demo=d.id_pago_demo) AS libros
                   FROM pago_demo d JOIN usuario u ON u.id_usuario=d.id_usuario";
        if (!$esPersonal) $sql .= ' WHERE d.id_usuario=:u';
        $sql .= ' ORDER BY d.id_pago_demo DESC LIMIT 200';
        $q=$conexion->prepare($sql);
        $q->execute($esPersonal ? [] : [':u'=>$usuarioId]);
        semsad_responder(['status'=>'success','lista'=>$q->fetchAll(PDO::FETCH_ASSOC),'demo'=>true]);
    } catch (Throwable $e) {
        error_log('[SEMSAD] listar pagos DEMO: '.$e->getMessage());
        semsad_responder(['status'=>'error','mensaje'=>'No se pudieron consultar las operaciones de prueba.'],500);
    }
}
if ($metodoHttp !== 'POST') {
    semsad_responder(['status'=>'error','mensaje'=>'Método no permitido.'],405);
}
if ($esPersonal) {
    semsad_responder(['status'=>'error','mensaje'=>'Los pagos de demostración se registran desde una sesión de estudiante.'],403);
}
$entrada=semsad_leer_json();
$codigo=(string)($entrada['codigo'] ?? '');
$metodo=(string)($entrada['metodo'] ?? '');
$ids=$entrada['multa_ids'] ?? null;
if (!preg_match('/^DEMO-[A-Za-z0-9-]{6,55}$/D', $codigo)
    || !in_array($metodo, ['VISA · DEMO','MASTERCARD · DEMO','AMEX · DEMO','PayPal · DEMO'], true)
    || !is_array($ids) || count($ids)<1 || count($ids)>15) {
    semsad_responder(['status'=>'error','mensaje'=>'Datos de simulación inválidos.'],400);
}
$ids=array_map('intval',$ids);
if (count(array_unique($ids)) !== count($ids) || in_array(0,$ids,true) ||
    (in_array(-1,$ids,true) && count($ids)!==1) ||
    count(array_filter($ids, fn($n)=>$n<0 && $n!==-1))>0) {
    semsad_responder(['status'=>'error','mensaje'=>'Los conceptos de prueba no son válidos.'],400);
}
try {
    $conexion->beginTransaction();
    $ya=$conexion->prepare('SELECT id_pago_demo, id_usuario FROM pago_demo WHERE codigo=:c LIMIT 1 FOR UPDATE');
    $ya->execute([':c'=>$codigo]);
    $existente=$ya->fetch(PDO::FETCH_ASSOC);
    if ($existente) {
        $conexion->rollBack();
        if ((int)$existente['id_usuario']!==$usuarioId) {
            semsad_responder(['status'=>'error','mensaje'=>'El identificador pertenece a otra operación.'],409);
        }
        semsad_responder(['status'=>'success','id'=>(int)$existente['id_pago_demo'],'ya_existe'=>true,'demo'=>true]);
    }
    $lineas=[]; $total=0.0;
    foreach ($ids as $idMulta) {
        if ($idMulta===-1) {
            $q=$conexion->prepare("SELECT COUNT(*) FROM multa m JOIN prestamo p ON p.id_prestamo=m.id_prestamo WHERE p.id_usuario=:u AND m.estado_pago='Pendiente'");
            $q->execute([':u'=>$usuarioId]);
            if ((int)$q->fetchColumn() > 0) {
                $conexion->rollBack();
                semsad_responder(['status'=>'error','mensaje'=>'La multa ficticia solo se usa cuando no hay multas pendientes.'],400);
            }
            $lineas[]=['id'=>-1,'titulo'=>'Multa ficticia de presentación','monto'=>120.00];
            $total+=120.00;
            continue;
        }
        $q=$conexion->prepare(
            "SELECT m.id_multa, m.monto, m.estado_pago, p.id_usuario,
                COALESCE((SELECT MIN(mb.titulo) FROM detalle_prestamo dp
                  JOIN ejemplar e ON e.id_ejemplar=dp.id_ejemplar
                  JOIN materialbibliografico mb ON mb.id_material=e.id_material
                  WHERE dp.id_prestamo=p.id_prestamo), CONCAT('Préstamo #',p.id_prestamo)) AS titulo
               FROM multa m JOIN prestamo p ON p.id_prestamo=m.id_prestamo
              WHERE m.id_multa=:id FOR UPDATE"
        );
        $q->execute([':id'=>$idMulta]); $m=$q->fetch(PDO::FETCH_ASSOC);
        if (!$m || (int)$m['id_usuario']!==$usuarioId || $m['estado_pago']!=='Pendiente') {
            $conexion->rollBack();
            semsad_responder(['status'=>'error','mensaje'=>'Hay multas que no pertenecen al usuario o ya no están pendientes.'],409);
        }
        $monto=round((float)$m['monto'],2);
        $lineas[]=['id'=>$idMulta,'titulo'=>substr((string)$m['titulo'],0,255),'monto'=>$monto];
        $total+=$monto;
    }
    $total=round($total,2);
    if ($total<=0) {
        $conexion->rollBack(); semsad_responder(['status'=>'error','mensaje'=>'No hay importe válido para la prueba.'],400);
    }
    $nuevo=$conexion->prepare("INSERT INTO pago_demo (codigo,id_usuario,metodo,monto,estado,fecha) VALUES (:c,:u,:m,:t,'SIMULADO',NOW())");
    $nuevo->execute([':c'=>$codigo,':u'=>$usuarioId,':m'=>$metodo,':t'=>$total]);
    $pagoId=(int)$conexion->lastInsertId();
    $det=$conexion->prepare("INSERT INTO pago_demo_detalle (id_pago_demo,id_usuario,id_multa,titulo,monto) VALUES (:p,:u,:i,:t,:m)");
    foreach ($lineas as $l) $det->execute([':p'=>$pagoId,':u'=>$usuarioId,':i'=>$l['id'],':t'=>$l['titulo'],':m'=>$l['monto']]);
    $conexion->commit();
    semsad_responder(['status'=>'success','id'=>$pagoId,'codigo'=>$codigo,'demo'=>true,'monto'=>$total]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    if ($e instanceof PDOException && $e->getCode()==='23000') {
        semsad_responder(['status'=>'error','codigo'=>'duplicado_demo','mensaje'=>'Esta multa ya tiene una operación registrada en el historial DEMO.'],409);
    }
    error_log('[SEMSAD] registrar pago DEMO: '.$e->getMessage());
    semsad_responder(['status'=>'error','mensaje'=>'No se pudo guardar la demostración.'],500);
}
