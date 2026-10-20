<?php
/**
 * MULTAS Y SANCIONES (solo personal).
 * - Calcula la mora de cada préstamo vencido y guarda/actualiza su multa en la tabla Multa
 *   (regla en ayudas_prestamo.php: SEMSAD_MULTA_POR_DIA, SEMSAD_MULTA_TOPE, SEMSAD_DIAS_BLOQUEO).
 * - Bloquea la cuenta del usuario que pasa de SEMSAD_DIAS_BLOQUEO días de retraso.
 * - Devuelve las multas pendientes con los datos del usuario.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/ayudas_prestamo.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    semsad_marcar_vencidos($conexion);

    $columnas = array_map('strtolower', $conexion->query('SHOW COLUMNS FROM multa')->fetchAll(PDO::FETCH_COLUMN, 0));
    foreach (['id_prestamo', 'monto', 'estado_pago'] as $obligatoria) {
        if (!in_array($obligatoria, $columnas, true)) {
            semsad_responder(['status' => 'error', 'mensaje' => "La tabla Multa no tiene la columna '$obligatoria'."], 500);
        }
    }
    $tieneDetalle = in_array('id_detalle', $columnas, true);
    $tieneTipo    = in_array('tipo_multa', $columnas, true);

    // 1. Préstamos sin devolver y fuera de plazo -> crear o actualizar su multa
    $estadosActivos = "'" . implode("','", SEMSAD_ESTADOS_ACTIVOS) . "'";
    $vencidos = $conexion->query(
        "SELECT p.id_prestamo, p.id_usuario, p.fecha_limite,
                (SELECT MIN(dp.id_detalle) FROM detalle_prestamo dp WHERE dp.id_prestamo = p.id_prestamo) AS id_detalle
           FROM prestamo p
          WHERE p.estado IN ($estadosActivos) AND p.fecha_limite < NOW()"
    )->fetchAll(PDO::FETCH_ASSOC);

    $estadoBloqueo = semsad_valor_enum($conexion, 'usuario', 'estado', ['Bloqueado', 'Suspendido']);

    foreach ($vencidos as $v) {
        $mora = semsad_calcular_mora($v['fecha_limite']);
        if ($mora['dias'] < 1) {
            continue;
        }
        $q = $conexion->prepare('SELECT id_multa, estado_pago FROM multa WHERE id_prestamo = :p ORDER BY id_multa DESC LIMIT 1');
        $q->execute([':p' => $v['id_prestamo']]);
        $existente = $q->fetch(PDO::FETCH_ASSOC);

        if (!$existente) {
            $campos  = ['id_prestamo', 'monto', 'estado_pago'];
            $valores = [':id_prestamo' => $v['id_prestamo'], ':monto' => $mora['monto'], ':estado_pago' => 'Pendiente'];
            if ($tieneDetalle && $v['id_detalle']) { $campos[] = 'id_detalle'; $valores[':id_detalle'] = $v['id_detalle']; }
            if ($tieneTipo)                        { $campos[] = 'tipo_multa'; $valores[':tipo_multa'] = 'Por Mora'; }
            $sql = 'INSERT INTO multa (' . implode(',', $campos) . ') VALUES (' . implode(',', array_keys($valores)) . ')';
            $conexion->prepare($sql)->execute($valores);
            $pendiente = true;
        } elseif ($existente['estado_pago'] === 'Pendiente') {
            $conexion->prepare('UPDATE multa SET monto = :m WHERE id_multa = :id')
                     ->execute([':m' => $mora['monto'], ':id' => $existente['id_multa']]);
            $pendiente = true;
        } else {
            $pendiente = false;   // ya pagada: no se vuelve a cobrar ni a bloquear
        }

        // RF13: más de SEMSAD_DIAS_BLOQUEO días de retraso => cuenta bloqueada
        if ($pendiente && $mora['dias'] > SEMSAD_DIAS_BLOQUEO && $estadoBloqueo !== null) {
            $conexion->prepare("UPDATE usuario SET estado = :e WHERE id_usuario = :u AND estado = 'Activo'")
                     ->execute([':e' => $estadoBloqueo, ':u' => $v['id_usuario']]);
        }
    }

    // 2. Multas pendientes con datos del usuario
    $tipoSql = $tieneTipo ? 'm.tipo_multa' : "'Por Mora'";
    $filas = $conexion->query(
        "SELECT m.id_multa, m.monto, m.estado_pago, $tipoSql AS tipo_multa,
                p.id_usuario, p.fecha_limite, p.estado AS estado_prestamo, p.fecha_devolucion_real,
                u.nombres, u.apellidos, u.correo, u.codigo_empleado, u.estado AS estado_usuario
           FROM multa m
      LEFT JOIN prestamo p ON p.id_prestamo = m.id_prestamo
      LEFT JOIN usuario u ON u.id_usuario = p.id_usuario
          WHERE m.estado_pago = 'Pendiente'
       ORDER BY m.id_multa DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $datos = [];
    foreach ($filas as $f) {
        $sinDevolver = in_array($f['estado_prestamo'], SEMSAD_ESTADOS_ACTIVOS, true);
        $dias = $sinDevolver
            ? semsad_calcular_mora($f['fecha_limite'])['dias']
            : (int)round(((float)$f['monto']) / SEMSAD_MULTA_POR_DIA);
        $tipo = (string)($f['tipo_multa'] ?? 'Por Mora');
        if ($dias > 0) {
            $tipo .= ' · ' . $dias . ($dias === 1 ? ' día' : ' días');
        }
        $datos[] = [
            'id_multa'        => (int)$f['id_multa'],
            'monto'           => (float)$f['monto'],
            'estado_pago'     => $f['estado_pago'],
            'tipo_multa'      => $tipo,
            'dias_mora'       => $dias,
            'id_usuario'      => (int)$f['id_usuario'],
            'nombres'         => $f['nombres'] ?? ('⚠ Usuario no encontrado (ID ' . (int)$f['id_usuario'] . ')'),
            'apellidos'       => $f['apellidos'] ?? '',
            'correo'          => $f['correo'] ?? '',
            'codigo_empleado' => $f['codigo_empleado'] ?? '',
            'estado_usuario'  => $f['estado_usuario'] ?? 'Activo',
            'fecha_limite'    => $f['fecha_limite'] ? date('Y-m-d', strtotime($f['fecha_limite'])) : 'N/A',
        ];
    }

    semsad_responder(['status' => 'success', 'data' => $datos]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_multas: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'message' => !empty($cfg['debug']) ? 'Error: ' . $e->getMessage() : 'No se pudo cargar el módulo de multas.',
    ], 500);
}
