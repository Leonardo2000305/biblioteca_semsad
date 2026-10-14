<?php
// Enrutador centralizado para Vercel (Consume solo 1 Serverless Function)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Extraer el nombre del endpoint solicitado
$endpoint = trim(str_replace('/api', '', $requestUri), '/');
$endpoint = preg_replace('/\.php$/', '', $endpoint);

if (empty($endpoint)) {
    echo json_encode(["status" => "success", "mensaje" => "API SEMSAD Operativa"]);
    exit;
}

$archivoObjetivo = __DIR__ . '/' . $endpoint . '.php';

if (file_exists($archivoObjetivo) && is_file($archivoObjetivo) && $endpoint !== 'index') {
    require $archivoObjetivo;
} else {
    http_response_code(404);
    echo json_encode(["status" => "error", "mensaje" => "Endpoint no encontrado: " . $endpoint]);
}
?>
