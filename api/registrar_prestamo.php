<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', 'True');
    session_start();
}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    // Capturar ID del material / libro
    $id_material = (int)(
        $_POST['id_material'] ?? 
        $input['id_material'] ?? 
        $_POST['id_libro'] ?? 
        $input['id_libro'] ?? 
        $input['id'] ?? 0
    );

    // Capturar usuario desde la petición
    $req_id_user = (int)(
        $input['id_usuario'] ?? 
        $input['id'] ?? 
        $input['usuario_id'] ?? 
        $input['user_id'] ?? 
        $_POST['id_usuario'] ?? 0
    );

    $req_email = trim(
        $input['correo'] ?? 
        $input['email'] ?? 
        $input['userCorreo'] ?? 
        $_POST['correo'] ?? ''
    );

    // Capturar desde la sesión PHP
    $sess_id = (int)(
        $_SESSION['id_usuario'] ?? 
        $_SESSION['usuario_id'] ?? 
        $_SESSION['id'] ?? 
        $_SESSION['usuario']['id_usuario'] ?? 
        $_SESSION['usuario']['id'] ?? 0
    );

    $sess_email = trim(
        $_SESSION['correo'] ?? 
        $_SESSION['email'] ?? 
        $_SESSION['usuario']['correo'] ?? 
        $_SESSION['usuario']['email'] ?? ''
    );

    if ($id_material <= 0) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Identificador de libro no válido.']);
        exit;
    }

    try {
        $usuario = null;

        // Búsqueda 1: Por ID enviado en payload
        if ($req_id_user > 0) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmt->execute([':id' => $req_id_user]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Búsqueda 2: Por Correo enviado en payload
        if (!$usuario && !empty($req_email)) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $req_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Búsqueda 3: Por ID en sesión PHP
        if (!$usuario && $sess_id > 0) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmt->execute([':id' => $sess_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Búsqueda 4: Por Correo en sesión PHP
        if (!$usuario && !empty($sess_email)) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $sess_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$usuario) {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'No se encontró tu usuario registrado. Por favor, inicia sesión nuevamente con tu cuenta de Estudiante.'
            ]);
            exit;
        }

        // BLOQUEO DE SEGURIDAD: Administradores y Bibliotecarios no pueden solicitar préstamos
        if ((int)($usuario['id_rol'] ?? 0) === 1 || (int)($usuario['id_rol'] ?? 0) === 2 || strtolower($usuario['correo']) === 'admin@semsad.edu.bo') {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Las cuentas de Administrador / Bibliotecario no pueden reservar libros. Debes iniciar sesión con una cuenta de Estudiante.'
            ]);
            exit;
        }

        $id_usuario = (int)$usuario['id_usuario'];
        $nombreCompleto = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);

        // Sincronizar sesión PHP
        $_SESSION['id_usuario'] = $id_usuario;
        $_SESSION['correo'] = $usuario['correo'];

        // Verificar estado activo
        if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "Tu cuenta ($nombreCompleto) está " . $usuario['estado'] . ". Contacta a la administración."
            ]);
            exit;
        }

        // Límite de 5 préstamos
        $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :u AND estado = 'Activo'");
        $stmtCount->execute([':u' => $id_usuario]);
        if ((int)$stmtCount->fetchColumn() >= 5) {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "$nombreCompleto, ya tienes el límite máximo de 5 préstamos activos simultáneos."
            ]);
            exit;
        }

        $conexion->beginTransaction();

        // Buscar ejemplar disponible
        $stmtCheck = $conexion->prepare("SELECT id_ejemplar FROM Ejemplar WHERE id_material = :m AND estado_ejemplar = 'Disponible' LIMIT 1 FOR UPDATE");
        $stmtCheck->execute([':m' => $id_material]);
        $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$ejemplar) {
            $conexion->rollBack();
            echo json_encode(['status' => 'error', 'mensaje' => 'Lo sentimos, no hay copias disponibles de este libro en este momento.']);
            exit;
        }

        $id_ejemplar = (int)$ejemplar['id_ejemplar'];
        $id_bibliotecario = 1;
        $dias_prestamo = 7;
        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

        // Registrar Préstamo a nombre del estudiante real
        $stmtP = $conexion->prepare("INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) VALUES (:u, :b, :salida, :limite, 'Activo')");
        $stmtP->execute([
            ':u'      => $id_usuario,
            ':b'      => $id_bibliotecario,
            ':salida' => $fecha_salida,
            ':limite' => $fecha_limite
        ]);
        $id_prestamo = (int)$conexion->lastInsertId();

        // Registrar Detalle
        $stmtD = $conexion->prepare("INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)");
        $stmtD->execute([':p' => $id_prestamo, ':e' => $id_ejemplar]);

        // Actualizar Ejemplar
        $stmtE = $conexion->prepare("UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :e");
        $stmtE->execute([':e' => $id_ejemplar]);

        $conexion->commit();

        $fecha_fmt = date('d/m/Y', strtotime("+$dias_prestamo days"));

        echo json_encode([
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
        echo json_encode(['status' => 'error', 'mensaje' => 'Error en base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
}
?>
