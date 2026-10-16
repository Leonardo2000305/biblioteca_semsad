<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.'], 405);
}

$input = semsad_leer_json();
$id_material = (int)($input['id_material'] ?? $input['id_libro'] ?? $_POST['id_material'] ?? $_POST['id_libro'] ?? 0);

// La única identidad válida es la sesión autenticada por el servidor.
// El ID/correo que llega desde el navegador se ignora deliberadamente.
$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Tu sesión ha expirado. Inicia sesión nuevamente para solicitar un préstamo.'], 401);
}
if ($id_material <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Identificador de libro no válido.'], 400);
}

try {
    $stmtUsuario = $conexion->prepare("
        SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.estado, u.id_rol, r.nombre AS rol
        FROM Usuario u
        LEFT JOIN Rol r ON r.id_rol = u.id_rol
        WHERE u.id_usuario = :id
        LIMIT 1
    ");
    $stmtUsuario->execute([':id' => $id_usuario]);
    $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        semsad_responder(['status' => 'error', 'mensaje' => 'No se encontró la cuenta de tu sesión. Cierra sesión e inicia nuevamente.'], 401);
    }
    if (($usuario['estado'] ?? '') !== 'Activo') {
        semsad_responder(['status' => 'error', 'mensaje' => 'Tu cuenta no está activa. Contacta a la administración.'], 403);
    }

    $rol = (string)($usuario['rol'] ?? '');
    if (in_array($rol, ['Administrador', 'Bibliotecario'], true)
        || in_array((int)($usuario['id_rol'] ?? 0), [1, 2], true)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Las cuentas administrativas no pueden solicitar préstamos. Inicia sesión con una cuenta de estudiante o docente.'], 403);
    }

    $nombreCompleto = trim(($usuario['nombres'] ?? '') . ' ' . ($usuario['apellidos'] ?? ''));
    if ($nombreCompleto === '') {
        $nombreCompleto = (string)$usuario['correo'];
    }

    $stmtMaterial = $conexion->prepare("SELECT id_material FROM MaterialBibliografico WHERE id_material = :m LIMIT 1");
    $stmtMaterial->execute([':m' => $id_material]);
    if (!$stmtMaterial->fetchColumn()) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El libro seleccionado no existe en el catálogo.'], 404);
    }

    // Las cuentas con multas pendientes o préstamos en mora no pueden crear préstamos nuevos.
    $stmtMora = $conexion->prepare("
        SELECT COUNT(*)
        FROM Multa m
        INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
    ");
    $stmtMora->execute([':u' => $id_usuario]);
    if ((int)$stmtMora->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Tienes multas pendientes. Regulariza tu cuenta antes de solicitar otro préstamo.'], 403);
    }

    $stmtYaPrestado = $conexion->prepare("
        SELECT COUNT(DISTINCT p.id_prestamo)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
        INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
        WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ('Activo', 'Con Mora')
    ");
    $stmtYaPrestado->execute([':u' => $id_usuario, ':m' => $id_material]);
    if ((int)$stmtYaPrestado->fetchColumn() > 0) {
        semsad_responder(['status' => 'error', 'mensaje' => "$nombreCompleto, ya tienes un préstamo activo de este libro."], 409);
    }

    $stmtCount = $conexion->prepare("
        SELECT COUNT(DISTINCT p.id_prestamo)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
        WHERE p.id_usuario = :u AND p.estado IN ('Activo', 'Con Mora')
    ");
    $stmtCount->execute([':u' => $id_usuario]);
    if ((int)$stmtCount->fetchColumn() >= 5) {
        semsad_responder(['status' => 'error', 'mensaje' => "$nombreCompleto, ya tienes el límite máximo de 5 préstamos activos."], 409);
    }

    $conexion->beginTransaction();

    $stmtCheck = $conexion->prepare("
        SELECT id_ejemplar
        FROM Ejemplar
        WHERE id_material = :m AND estado_ejemplar = 'Disponible'
        ORDER BY id_ejemplar
        LIMIT 1
        FOR UPDATE
    ");
    $stmtCheck->execute([':m' => $id_material]);
    $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$ejemplar) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No hay copias disponibles. Puedes registrarte en la lista de espera si el sistema habilita reservas.'], 409);
    }

    $id_ejemplar = (int)$ejemplar['id_ejemplar'];
    // Se conserva la asignación de bibliotecario utilizada en la versión actual del sistema.
    $id_bibliotecario = 1;
    $dias_prestamo = 7;
    $fecha_salida = date('Y-m-d H:i:s');
    $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

    $stmtP = $conexion->prepare("
        INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado)
        VALUES (:u, :b, :salida, :limite, 'Activo')
    ");
    $stmtP->execute([
        ':u' => $id_usuario,
        ':b' => $id_bibliotecario,
        ':salida' => $fecha_salida,
        ':limite' => $fecha_limite
    ]);
    $id_prestamo = (int)$conexion->lastInsertId();

    $stmtD = $conexion->prepare("INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)");
    $stmtD->execute([':p' => $id_prestamo, ':e' => $id_ejemplar]);

    $stmtE = $conexion->prepare("
        UPDATE Ejemplar
        SET estado_ejemplar = 'Prestado'
        WHERE id_ejemplar = :e AND estado_ejemplar = 'Disponible'
    ");
    $stmtE->execute([':e' => $id_ejemplar]);
    if ($stmtE->rowCount() !== 1) {
        throw new RuntimeException('El ejemplar dejó de estar disponible.');
    }

    $conexion->commit();
    $fecha_fmt = date('d/m/Y', strtotime($fecha_limite));

    semsad_responder([
        'status' => 'success',
        'mensaje' => "¡Préstamo registrado exitosamente a nombre de $nombreCompleto! Tienes hasta el $fecha_fmt (7 días) para devolverlo.",
        'id_prestamo' => $id_prestamo,
        'id_usuario' => $id_usuario,
        'usuario' => $nombreCompleto,
        'fecha_devolucion' => $fecha_fmt
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] registrar_prestamo: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar el préstamo. Actualiza el catálogo e inténtalo nuevamente.'], 500);
}
?>