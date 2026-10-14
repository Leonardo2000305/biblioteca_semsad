<?php
require_once 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);
$correo = trim($data['correo'] ?? '');

if (empty($correo)) {
    echo json_encode(["status" => "error", "mensaje" => "Ingresa tu correo o código."]);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '166.114.171.21';
$fecha = date('Y-m-d H:i:s');

// Verificar usuario
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? OR codigo_empleado = ? LIMIT 1");
$stmt->bind_param("ss", $correo, $correo);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    $estado = "CAMBIÓ CONTRASEÑA CON ÉXITO";
    $status = "success";
    $msg = "Se han enviado las instrucciones a tu correo registrado.";
} else {
    $estado = "NO ENCONTRADO - FALLIDO";
    $status = "error";
    $msg = "El correo o código ingresado no se encuentra registrado.";
}

// Registrar en auditoría
$stmtAud = $conn->prepare("INSERT INTO auditoria_claves (correo_ingresado, fecha_hora, estado, ip_origen) VALUES (?, ?, ?, ?)");
$stmtAud->bind_param("ssss", $correo, $fecha, $estado, $ip);
$stmtAud->execute();

echo json_encode(["status" => $status, "mensaje" => $msg]);

$conn->close();
?>
