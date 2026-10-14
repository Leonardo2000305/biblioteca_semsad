<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Configura con las credenciales de tu Base de Datos remota
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'biblioteca_semsad';
$port = getenv('DB_PORT') ?: 3306;

try {
    $conn = new mysqli($host, $user, $pass, $dbname, $port);
    $conn->set_charset("utf8mb4");

    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
} catch (Exception $e) {
    // Retorna error en formato JSON si no puede conectar
    echo json_encode(["status" => "error", "mensaje" => $e->getMessage()]);
    exit;
}
?>
