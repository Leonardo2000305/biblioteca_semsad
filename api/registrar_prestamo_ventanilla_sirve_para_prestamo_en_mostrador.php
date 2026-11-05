<?php
// ARCHIVO: registrar_prestamo_ventanilla_sirve_para_prestamo_en_mostrador.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: que el bibliotecario preste un libro en mostrador (ventanilla) a un usuario.
// QUÉ HACE: Recibe el usuario, el libro y el tipo (Domicilio: 7 días; Sala: hay que devolverlo hoy). Revisa las reglas, crea el préstamo y marca la copia como Prestado.
//   Responde en JSON con el resultado.
// CONECTA CON: principal/admin.html (préstamo en ventanilla); usa reglas_prestamos_sirve_para_multas_y_plazos.php y conexion_sirve_para_conectar_base_datos.php
/**
 * PRÉSTAMO EN VENTANILLA (solo personal): el bibliotecario registra el préstamo de un libro a un usuario.
 * Entrada JSON: { id_usuario, id_material, tipo: "Domicilio" | "Sala" }
 *  - Domicilio: 7 días.  - Sala: debe devolverse el mismo día.
 * Reglas: usuario activo, sin multas pendientes, máximo 5 préstamos, sin repetir el mismo libro, con copia disponible.
 */
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

// Solo se acepta POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

// Lee el JSON con id_usuario, id_material y tipo.
$entrada    = semsad_leer_json();
$idUsuario  = (int)($entrada['id_usuario'] ?? 0);
$idMaterial = (int)($entrada['id_material'] ?? 0);
// Tipo de préstamo: 'Sala' si se indica; en cualquier otro caso queda 'Domicilio'.
$tipo       = ($entrada['tipo'] ?? 'Domicilio') === 'Sala' ? 'Sala' : 'Domicilio';

// Validación: deben venir el usuario y el libro.
if ($idUsuario <= 0 || $idMaterial <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Selecciona el usuario y el libro.']);
}

try {
    // Busca el usuario y su rol en la base.
    $q = $conexion->prepare('SELECT u.nombres, u.apellidos, u.estado, r.nombre AS rol FROM usuario u LEFT JOIN rol r ON r.id_rol = u.id_rol WHERE u.id_usuario = :u LIMIT 1');
    $q->execute([':u' => $idUsuario]);
    $usuario = $q->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El usuario no existe.']);
    }
    $nombre = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);

    // Regla: el personal no puede recibir préstamos.
    if (in_array($usuario['rol'], ['Administrador', 'Bibliotecario'], true)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Las cuentas del personal no piden libros prestados.']);
    }
    // Regla: la cuenta debe estar Activa.
    if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
        semsad_responder(['status' => 'error', 'mensaje' => "La cuenta de $nombre está {$usuario['estado']}. No puede recibir préstamos."]);
    }

    // Regla: no se presta si el usuario tiene multas pendientes.
    try {
        $q = $conexion->prepare("SELECT COUNT(*) FROM multa m INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'");
        $q->execute([':u' => $idUsuario]);
        if ((int)$q->fetchColumn() > 0) {
            semsad_responder(['status' => 'error', 'mensaje' => "$nombre tiene multas pendientes. Debe cancelarlas antes de recibir otro libro."]);
        }
    } catch (PDOException $ignorar) { /* sin tabla Multa */ }

    $activos = "'" . implode("','", SEMSAD_ESTADOS_ACTIVOS) . "'";

    // Primero se actualizan los turnos de la lista de espera; luego se revisa si este usuario puede llevarse el libro.
    semsad_caducar_reservas($conexion);
    $motivoTurno = semsad_turno_permite($conexion, $idUsuario, $idMaterial);
    if ($motivoTurno !== null) {
        semsad_responder(['status' => 'error', 'mensaje' => $motivoTurno]);
    }

    // Regla: no se presta dos veces el mismo libro a la misma persona.
    $q = $conexion->prepare("SELECT COUNT(*) FROM prestamo p INNER JOIN detalle_prestamo dp ON dp.id_prestamo = p.id_prestamo INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ($activos)");
    $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
    if ((int)$q->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => "$nombre ya tiene este libro en préstamo."]);
    }

    // Regla: máximo 5 préstamos activos por usuario.
    $q = $conexion->prepare("SELECT COUNT(*) FROM prestamo WHERE id_usuario = :u AND estado IN ($activos)");
    $q->execute([':u' => $idUsuario]);
    if ((int)$q->fetchColumn() >= 5) {
        semsad_responder(['status' => 'error', 'mensaje' => "$nombre ya tiene el límite de 5 préstamos activos."]);
    }

    // Transacción: se bloquea una copia libre (FOR UPDATE) para no prestarla a dos personas a la vez.
    $conexion->beginTransaction();
    $q = $conexion->prepare("SELECT e.id_ejemplar, m.titulo FROM ejemplar e INNER JOIN materialbibliografico m ON m.id_material = e.id_material WHERE e.id_material = :m AND " . semsad_sql_ejemplar_libre('e') . " LIMIT 1 FOR UPDATE");
    $q->execute([':m' => $idMaterial]);
    $ejemplar = $q->fetch(PDO::FETCH_ASSOC);
    // Si no hay copias libres, se cancela la transacción y se avisa.
    if (!$ejemplar) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No hay copias disponibles de ese libro.']);
    }

    // Fechas: la Sala vence hoy a las 23:59; el Domicilio vence dentro de 7 días.
    $salida = date('Y-m-d H:i:s');
    $limite = $tipo === 'Sala' ? date('Y-m-d 23:59:59') : date('Y-m-d H:i:s', strtotime('+7 days'));

    // Guarda el préstamo. El bibliotecario que entrega sale de la sesión del personal.
    $conexion->prepare("INSERT INTO prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) VALUES (:u, :b, :s, :l, 'Activo')")
             ->execute([':u' => $idUsuario, ':b' => (int)$_SESSION['id_usuario'], ':s' => $salida, ':l' => $limite]);
    $idPrestamo = (int)$conexion->lastInsertId();
    // Guarda qué copia se prestó y la marca como Prestado.
    $conexion->prepare('INSERT INTO detalle_prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)')->execute([':p' => $idPrestamo, ':e' => $ejemplar['id_ejemplar']]);
    $conexion->prepare("UPDATE ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :e")->execute([':e' => $ejemplar['id_ejemplar']]);
    // Confirma la transacción.
    $conexion->commit();
    // Si el usuario tenía reserva de este libro, la marca como atendida.
    semsad_atender_reserva($conexion, $idUsuario, $idMaterial);

    $hasta = $tipo === 'Sala' ? 'hoy (préstamo en sala)' : 'el ' . date('d/m/Y', strtotime($limite)) . ' (7 días)';
    // Respuesta JSON de éxito con el mensaje y el id del préstamo.
    semsad_responder([
        'status'      => 'success',
        'mensaje'     => "Préstamo de \"{$ejemplar['titulo']}\" registrado a nombre de $nombre. Debe devolverlo $hasta.",
        'id_prestamo' => $idPrestamo,
    ]);
} catch (Throwable $e) {
    // Error: si la transacción sigue abierta, se deshacen los cambios.
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // Guarda el error en el log del servidor.
    error_log('[SEMSAD] registrar_prestamo_ventanilla: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar el préstamo.'], 500);
}
