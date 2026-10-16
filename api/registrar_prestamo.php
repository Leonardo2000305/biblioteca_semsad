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

    $id_material = isset($_POST['id_material']) ? $_POST['id_material'] : (isset($input['id_material']) ? $input['id_material'] : (isset($input['id_libro']) ? $input['id_libro'] : ''));

    // Capturar datos enviadas desde el frontend o la sesión PHP
    $req_id_user   = (int)(!empty($input['id_usuario']) ? $input['id_usuario'] : (!empty($_POST['id_usuario']) ? $_POST['id_usuario'] : 0));
    $req_email     = trim(!empty($input['correo']) ? $input['correo'] : (!empty($input['email']) ? $input['email'] : (!empty($_POST['correo']) ? $_POST['correo'] : '')));
    
    $session_id    = (int)($_SESSION['id_usuario'] ?? 0);
    $session_email = trim($_SESSION['correo'] ?? $_SESSION['email'] ?? '');

    if (empty($id_material)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Falta el identificador del libro.']);
        exit;
    }

    try {
        $usuario = null;

        // Búsqueda 1: Por ID enviado en la petición
        if ($req_id_user > 0) {
            $stmtUser = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmtUser->execute([':id' => $req_id_user]);
            $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
        }

        // Búsqueda 2: Por Correo enviado en la petición
        if (!$usuario && !empty($req_email)) {
            $stmtUser = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado FROM Usuario WHERE LOWER(correo) = LOWER(:email) LIMIT 1");
            $stmtUser->execute([':email' => $req_email]);
            $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
        }

        // Búsqueda 3: Por ID guardado en la Sesión PHP
        if (!$usuario && $session_id > 0) {
            $stmtUser = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado FROM Usuario WHERE id_usuario = :id LIMIT 1");
            $stmtUser->execute([':id' => $session_id]);
            $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
        }

        // Búsqueda 4: Por Correo guardado en la Sesión PHP
        if (!$usuario && !empty($session_email)) {
            $stmtUser = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado FROM Usuario WHERE LOWER(correo) = LOWER(:email) LIMIT 1");
            $stmtUser->execute([':email' => $session_email]);
            $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
        }

        // Si no se encuentra el usuario:
        if (!$usuario) {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'No se encontró tu usuario registrado. Por favor, vuelve a iniciar sesión en la plataforma.'
            ]);
            exit;
        }

        $id_usuario = (int)$usuario['id_usuario'];
        $nombreCompleto = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);

        // Sincronizar sesión PHP
        $_SESSION['id_usuario'] = $id_usuario;
        $_SESSION['correo'] = $usuario['correo'];

        // Verificar estado de la cuenta
        if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "Tu cuenta ($nombreCompleto) está " . $usuario['estado'] . ". Contacta a la biblioteca."
            ]);
            exit;
        }

        // Verificar límite de 5 préstamos
        $sqlCount = "SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :id_usuario AND estado = 'Activo'";
        $stmtCount = $conexion->prepare($sqlCount);
        $stmtCount->execute([':id_usuario' => $id_usuario]);
        $prestamos_activos = intval($stmtCount->fetchColumn());

        if ($prestamos_activos >= 5) {
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "$nombreCompleto, has alcanzado el límite máximo de 5 préstamos activos."
            ]);
            exit;
        }

        $conexion->beginTransaction();

        // Buscar ejemplar libre
        $sqlCheck = "SELECT id_ejemplar FROM Ejemplar WHERE id_material = :id_material AND estado_ejemplar = 'Disponible' LIMIT 1 FOR UPDATE";
        $stmtCheck = $conexion->prepare($sqlCheck);
        $stmtCheck->execute([':id_material' => $id_material]);
        $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$ejemplar) {
            $conexion->rollBack();
            echo json_encode(['status' => 'error', 'mensaje' => 'No hay copias disponibles de este libro en este momento.']);
            exit;
        }

        $id_ejemplar = $ejemplar['id_ejemplar'];
        $id_bibliotecario = 1;

        $dias_prestamo = 7;
        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

        // Insertar préstamo a nombre del estudiante
        $sqlPrestamo = "INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) 
                        VALUES (:id_usuario, :id_bibliotecario, :fecha_salida, :fecha_limite, 'Activo')";
        $stmtPrestamo = $conexion->prepare($sqlPrestamo);
        $stmtPrestamo->execute([
            ':id_usuario'       => $id_usuario,
            ':id_bibliotecario' => $id_bibliotecario,
            ':fecha_salida'     => $fecha_salida,
            ':fecha_limite'     => $fecha_limite
        ]);
        $id_prestamo = $conexion->lastInsertId();

        // Detalle
        $sqlDetalle = "INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:id_prestamo, :id_ejemplar)";
        $stmtDetalle = $conexion->prepare($sqlDetalle);
        $stmtDetalle->execute([
            ':id_prestamo' => $id_prestamo,
            ':id_ejemplar' => $id_ejemplar
        ]);

        // Cambiar estado a 'Prestado'
        $sqlUpdate = "UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :id_ejemplar";
        $stmtUpdate = $conexion->prepare($sqlUpdate);
        $stmtUpdate->execute([':id_ejemplar' => $id_ejemplar]);

        $conexion->commit();

        $fecha_limite_formato = date('d/m/Y', strtotime("+$dias_prestamo days"));

        echo json_encode([
            'status'           => 'success',
            'mensaje'          => "¡Préstamo registrado exitosamente a nombre de $nombreCompleto! Plazo hasta el $fecha_limite_formato (7 días).",
            'id_prestamo'      => $id_prestamo,
            'usuario'          => $nombreCompleto,
            'fecha_limite'     => $fecha_limite,
            'fecha_devolucion' => $fecha_limite_formato
        ]);

    } catch (PDOException $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al procesar el préstamo: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
}
?>
