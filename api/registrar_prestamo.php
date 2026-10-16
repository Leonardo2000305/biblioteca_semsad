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

    // Capturar id_material
    $id_material = isset($_POST['id_material']) ? $_POST['id_material'] : (isset($input['id_material']) ? $input['id_material'] : (isset($input['id_libro']) ? $input['id_libro'] : ''));

    // Capturar id_usuario (Si no viene en el body, toma la sesión activa)
    $id_usuario = isset($_POST['id_usuario']) ? $_POST['id_usuario'] : (isset($input['id_usuario']) ? $input['id_usuario'] : (isset($_SESSION['id_usuario']) ? $_SESSION['id_usuario'] : ''));

    $dias_prestamo = isset($_POST['dias_prestamo']) ? intval($_POST['dias_prestamo']) : (isset($input['dias_prestamo']) ? intval($input['dias_prestamo']) : 7);

    if (empty($id_usuario)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Debes iniciar sesión para solicitar un préstamo.']);
        exit;
    }

    if (empty($id_material)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Falta el identificador del libro.']);
        exit;
    }

    try {
        // 1. Verificar estado del usuario
        $sqlUser = "SELECT estado FROM Usuario WHERE id_usuario = :id_usuario LIMIT 1";
        $stmtUser = $conexion->prepare($sqlUser);
        $stmtUser->execute([':id_usuario' => $id_usuario]);
        $usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El usuario no existe.']);
            exit;
        }

        if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
            echo json_encode([
                'status' => 'error',
                'mensaje' => 'Tu cuenta está ' . $usuario['estado'] . '. No puedes solicitar préstamos. Contacta a la biblioteca.'
            ]);
            exit;
        }

        // 2. Regla de Negocio: Máximo 5 préstamos activos por estudiante
        $sqlCount = "SELECT COUNT(*) FROM Prestamo WHERE id_usuario = :id_usuario AND estado = 'Activo'";
        $stmtCount = $conexion->prepare($sqlCount);
        $stmtCount->execute([':id_usuario' => $id_usuario]);
        $prestamos_activos = intval($stmtCount->fetchColumn());

        if ($prestamos_activos >= 5) {
            echo json_encode([
                'status' => 'error',
                'mensaje' => 'Has alcanzado el límite máximo de 5 préstamos activos simultáneos.'
            ]);
            exit;
        }

        $conexion->beginTransaction();

        // 3. Obtener un ejemplar disponible de ese material
        $sqlCheck = "SELECT id_ejemplar FROM Ejemplar WHERE id_material = :id_material AND estado_ejemplar = 'Disponible' LIMIT 1 FOR UPDATE";
        $stmtCheck = $conexion->prepare($sqlCheck);
        $stmtCheck->execute([':id_material' => $id_material]);
        $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$ejemplar) {
            $conexion->rollBack();
            echo json_encode(['status' => 'error', 'mensaje' => 'No hay copias disponibles de este libro.']);
            exit;
        }

        $id_ejemplar = $ejemplar['id_ejemplar'];

        // 4. Determinar bibliotecario a cargo
        $id_bibliotecario = isset($_SESSION['id_usuario']) ? $_SESSION['id_usuario'] : 1;

        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

        // 5. Registrar el préstamo
        $sqlPrestamo = "INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) 
                        VALUES (:id_usuario, :id_bibliotecario, :fecha_salida, :fecha_limite, 'Activo')";
        $stmtPrestamo = $conexion->prepare($sqlPrestamo);
        $stmtPrestamo->execute([
            ':id_usuario' => $id_usuario,
            ':id_bibliotecario' => $id_bibliotecario,
            ':fecha_salida' => $fecha_salida,
            ':fecha_limite' => $fecha_limite
        ]);
        $id_prestamo = $conexion->lastInsertId();

        // 6. Registrar en Detalle_Prestamo
        $sqlDetalle = "INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:id_prestamo, :id_ejemplar)";
        $stmtDetalle = $conexion->prepare($sqlDetalle);
        $stmtDetalle->execute([
            ':id_prestamo' => $id_prestamo,
            ':id_ejemplar' => $id_ejemplar
        ]);

        // 7. Cambiar el estado del ejemplar a 'Prestado'
        $sqlUpdate = "UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :id_ejemplar";
        $stmtUpdate = $conexion->prepare($sqlUpdate);
        $stmtUpdate->execute([':id_ejemplar' => $id_ejemplar]);

        $conexion->commit();

        $fecha_limite_formato = date('d/m/Y', strtotime("+$dias_prestamo days"));

        echo json_encode([
            'status' => 'success',
            'mensaje' => "¡Préstamo registrado con éxito! Tienes hasta el $fecha_limite_formato (7 días) para devolverlo.",
            'id_prestamo' => $id_prestamo,
            'fecha_limite' => $fecha_limite,
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
