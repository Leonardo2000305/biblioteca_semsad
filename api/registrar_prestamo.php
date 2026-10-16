<?php
require_once 'conexion.php';
semsad_iniciar_sesion();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

    $id_material = (int)(
        $input['id_material'] ?? 
        $_POST['id_material'] ?? 
        $input['id_libro'] ?? 
        $_POST['id_libro'] ?? 0
    );

    $req_id_user = (int)(
        $input['id_usuario'] ?? 
        $input['id'] ?? 
        $_POST['id_usuario'] ?? 0
    );

    $req_email = trim(
        $input['correo'] ?? 
        $input['email'] ?? 
        $_POST['correo'] ?? ''
    );

    $sess_id = (int)($_SESSION['id_usuario'] ?? 0);
    $sess_email = trim($_SESSION['correo'] ?? $_SESSION['email'] ?? '');

    if ($id_material <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Identificador de libro no válido.']);
    }

    try {
        $usuario = null;

        // 1. Priorizar la sesión PHP activa en el servidor
        if ($sess_id > 0) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmt->execute([':id' => $sess_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        } else if (!empty($sess_email)) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $sess_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 2. Si la sesión no vino por cookies, validar datos enviados por el frontend
        if (!$usuario && $req_id_user > 0 && !empty($req_email)) {
            // Coincidencia cruzada estricta: ID y Correo deben pertenecer al mismo registro
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id AND LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':id' => $req_id_user, ':e' => $req_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        } else if (!$usuario && $req_id_user > 0) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmt->execute([':id' => $req_id_user]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        } else if (!$usuario && !empty($req_email)) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $req_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Rechazar si no hay ningún usuario válido autenticado
        if (!$usuario) {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => 'No se detectó tu sesión activa de estudiante. Por favor, cierra sesión e inicia sesión nuevamente.'
            ]);
        }

        // Prevenir préstamos a cuentas de Administrador (rol 1) o Bibliotecario (rol 2)
        if ((int)($usuario['id_rol'] ?? 0) === 1 || (int)($usuario['id_rol'] ?? 0) === 2 || strtolower($usuario['correo']) === 'admin@semsad.edu.bo') {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => 'Las cuentas administrativas no pueden solicitar préstamos. Ingresa con una cuenta de Estudiante.'
            ]);
        }

        $id_usuario = (int)$usuario['id_usuario'];
        $nombreCompleto = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);

        // Sincronizar la sesión PHP con el usuario autenticado
        $_SESSION['id_usuario'] = $id_usuario;
        $_SESSION['correo']     = $usuario['correo'];

        if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => "Tu cuenta ($nombreCompleto) está " . $usuario['estado'] . ". Contacta a la administración."
            ]);
        }

        // Límite de 5 préstamos activos simultáneos
        $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :u AND estado = 'Activo'");
        $stmtCount->execute([':u' => $id_usuario]);
        if ((int)$stmtCount->fetchColumn() >= 5) {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => "$nombreCompleto, ya tienes el límite máximo de 5 préstamos activos."
            ]);
        }

        $conexion->beginTransaction();

        // Reservar un ejemplar disponible
        $stmtCheck = $conexion->prepare("SELECT id_ejemplar FROM Ejemplar WHERE id_material = :m AND estado_ejemplar = 'Disponible' LIMIT 1 FOR UPDATE");
        $stmtCheck->execute([':m' => $id_material]);
        $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$ejemplar) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'No hay copias disponibles de este libro.']);
        }

        $id_ejemplar = (int)$ejemplar['id_ejemplar'];
        $id_bibliotecario = 1;
        $dias_prestamo = 7;
        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

        // Registrar en tabla Prestamo
        $stmtP = $conexion->prepare("INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) VALUES (:u, :b, :salida, :limite, 'Activo')");
        $stmtP->execute([
            ':u'      => $id_usuario,
            ':b'      => $id_bibliotecario,
            ':salida' => $fecha_salida,
            ':limite' => $fecha_limite
        ]);
        $id_prestamo = (int)$conexion->lastInsertId();

        // Registrar Detalle y actualizar Ejemplar
        $stmtD = $conexion->prepare("INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)");
        $stmtD->execute([':p' => $id_prestamo, ':e' => $id_ejemplar]);

        $stmtE = $conexion->prepare("UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :e");
        $stmtE->execute([':e' => $id_ejemplar]);

        $conexion->commit();

        $fecha_fmt = date('d/m/Y', strtotime("+$dias_prestamo days"));

        semsad_responder([
            'status'           => 'success',
            'mensaje'          => "¡Préstamo registrado exitosamente a nombre de $nombreCompleto! Tienes hasta el $fecha_fmt (7 días) para devolverlo.",
            'id_prestamo'      => $id_prestamo,
            'usuario'          => $nombreCompleto,
            'fecha_devolucion' => $fecha_fmt
        ]);

    } catch (PDOException $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        semsad_responder(['status' => 'error', 'mensaje' => 'Error en base de datos: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.'], 405);
}
