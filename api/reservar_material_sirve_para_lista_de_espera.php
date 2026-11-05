<?php
// ARCHIVO: reservar_material_sirve_para_lista_de_espera.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: anotar a un estudiante o docente en la lista de espera de un libro sin copias libres, o sacarlo de esa lista.
// QUÉ HACE: Si accion es 'cancelar', marca su reserva como Cancelada. Si no, revisa que pueda reservar y le asigna el siguiente turno.
//   Responde en JSON con el resultado.
// CONECTA CON: principal/index.html (botones de lista de espera); usa conexion_sirve_para_conectar_base_datos.php y reglas_prestamos_sirve_para_multas_y_plazos.php
/**
 * LISTA DE ESPERA (estudiante/docente con sesión): reservar un libro que no tiene copias disponibles.
 * Entrada JSON: { id_material, accion?: "cancelar" }
 * Usa la tabla Reserva (estado Pendiente, prioridad = turno).
 */
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
semsad_iniciar_sesion();

// Solo se acepta POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
// Quién reserva: solo la sesión del servidor.
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
// Sin sesión: se responde 401 para que la página pida iniciar sesión.
if ($idUsuario <= 0) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}
// Lee el JSON: el id del libro y, si se envía, la acción (por ejemplo 'cancelar').
$entrada    = semsad_leer_json();
$idMaterial = (int)($entrada['id_material'] ?? 0);
// Validación: el libro debe tener un id válido.
if ($idMaterial <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Libro no válido.']);
}

semsad_caducar_reservas($conexion);   // aplica la hora de corte antes de asignar turnos

try {
    // Acción 'cancelar': el usuario sale de la lista de espera de este libro.
    if (($entrada['accion'] ?? '') === 'cancelar') {
        $q = $conexion->prepare("UPDATE reserva SET estado = 'Cancelada' WHERE id_usuario = :u AND id_material = :m AND estado = 'Pendiente'");
        $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
        semsad_responder(['status' => 'success', 'mensaje' => 'Saliste de la lista de espera.']);
    }

    // Busca el estado de la cuenta y su rol.
    $q = $conexion->prepare('SELECT u.estado, r.nombre AS rol FROM usuario u LEFT JOIN rol r ON r.id_rol = u.id_rol WHERE u.id_usuario = :u');
    $q->execute([':u' => $idUsuario]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    // Permisos: solo estudiantes y docentes pueden reservar; el personal no.
    if (!$u || in_array($u['rol'], ['Administrador', 'Bibliotecario'], true)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Solo estudiantes y docentes pueden reservar libros.']);
    }
    // Regla: la cuenta debe estar Activa.
    if (($u['estado'] ?? 'Activo') !== 'Activo') {
        semsad_responder(['status' => 'error', 'mensaje' => 'Tu cuenta no está activa. Contacta a la biblioteca.']);
    }

    // Verifica que el libro exista en el catálogo.
    $q = $conexion->prepare('SELECT titulo FROM materialbibliografico WHERE id_material = :m');
    $q->execute([':m' => $idMaterial]);
    $titulo = $q->fetchColumn();
    if (!$titulo) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Ese libro no existe en el catálogo.']);
    }

    $activos = "'" . implode("','", SEMSAD_ESTADOS_ACTIVOS) . "'";
    // Regla: si ya tiene este libro en préstamo, no puede reservarlo.
    $q = $conexion->prepare("SELECT COUNT(*) FROM prestamo p INNER JOIN detalle_prestamo dp ON dp.id_prestamo = p.id_prestamo INNER JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ($activos)");
    $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
    if ((int)$q->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Ya tienes este libro en tu poder.']);
    }

    // Regla: si hay copias libres, no hace falta reservar: se pide como préstamo normal.
    $q = $conexion->prepare("SELECT COUNT(*) FROM ejemplar e WHERE e.id_material = :m AND " . semsad_sql_ejemplar_libre('e'));
    $q->execute([':m' => $idMaterial]);
    if ((int)$q->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Este libro tiene copias disponibles: solicítalo directamente como préstamo.']);
    }

    // Transacción: se bloquea la reserva del usuario (FOR UPDATE) para no crear dos turnos por error.
    $conexion->beginTransaction();
    $q = $conexion->prepare("SELECT prioridad FROM reserva WHERE id_usuario = :u AND id_material = :m AND estado = 'Pendiente' LIMIT 1 FOR UPDATE");
    $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
    $turno = $q->fetchColumn();
    // Si ya tiene turno en la lista, no se crea otro: solo se informa su número.
    if ($turno !== false) {
        $conexion->rollBack();
        semsad_responder(['status' => 'success', 'mensaje' => "Ya estás en la lista de espera de \"$titulo\" (turno $turno)."]);
    }

    // Nuevo turno: el mayor número de turno de la lista más 1.
    $q = $conexion->prepare("SELECT COALESCE(MAX(prioridad), 0) + 1 FROM reserva WHERE id_material = :m AND estado = 'Pendiente'");
    $q->execute([':m' => $idMaterial]);
    $turno = (int)$q->fetchColumn();
    // Guarda la reserva con estado Pendiente y el turno asignado.
    $conexion->prepare("INSERT INTO reserva (id_usuario, id_material, estado, prioridad) VALUES (:u, :m, 'Pendiente', :p)")
             ->execute([':u' => $idUsuario, ':m' => $idMaterial, ':p' => $turno]);
    $conexion->commit();

    // Respuesta JSON de éxito con el turno asignado.
    semsad_responder(['status' => 'success', 'mensaje' => "Te anotamos en la lista de espera de \"$titulo\". Tu turno es el $turno."]);
} catch (Throwable $e) {
    // Error: si la transacción sigue abierta, se deshacen los cambios.
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // Guarda el error en el log del servidor.
    error_log('[SEMSAD] reservar_material: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar la reserva.'], 500);
}
