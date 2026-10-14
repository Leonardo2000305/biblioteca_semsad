<?php
require_once 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);

$correo = trim($data['correo'] ?? '');
$clave = $data['clave'] ?? '';

if (empty($correo) || empty($clave)) {
    echo json_encode(["status" => "error", "mensaje" => "Correo y contraseña son obligatorios."]);
    exit;
}

// Búsqueda del usuario por correo o código
$stmt = $conn->prepare("SELECT id, nombres, apellidos, correo, clave, rol FROM usuarios WHERE correo = ? OR codigo_empleado = ? LIMIT 1");
$stmt->bind_param("ss", $correo, $correo);
$stmt->execute();
$result = $stmt->get_result();

if ($user = $result->fetch_assoc()) {
    // Si la contraseña usa password_hash o texto plano
    if (password_verify($clave, $user['clave']) || $clave === $user['clave']) {
        $rol = strtolower($user['rol'] ?? 'estudiante');
        $redirect = ($rol === 'admin' || $rol === 'bibliotecario') ? '../admin.html' : '../index.html';

        echo json_encode([
            "status" => "success",
            "mensaje" => "Acceso correcto",
            "redirect" => $redirect,
            "usuario" => [
                "id" => $user['id'],
                "nombre" => $user['nombres'] . " " . $user['apellidos'],
                "correo" => $user['correo'],
                "rol" => $user['rol']
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "mensaje" => "Contraseña incorrecta."]);
    }
} else {
    echo json_encode(["status" => "error", "mensaje" => "Usuario no encontrado."]);
}

$stmt->close();
$conn->close();
?>
