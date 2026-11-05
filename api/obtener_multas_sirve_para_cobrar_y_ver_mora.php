<?php
// ARCHIVO: obtener_multas_sirve_para_cobrar_y_ver_mora.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: mostrar al personal las multas pendientes y calcular los días de retraso.
// QUÉ HACE: Actualiza los préstamos vencidos, crea o actualiza la multa de cada uno y bloquea la cuenta si el retraso es grande.
//   Responde en JSON con las multas pendientes y los datos del usuario.
// CONECTA CON: principal/admin.html (pantalla de multas); usa reglas_prestamos_sirve_para_multas_y_plazos.php y conexion_sirve_para_conectar_base_datos.php
/**
 * MULTAS Y SANCIONES (solo personal).
 * - Calcula la mora de cada préstamo vencido y guarda/actualiza su multa en la tabla Multa
 *   (regla en reglas_prestamos_sirve_para_multas_y_plazos.php: SEMSAD_MULTA_POR_DIA, SEMSAD_MULTA_TOPE, SEMSAD_DIAS_BLOQUEO).
 * - Bloquea la cuenta del usuario que pasa de SEMSAD_DIAS_BLOQUEO días de retraso.
 * - Devuelve las multas pendientes con los datos del usuario.
 */
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
// Permisos: solo Administrador o Bibliotecario pueden ver las multas.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    // Antes de contar multas, se actualizan los préstamos que ya pasaron su fecha límite.
    semsad_marcar_vencidos($conexion);

    // Revisa que la tabla Multa tenga las columnas necesarias; si falta alguna, responde error.
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
          WHERE p.estado IN ($estadosActivos) AND p.fecha_limite < NOW() - INTERVAL " . (int)SEMSAD_DIAS_GRACIA . " DAY"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Busca el nombre de estado que la base acepta para bloquear una cuenta ('Bloqueado' o 'Suspendido').
    $estadoBloqueo = semsad_valor_enum($conexion, 'usuario', 'estado', ['Bloqueado', 'Suspendido']);

    foreach ($vencidos as $v) {
        $mora = semsad_calcular_mora($v['fecha_limite']);
        // Si todavía no hay días completos de retraso, no se crea multa.
        if ($mora['dias'] < 1) {
            continue;
        }
        // Busca la multa más reciente de este préstamo, si ya existe.
        $q = $conexion->prepare('SELECT id_multa, estado_pago FROM multa WHERE id_prestamo = :p ORDER BY id_multa DESC LIMIT 1');
        $q->execute([':p' => $v['id_prestamo']]);
        $existente = $q->fetch(PDO::FETCH_ASSOC);

        // No hay multa todavía: se crea una nueva, Pendiente, con el monto calculado.
        if (!$existente) {
            $campos  = ['id_prestamo', 'monto', 'estado_pago'];
            $valores = [':id_prestamo' => $v['id_prestamo'], ':monto' => $mora['monto'], ':estado_pago' => 'Pendiente'];
            if ($tieneDetalle && $v['id_detalle']) { $campos[] = 'id_detalle'; $valores[':id_detalle'] = $v['id_detalle']; }
            if ($tieneTipo)                        { $campos[] = 'tipo_multa'; $valores[':tipo_multa'] = 'Por Mora'; }
            $sql = 'INSERT INTO multa (' . implode(',', $campos) . ') VALUES (' . implode(',', array_keys($valores)) . ')';
            $conexion->prepare($sql)->execute($valores);
            $pendiente = true;
        // Ya existe y sigue sin pagarse: se actualiza su monto (sube cada día de retraso).
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
    // Si la tabla no tiene la columna tipo_multa, el tipo queda fijo como 'Por Mora'.
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
        // Si el libro sigue sin devolver, los días se calculan con la fecha de hoy; si ya se devolvió, se deducen del monto.
        $sinDevolver = in_array($f['estado_prestamo'], SEMSAD_ESTADOS_ACTIVOS, true);
        $dias = $sinDevolver
            ? semsad_calcular_mora($f['fecha_limite'])['dias']
            : (int)round(((float)$f['monto']) / SEMSAD_MULTA_POR_DIA);
        // Texto del tipo de multa con los días, por ejemplo 'Por Mora · 3 días'.
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

    // Respuesta JSON de éxito con la lista de multas pendientes.
    semsad_responder(['status' => 'success', 'data' => $datos]);
} catch (Throwable $e) {
    // Guarda el error en el registro (log) del servidor para revisarlo después.
    error_log('[SEMSAD] obtener_multas: ' . $e->getMessage());
    // Responde error 500 con la clave 'message' (en inglés; los demás archivos usan 'mensaje'). El detalle técnico solo se muestra si 'debug' está en true.
    semsad_responder([
        'status'  => 'error',
        'message' => !empty($cfg['debug']) ? 'Error: ' . $e->getMessage() : 'No se pudo cargar el módulo de multas.',
    ], 500);
}
