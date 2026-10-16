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

    $id_material = (int)(
        $_POST['id_material'] ?? 
        $input['id_material'] ?? 
        $_POST['id_libro'] ?? 
        $input['id_libro'] ?? 0
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
        echo json_encode(['status' => 'error', 'mensaje' => 'Identificador de libro no válido.']);
        exit;
    }

    try {
        $usuario = null;

        // 1. Buscar por ID directo de la petición
        if ($req_id_user > 0) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmt->execute([':id' => $req_id_user]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 2. Buscar por Correo directo de la petición
        if (!$usuario && !empty($req_email)) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $req_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 3. Buscar por Sesión PHP
        if (!$usuario && $sess_id > 0) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmt->execute([':id' => $sess_id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 4. Buscar por Correo de Sesión PHP
        if (!$usuario && !empty($sess_email)) {
            $stmt = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
            $stmt->execute([':e' => $sess_email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // SIN FALLBACK ALEATORIO: Si no se encuentra el usuario exacto, rechazar la petición
        if (!$usuario) {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'No se detectó tu sesión activa de estudiante. Por favor, cierra sesión e inicia sesión nuevamente.'
            ]);
            exit;
        }

        // Prevenir préstamos a cuentas administrativas
        if ((int)($usuario['id_rol'] ?? 0) === 1 || (int)($usuario['id_rol'] ?? 0) === 2 || strtolower($usuario['correo']) === 'admin@semsad.edu.bo') {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Las cuentas administrativas no pueden solicitar préstamos. Ingresa con una cuenta de Estudiante.'
            ]);
            exit;
        }

        $id_usuario = (int)$usuario['id_usuario'];
        $nombreCompleto = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);

        // Sincronizar sesión PHP con este usuario
        $_SESSION['id_usuario'] = $id_usuario;
        $_SESSION['correo'] = $usuario['correo'];

        if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "Tu cuenta ($nombreCompleto) está " . $usuario['estado'] . ". Contacta a la administración."
            ]);
            exit;
        }

        // Límite máximo de 5 préstamos
        $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :u AND estado = 'Activo'");
        $stmtCount->execute([':u' => $id_usuario]);
        if ((int)$stmtCount->fetchColumn() >= 5) {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "$nombreCompleto, ya tienes el límite máximo de 5 préstamos activos."
            ]);
            exit;
        }

        $conexion->beginTransaction();

        $stmtCheck = $conexion->prepare("SELECT id_ejemplar FROM Ejemplar WHERE id_material = :m AND estado_ejemplar = 'Disponible' LIMIT 1 FOR UPDATE");
        $stmtCheck->execute([':m' => $id_material]);
        $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$ejemplar) {
            $conexion->rollBack();
            echo json_encode(['status' => 'error', 'mensaje' => 'No hay copias disponibles de este libro.']);
            exit;
        }

        $id_ejemplar = (int)$ejemplar['id_ejemplar'];
        $id_bibliotecario = 1;
        $dias_prestamo = 7;
        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

        $stmtP = $conexion->prepare("INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) VALUES (:u, :b, :salida, :limite, 'Activo')");
        $stmtP->execute([
            ':u'      => $id_usuario,
            ':b'      => $id_bibliotecario,
            ':salida' => $fecha_salida,
            ':limite' => $fecha_limite
        ]);
        $id_prestamo = (int)$conexion->lastInsertId();

        $stmtD = $conexion->prepare("INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)");
        $stmtD->execute([':p' => $id_prestamo, ':e' => $id_ejemplar]);

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
