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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    $id_usuario = isset($_GET['usuario']) ? $_GET['usuario'] : (isset($_GET['id_usuario']) ? $_GET['id_usuario'] : null);

    try {
        $sql = "SELECT p.id_prestamo, 
                       p.fecha_salida, 
                       p.fecha_limite, 
                       p.estado, 
                       mb.titulo AS bookTitle, 
                       CONCAT(u.nombres, ' ', u.apellidos) AS userName, 
                       u.correo AS userCorreo,
                       u.codigo_empleado,
                       u.id_usuario AS userId
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

        $sql .= " ORDER BY p.id_prestamo DESC";

        $stmt = $conexion->prepare($sql);
        $stmt->execute($params);
        $prestamos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formateados = array_map(function($p) {
            $now = time();
            $due = strtotime($p['fecha_limite']);
            $diff = $due - $now;
            $daysLeft = (int)round($diff / (60 * 60 * 24));
            $overdueDays = $daysLeft < 0 ? abs($daysLeft) : 0;
            $status = $p['estado'];
            
            if ($overdueDays > 0 && $status === 'Activo') {
                $status = 'Vencido';
            }

            $dueDate = $p['fecha_limite'] ? substr($p['fecha_limite'], 0, 10) : date('Y-m-d');
            $rawCodigo = trim($p['codigo_empleado'] ?? '');

            // Si contiene entre 7 y 12 dígitos, es un número de celular/teléfono
            if (preg_match('/^[0-9\s\+\-]{7,12}$/', $rawCodigo)) {
                $userTelefono = $rawCodigo;
                $userCi = 'REG-' . str_pad($p['userId'], 4, '0', STR_PAD_LEFT);
            } else if (!empty($rawCodigo)) {
                $userCi = $rawCodigo;
                $userTelefono = 'Sin teléfono';
            } else {
                $userCi = 'REG-' . str_pad($p['userId'], 4, '0', STR_PAD_LEFT);
                $userTelefono = 'Sin teléfono';
            }

            return [
                'id'           => (int)$p['id_prestamo'],
                'id_prestamo'  => (int)$p['id_prestamo'],
                'userName'     => $p['userName'],
                'userCorreo'   => $p['userCorreo'],
                'userTelefono' => $userTelefono,
                'userCi'       => $userCi,
                'bookTitle'    => $p['bookTitle'],
                'dueDate'      => $dueDate,
                'status'       => $status
            ];
        }, $prestamos);

        echo json_encode([
            'status'    => 'success',
            'total'     => count($formateados),
            'datos'     => $formateados,
            'prestamos' => $formateados,
            'data'      => $formateados
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al consultar préstamos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido. Use GET.']);
}
?>
