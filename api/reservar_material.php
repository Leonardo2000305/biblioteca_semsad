<?php
/**
 * LISTA DE ESPERA (estudiante/docente con sesión): reservar un libro que no tiene copias disponibles.
 * Entrada JSON: { id_material, accion?: "cancelar" }
 * Usa la tabla Reserva (estado Pendiente, prioridad = turno).
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/ayudas_prestamo.php';
semsad_iniciar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($idUsuario <= 0) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}
$entrada    = semsad_leer_json();
$idMaterial = (int)($entrada['id_material'] ?? 0);
if ($idMaterial <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Libro no válido.']);
}

try {
    if (($entrada['accion'] ?? '') === 'cancelar') {
        $q = $conexion->prepare("UPDATE Reserva SET estado = 'Cancelada' WHERE id_usuario = :u AND id_material = :m AND estado = 'Pendiente'");
        $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
        semsad_responder(['status' => 'success', 'mensaje' => 'Saliste de la lista de espera.']);
    }

    $q = $conexion->prepare('SELECT u.estado, r.nombre AS rol FROM usuario u LEFT JOIN rol r ON r.id_rol = u.id_rol WHERE u.id_usuario = :u');
    $q->execute([':u' => $idUsuario]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u || in_array($u['rol'], ['Administrador', 'Bibliotecario'], true)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Solo estudiantes y docentes pueden reservar libros.']);
    }
    if (($u['estado'] ?? 'Activo') !== 'Activo') {
        semsad_responder(['status' => 'error', 'mensaje' => 'Tu cuenta no está activa. Contacta a la biblioteca.']);
    }

    $q = $conexion->prepare('SELECT titulo FROM MaterialBibliografico WHERE id_material = :m');
    $q->execute([':m' => $idMaterial]);
    $titulo = $q->fetchColumn();
    if (!$titulo) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Ese libro no existe en el catálogo.']);
    }

    $activos = "'" . implode("','", SEMSAD_ESTADOS_ACTIVOS) . "'";
    $q = $conexion->prepare("SELECT COUNT(*) FROM Prestamo p INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ($activos)");
    $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
    if ((int)$q->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Ya tienes este libro en tu poder.']);
    }

    $q = $conexion->prepare("SELECT COUNT(*) FROM Ejemplar e WHERE e.id_material = :m AND " . semsad_sql_ejemplar_libre('e'));
    $q->execute([':m' => $idMaterial]);
    if ((int)$q->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Este libro tiene copias disponibles: solicítalo directamente como préstamo.']);
    }

    $conexion->beginTransaction();
    $q = $conexion->prepare("SELECT prioridad FROM Reserva WHERE id_usuario = :u AND id_material = :m AND estado = 'Pendiente' LIMIT 1 FOR UPDATE");
    $q->execute([':u' => $idUsuario, ':m' => $idMaterial]);
    $turno = $q->fetchColumn();
    if ($turno !== false) {
        $conexion->rollBack();
        semsad_responder(['status' => 'success', 'mensaje' => "Ya estás en la lista de espera de \"$titulo\" (turno $turno)."]);
    }

    $q = $conexion->prepare("SELECT COALESCE(MAX(prioridad), 0) + 1 FROM Reserva WHERE id_material = :m AND estado = 'Pendiente'");
    $q->execute([':m' => $idMaterial]);
    $turno = (int)$q->fetchColumn();
    $conexion->prepare("INSERT INTO Reserva (id_usuario, id_material, estado, prioridad) VALUES (:u, :m, 'Pendiente', :p)")
             ->execute([':u' => $idUsuario, ':m' => $idMaterial, ':p' => $turno]);
    $conexion->commit();

    semsad_responder(['status' => 'success', 'mensaje' => "Te anotamos en la lista de espera de \"$titulo\". Tu turno es el $turno."]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    error_log('[SEMSAD] reservar_material: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar la reserva.'], 500);
}
