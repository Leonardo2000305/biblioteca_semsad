<?php
require_once 'conexion.php';

$sql = "SELECT id, correo_ingresado, fecha_hora, estado, ip_origen FROM auditoria_claves ORDER BY fecha_hora DESC";
$result = $conn->query($sql);

$auditoria = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $auditoria[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $auditoria]);
$conn->close();
?>
