<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $id_usuario = isset($_POST['id_usuario']) ? $_POST['id_usuario'] : (isset($input['id_usuario']) ? $input['id_usuario'] : '');
    $id_material = isset($_POST['id_material']) ? $_POST['id_material'] : (isset($input['id_material']) ? $input['id_material'] : '');
    $dias_prestamo = isset($_POST['dias_prestamo']) ? intval($_POST['dias_prestamo']) : (isset($input['dias_prestamo']) ? intval($input['dias_prestamo']) : 7);

    if (empty($id_usuario) || empty($id_material)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios (usuario o libro).']);
        exit;
    }

    try {
        $conexion->beginTransaction();

        // 1. Obtener un ejemplar disponible de ese material
        $sqlCheck = "SELECT id_ejemplar, estado_ejemplar FROM Ejemplar WHERE id_material = :id_material AND estado_ejemplar = 'Disponible' LIMIT 1 FOR UPDATE";
        $stmtCheck = $conexion->prepare($sqlCheck);
        $stmtCheck->execute([':id_material' => $id_material]);
        $ejemplar = $stmtCheck->fetch();

        if (!$ejemplar) {
            $conexion->rollBack();
            echo json_encode(['status' => 'error', 'mensaje' => 'No hay copias disponibles de este libro.']);
            exit;
        }

        $id_ejemplar = $ejemplar['id_ejemplar'];

        // 2. Registrar el préstamo (incluye id_bibliotecario_entrega)
        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));
        $id_bibliotecario = isset($_SESSION['id_usuario']) ? $_SESSION['id_usuario'] : 1; 

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

        // 3. Registrar en Detalle_Prestamo
        $sqlDetalle = "INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:id_prestamo, :id_ejemplar)";
        $stmtDetalle = $conexion->prepare($sqlDetalle);
        $stmtDetalle->execute([
            ':id_prestamo' => $id_prestamo,
            ':id_ejemplar' => $id_ejemplar
        ]);

        // 4. Cambiar el estado del ejemplar a 'Prestado'
        $sqlUpdate = "UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :id_ejemplar";
        $stmtUpdate = $conexion->prepare($sqlUpdate);
        $stmtUpdate->execute([':id_ejemplar' => $id_ejemplar]);

        $conexion->commit();

        echo json_encode([
            'status' => 'success',
            'mensaje' => 'Préstamo registrado con éxito.',
            'id_prestamo' => $id_prestamo,
            'fecha_limite' => $fecha_limite
        ]);

    } catch (PDOException $e) {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al procesar el préstamo: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
}
?>
