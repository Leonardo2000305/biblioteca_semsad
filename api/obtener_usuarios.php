<?php
require_once 'conexion.php';

$sql = "SELECT id, nombres, apellidos, correo, telefono, codigo_empleado, rol FROM usuarios ORDER BY id DESC";
$result = $conn->query($sql);

$usuarios = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $usuarios]);
$conn->close();
?>
