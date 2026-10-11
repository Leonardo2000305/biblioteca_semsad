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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    $id_usuario = isset($_GET['usuario']) ? $_GET['usuario'] : null;

    try {
        // Se añade 'WHERE p.estado != 'Devuelto'' para ignorar préstamos ya finalizados
        $sql = "SELECT p.id_prestamo, p.fecha_salida, p.fecha_limite, p.estado, 
                        mb.titulo as bookTitle, u.nombres as userName, u.codigo_empleado as userCi
                FROM Prestamo p
                INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
                INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
                INNER JOIN MaterialBibliografico mb ON e.id_material = mb.id_material
                INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
                WHERE p.estado != 'Devuelto'"; 
        
        $params = [];
        if ($id_usuario) {
            $sql .= " AND p.id_usuario = :id_usuario";
            $params[':id_usuario'] = $id_usuario;
        }

        $stmt = $conexion->prepare($sql);
        $stmt->execute($params);
        $prestamos = $stmt->fetchAll();

        $formateados = array_map(function($p) {
            $now = time();
            $due = strtotime($p['fecha_limite']);
            $diff = $due - $now;
            $daysLeft = round($diff / (60 * 60 * 24));
            $overdueDays = $daysLeft < 0 ? abs($daysLeft) : 0;
            $status = $p['estado'];
            
            if ($overdueDays > 0 && $status == 'Activo') {
                $status = 'Vencido';
            }

            return [
                'id' => $p['id_prestamo'],
                'userCi' => $p['userCi'],
                'userName' => $p['userName'],
                'bookTitle' => $p['bookTitle'],
                'issueDate' => substr($p['fecha_salida'], 0, 10),
                'dueDate' => substr($p['fecha_limite'], 0, 10),
                'daysLeft' => $daysLeft,
                'overdueDays' => $overdueDays,
                'status' => $status,
                'code' => 'PRE-00' . $p['id_prestamo']
            ];
        }, $prestamos);

        echo json_encode([
            'status' => 'success',
            'datos' => $formateados
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error: ' . $e->getMessage()]);
    }
}
?>