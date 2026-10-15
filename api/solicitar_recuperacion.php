<?php
ob_start();
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));

if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingrese un correo electrónico válido.']);
}

try {
    // 1. Verificar si el usuario existe en la BD
    $stmt = $conexion->prepare('SELECT id_usuario FROM usuario WHERE LOWER(correo) = :c LIMIT 1');
    $stmt->execute([':c' => $correo]);
    if (!$stmt->fetch()) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'No existe ninguna cuenta registrada con este correo.']);
    }

    // 2. Generar código OTP de 6 dígitos (expira en 5 minutos)
    $otp        = (string)random_int(100000, 999999);
    $expiracion = date('Y-m-d H:i:s', time() + 300);

    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
    $conexion->prepare('INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:c, :o, :e)')
             ->execute([':c' => $correo, ':o' => $otp, ':e' => $expiracion]);

    // 3. Localizar automáticamente la API Key de Brevo (inicia con xkeysib-)
    $apiKeyBrevo = '';

    // Buscar en variables globales cargadas por conexion.php
    $searchVars = [$cfg ?? [], $GLOBALS['cfg'] ?? [], $GLOBALS];
    foreach ($searchVars as $var) {
        if (is_array($var)) {
            array_walk_recursive($var, function($val) use (&$apiKeyBrevo) {
                if (is_string($val) && strpos($val, 'xkeysib-') === 0) {
                    $apiKeyBrevo = $val;
                }
            });
        }
    }

    // Si no está en memoria, extraer directamente de los archivos de configuración en el servidor
    if (empty($apiKeyBrevo)) {
        $filesToScan = [
            __DIR__ . '/config.php',
            __DIR__ . '/conexion.php',
            __DIR__ . '/enviar_codigo.php'
        ];
        foreach ($filesToScan as $filePath) {
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                if (preg_match('/xkeysib-[a-zA-Z0-9\-_]+/', $content, $matches)) {
                    $apiKeyBrevo = $matches[0];
                    break;
                }
            }
        }
    }

    // 4. Ejecutar el envío de correo
    $enviado = false;
    $asunto  = 'Restablecer Contrasena - Biblioteca SEMSAD';
    $html    = "<h3>Recuperacion de Contrasena - Biblioteca SEMSAD</h3>
                <p>Hola,</p>
                <p>Has solicitado restablecer tu contrasena. Tu codigo de verificacion es: <b>$otp</b></p>
                <p>Este codigo expira en 5 minutos. Si no solicitaste este cambio, ignora este mensaje.</p>";

    if (function_exists('semsad_enviar_correo_brevo')) {
        $enviado = semsad_enviar_correo_brevo($correo, $asunto, $html);
    } elseif (function_exists('semsad_enviar_correo')) {
        $enviado = semsad_enviar_correo($correo, $asunto, $html);
    } elseif (!empty($apiKeyBrevo)) {
        $dataBrevo = [
            'sender' => [
                'name'  => 'Biblioteca SEMSAD',
                'email' => 'no-reply@semsad.edu.bo'
            ],
            'to' => [
                ['email' => $correo]
            ],
            'subject' => $asunto,
            'htmlContent' => $html
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dataBrevo));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . $apiKeyBrevo,
            'Content-Type: application/json',
            'accept: application/json'
        ]);

        $respuesta = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $enviado = true;
        } else {
            error_log('[SEMSAD] Error Brevo Recuperacion (' . $httpCode . '): ' . $respuesta);
        }
    }

    if (ob_get_length()) ob_clean();

    if ($enviado) {
        semsad_responder(['status' => 'success', 'mensaje' => 'Código de recuperación enviado a tu correo.']);
    } else {
        semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo enviar el correo. Intenta nuevamente.'], 500);
    }
} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error interno del servidor.'], 500);
}
