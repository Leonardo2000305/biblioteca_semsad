<?php
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada = semsad_leer_json();
$correo  = strtolower(trim((string)($entrada['correo'] ?? '')));

if ($correo === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa tu correo o código.']);
}

$ip    = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$fecha = date('Y-m-d H:i:s');

try {
    $stmt = $conexion->prepare(
        'SELECT id_usuario FROM usuario WHERE LOWER(correo) = :correo OR LOWER(codigo_empleado) = :codigo LIMIT 1'
    );
    $stmt->execute([':correo' => $correo, ':codigo' => $correo]);
    $existe = (bool)$stmt->fetch();

    if ($existe) {
        $estado = 'CAMBIÓ CONTRASEÑA CON ÉXITO';
        $status = 'success';
        $msg    = 'Se han enviado las instrucciones a tu correo registrado.';
    } else {
        $estado = 'NO ENCONTRADO - FALLIDO';
        $status = 'error';
        $msg    = 'El correo o código ingresado no se encuentra registrado.';
    }

    $aud = $conexion->prepare(
        'INSERT INTO auditoria_claves (correo_ingresado, fecha_hora, estado, ip_origen) VALUES (:c, :f, :e, :ip)'
    );
    $aud->execute([':c' => $correo, ':f' => $fecha, ':e' => $estado, ':ip' => $ip]);

    semsad_responder(['status' => $status, 'mensaje' => $msg]);
} catch (PDOException $e) {
    error_log('[SEMSAD] solicitar_recuperacion: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error de base de datos: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
