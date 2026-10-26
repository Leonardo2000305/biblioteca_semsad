<?php
/**
 * REGISTRO DE USUARIOS NUEVOS: envía el código de verificación de 6 dígitos al correo.
 * (La recuperación de contraseña es otro flujo: solicitar_recuperacion.php)
 * El correo NO debe existir todavía: este archivo es solo para crear cuentas.
 */
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada   = semsad_leer_json();
$correo    = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigoEmp = trim((string)($entrada['codigo_empleado'] ?? ''));
$telefono  = trim((string)($entrada['telefono'] ?? ''));

if ($correo === '' || $codigoEmp === '' || $telefono === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Completa tu correo, CI y teléfono antes de pedir el código.']);
}
if (!semsad_correo_valido($correo)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa un correo electrónico válido.']);
}
if (!preg_match('/^[A-Za-z0-9 .\-\/]{3,20}$/', $codigoEmp)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La CI / código de registro no es válido.']);
}

// Límite de envíos por conexión (anti-spam): 10 códigos por hora desde la misma conexión
$claveEnvio = 'otpenv:ip:' . semsad_ip();
if (semsad_limite_espera($conexion, $claveEnvio) > 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Has pedido demasiados códigos. Espera un rato antes de intentarlo de nuevo.'], 429);
}
semsad_limite_fallo($conexion, $claveEnvio, 10, 3600, 3600);
if (!preg_match('/^[0-9]{7,10}$/', $telefono)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa un teléfono válido (7 a 10 dígitos).']);
}

try {
    // 1. Duplicados
    $duplicados = [
        ['SELECT 1 FROM usuario WHERE LOWER(correo) = :v',   $correo,    'El correo electrónico ya está registrado en el sistema.'],
        ['SELECT 1 FROM usuario WHERE codigo_empleado = :v', $codigoEmp, 'La CI / Código de estudiante ya está registrada.'],
        ['SELECT 1 FROM usuario WHERE telefono = :v',        $telefono,  '¡Registro bloqueado! Este número de teléfono ya pertenece a otra cuenta.'],
    ];
    foreach ($duplicados as [$sql, $valor, $mensaje]) {
        $q = $conexion->prepare($sql . ' LIMIT 1');
        $q->execute([':v' => $valor]);
        if ($q->fetch()) {
            semsad_responder(['status' => 'error', 'mensaje' => $mensaje]);
        }
    }

    // 2. Límite de reenvío: un código por minuto (el código dura 5 min, así que se sabe cuándo se creó el último)
    $q = $conexion->prepare('SELECT MAX(expiracion) FROM codigo_verificacion WHERE LOWER(correo) = :c');
    $q->execute([':c' => $correo]);
    $ultimaExp = $q->fetchColumn();
    if ($ultimaExp) {
        $espera = 60 - (time() - (strtotime((string)$ultimaExp) - 300));
        if ($espera > 0) {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => "Ya te enviamos un código hace poco. Espera $espera segundos para pedir otro.",
                'espera'  => $espera,
            ]);
        }
    }

    // 3. Código nuevo (caduca en 5 minutos); el anterior deja de servir
    $otp        = (string)random_int(100000, 999999);
    $expiracion = date('Y-m-d H:i:s', time() + 300);

    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
    $conexion->prepare('INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:c, :o, :e)')
             ->execute([':c' => $correo, ':o' => $otp, ':e' => $expiracion]);

    // 4. Envío por correo
    $asunto  = '=?UTF-8?B?' . base64_encode('Código de verificación - Biblioteca SEMSAD') . '?=';
    $cuerpo  = "Hola,\n\nTu código de verificación para registrarte en la Biblioteca SEMSAD es: $otp\n\nEste código caduca en 5 minutos.\nSi no lo pediste tú, ignora este mensaje.";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
             . 'From: Biblioteca SEMSAD <' . $cfg['mail_from'] . ">\r\nX-Mailer: PHP/" . phpversion();
    $enviado = @mail($correo, $asunto, $cuerpo, $headers);

    if (!$enviado && empty($cfg['otp_demo'])) {
        error_log('[SEMSAD] mail() no pudo enviar el código a ' . $correo);
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
        semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo enviar el correo en este momento. Inténtalo de nuevo en unos minutos.'], 500);
    }

    $mensaje = 'Código de verificación enviado a ' . $correo . '. Revisa tu correo o SPAM.';
    if (!empty($cfg['otp_demo'])) {
        $mensaje .= ' (Modo prueba — tu código es: ' . $otp . ')';
    }
    semsad_responder(['status' => 'success', 'mensaje' => $mensaje]);
} catch (Throwable $e) {
    error_log('[SEMSAD] enviar_codigo: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
