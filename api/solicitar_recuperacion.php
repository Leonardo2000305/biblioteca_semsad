<?php
session_start();
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $correo = isset($data['correo']) ? trim($data['correo']) : '';

    if (empty($correo)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Debes ingresar un correo o código.']);
        exit;
    }

    // Capturar la dirección IP del usuario/dispositivo
    $ip_origen = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';

    try {
        // 1. Verificar si el usuario existe en la tabla usuario
        $stmtUser = $conexion->prepare("SELECT id_usuario, correo FROM usuario WHERE correo = :c OR codigo_empleado = :c LIMIT 1");
        $stmtUser->execute([':c' => $correo]);
        $user = $stmtUser->fetch();

        if ($user) {
            // Existe -> Registrar intento 'Exitoso'
            $stmtLog = $conexion->prepare("INSERT INTO auditoria_recuperacion (correo_ingresado, id_usuario, estado, ip_origen) VALUES (:correo, :id_usuario, 'Exitoso', :ip)");
            $stmtLog->execute([
                ':correo' => $correo,
                ':id_usuario' => $user['id_usuario'],
                ':ip' => $ip_origen
            ]);

            echo json_encode([
                'status' => 'success',
                'mensaje' => 'Se han enviado las instrucciones a tu correo institucional.'
            ]);
        } else {
            // No existe -> Registrar intento 'Correo No Encontrado' para auditoría de seguridad
            $stmtLog = $conexion->prepare("INSERT INTO auditoria_recuperacion (correo_ingresado, id_usuario, estado, ip_origen) VALUES (:correo, NULL, 'Correo No Encontrado', :ip)");
            $stmtLog->execute([
                ':correo' => $correo,
                ':ip' => $ip_origen
            ]);

            echo json_encode([
                'status' => 'error',
                'mensaje' => 'El correo o código ingresado no coincide con ninguna cuenta activa.'
            ]);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error al procesar la solicitud: ' . $e->getMessage()]);
    }
}
?>
