<?php
$host = "mysql-bibliosemsad.alwaysdata.net";
$port = "3306";
$dbname = "bibliosemsad_db";
$username = "bibliosemsad";
$password = "Jueg012345";

try {
    $conexion = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die(json_encode([
        'status' => 'error',
        'mensaje' => 'Error de conexión a la base de datos: ' . $e->getMessage()
    ]));
}
?>