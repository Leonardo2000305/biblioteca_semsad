<?php
require_once 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);

$nombres = trim($data['nombres'] ?? '');
$apellidos = trim($data['apellidos'] ?? '');
$correo = trim($data['correo'] ?? '');
$codigo_empleado = trim($data['codigo_empleado'] ?? '');
$telefono = trim($data['telefono'] ?? '');
$clave = $data['clave'] ?? '';

if (empty($nombres) || empty($correo) || empty($clave)) {
    echo json_encode(["status" => "error", "mensaje" => "Por favor completa todos los campos requeridos."]);
    exit;
}

// Verificar si el correo ya existe
$stmtCheck = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? OR codigo_empleado = ?");
$stmtCheck->bind_param("ss", $correo, $codigo_empleado);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    echo json_encode(["status" => "error", "mensaje" => "El correo o CI ya está registrado."]);
    exit;
}

// Encriptar clave
$claveHash = password_hash($clave, PASSWORD_BCRYPT);
$rol = 'estudiante';

$stmt = $conn->prepare("INSERT INTO usuarios (nombres, apellidos, correo, codigo_empleado, telefono, clave, rol) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssss", $nombres, $apellidos, $correo, $codigo_empleado, $telefono, $claveHash, $rol);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "mensaje" => "Registro completado con éxito."]);
} else {
    echo json_encode(["status" => "error", "mensaje" => "Error al registrar en la base de datos."]);
}

$stmt->close();
$conn->close();
?>
