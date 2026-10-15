<?php
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = trim((string)($entrada['correo'] ?? ''));

if ($correo === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Debes ingresar un correo o código.']);
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
$ip = trim(explode(',', $ip)[0]);

try {
    $stmt = $conexion->prepare(
        'SELECT id_usuario FROM usuario WHERE LOWER(correo) = :c1 OR LOWER(codigo_empleado) = :c2 LIMIT 1'
    );
    $stmt->execute([':c1' => strtolower($correo), ':c2' => strtolower($correo)]);
    $usuario = $stmt->fetch();

    // Cada intento queda registrado en auditoria_recuperacion (lo muestra el panel de administración)
    $log = $conexion->prepare(
        'INSERT INTO auditoria_recuperacion (correo_ingresado, id_usuario, estado, ip_origen) VALUES (:c, :u, :e, :ip)'
    );
    $log->execute([
        ':c'  => $correo,
        ':u'  => $usuario ? $usuario['id_usuario'] : null,
        ':e'  => $usuario ? 'Exitoso' : 'Correo No Encontrado',
        ':ip' => $ip,
    ]);

    if ($usuario) {
        semsad_responder(['status' => 'success', 'mensaje' => 'Se han enviado las instrucciones a tu correo institucional.']);
    }
    semsad_responder(['status' => 'error', 'mensaje' => 'El correo o código ingresado no coincide con ninguna cuenta activa.']);
} catch (PDOException $e) {
    error_log('[SEMSAD] solicitar_recuperacion: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error al procesar la solicitud: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
