<?php
require_once 'conexion.php';

$sql = "SELECT id_material, titulo, isbn, anio_publicacion, autor, disponible FROM libros ORDER BY id_material DESC";
$result = $conn->query($sql);

$libros = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $libros[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $libros]);
$conn->close();
?>
