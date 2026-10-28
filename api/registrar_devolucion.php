<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', 'True');
    session_start();
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $id_prestamo = isset($_POST['id_prestamo']) ? $_POST['id_prestamo'] : (isset($input['id_prestamo']) ? $input['id_prestamo'] : '');
    $condicion_devolucion = isset($_POST['condicion_devolucion']) ? trim($_POST['condicion_devolucion']) : (isset($input['condicion_devolucion']) ? trim($input['condicion_devolucion']) : 'Bueno');

    // Estado físico real que registra el bibliotecario (solo valores permitidos)
    $condiciones_validas = ['Bueno', 'Regular', 'Malo'];
    if (!in_array($condicion_devolucion, $condiciones_validas, true)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Estado físico no válido. Use Bueno, Regular o Malo.']);
        exit;
    }

    if (empty($id_prestamo)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Debe indicar el ID del préstamo.']);
        exit;
    }

    try {
        $conexion->beginTransaction();

        // 1. Obtener detalles del préstamo (tablas en minúsculas)
        $sql = "SELECT p.id_prestamo, p.fecha_limite, dp.id_ejemplar 
                FROM prestamo p
                INNER JOIN detalle_prestamo dp ON p.id_prestamo = dp.id_prestamo
                WHERE p.id_prestamo = :id_prestamo AND p.estado IN ('Activo', 'Con Mora')";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([':id_prestamo' => $id_prestamo]);
        $prestamo = $stmt->fetch();

        if (!$prestamo) {
            $conexion->rollBack();
            echo json_encode(['status' => 'error', 'mensaje' => 'El préstamo no existe o ya fue devuelto.']);
            exit;
        }

        $fecha_actual = date('Y-m-d H:i:s');

        // 2. Actualizar el estado del préstamo
        $sqlUpPrestamo = "UPDATE prestamo SET fecha_devolucion_real = :fecha_devolucion, estado = 'Devuelto' WHERE id_prestamo = :id_prestamo";
        $stmtUpP = $conexion->prepare($sqlUpPrestamo);
        $stmtUpP->execute([
            ':fecha_devolucion' => $fecha_actual,
            ':id_prestamo' => $id_prestamo
        ]);

        // 3. Actualizar la condición en detalle_prestamo
        $sqlUpDetalle = "UPDATE detalle_prestamo SET condicion_devolucion = :condicion WHERE id_prestamo = :id_prestamo";
        $stmtUpD = $conexion->prepare($sqlUpDetalle);
        $stmtUpD->execute([
            ':condicion' => $condicion_devolucion,
            ':id_prestamo' => $id_prestamo
        ]);

        // 4. Liberar el ejemplar y guardar su estado físico real
        $sqlUpEjemplar = "UPDATE ejemplar SET estado_ejemplar = 'Disponible', condicion_fisica = :condicion WHERE id_ejemplar = :id_ejemplar";
        $stmtUpE = $conexion->prepare($sqlUpEjemplar);
        $stmtUpE->execute([
            ':condicion' => $condicion_devolucion,
            ':id_ejemplar' => $prestamo['id_ejemplar']
        ]);

        $conexion->commit();

        echo json_encode([
            'status' => 'success',
            'mensaje' => 'Devolución registrada correctamente.'
        ]);

    } catch (PDOException $e) {
        $conexion->rollBack();
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al registrar devolución.' . semsad_detalle_error($e)]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.']);
}
?>
