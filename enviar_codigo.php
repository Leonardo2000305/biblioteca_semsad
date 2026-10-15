<?php
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada   = semsad_leer_json();
$correo    = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigoEmp = trim((string)($entrada['codigo_empleado'] ?? ''));
$telefono  = trim((string)($entrada['telefono'] ?? ''));

if ($correo === '' || $codigoEmp === '' || $telefono === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Complete su Correo, CI y Teléfono antes de pedir el código.']);
}

// 1. Solo correos institucionales
if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || !preg_match('/@([a-z0-9-]+\.)?semsad\.edu\.bo$/i', $correo)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Solo se permiten correos institucionales (@est.semsad.edu.bo).']);
}

// 2. Teléfono: 7 a 10 dígitos
if (!preg_match('/^[0-9]{7,10}$/', $telefono)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingrese un teléfono válido (7 a 10 dígitos).']);
}

try {
    // 3. Duplicados
    $duplicados = [
        ['SELECT 1 FROM usuario WHERE LOWER(correo) = :v',  $correo,    'El correo electrónico ya está registrado en el sistema.'],
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

    // 4. Código de 6 dígitos que caduca en 5 minutos (se guarda en codigo_verificacion)
    $otp        = (string)random_int(100000, 999999);
    $expiracion = date('Y-m-d H:i:s', time() + 300);

    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
    $conexion->prepare('INSERT INTO codigo_verificacion (correo, codigo, expiracion) VALUES (:c, :o, :e)')
             ->execute([':c' => $correo, ':o' => $otp, ':e' => $expiracion]);

    // 5. Envío por correo
    $asunto  = 'Codigo de Verificacion - Biblioteca SEMSAD';
    $cuerpo  = "Hola,\n\nTu codigo de verificacion para registrarte en la Biblioteca SEMSAD es: $otp\n\nEste codigo caduca en 5 minutos.";
    $headers = "From: no-reply@semsad.edu.bo\r\nReply-To: soporte@semsad.edu.bo\r\nX-Mailer: PHP/" . phpversion();
    $enviado = @mail($correo, $asunto, $cuerpo, $headers);

    $mensaje = 'Código de verificación enviado a ' . $correo . '. Revisa tu correo o SPAM.';
    if (!empty($cfg['otp_demo'])) {
        $mensaje .= ' (Modo prueba — tu código es: ' . $otp . ')';
    } elseif (!$enviado) {
        error_log('[SEMSAD] mail() no pudo enviar el código a ' . $correo);
    }

    semsad_responder(['status' => 'success', 'mensaje' => $mensaje]);
} catch (PDOException $e) {
    error_log('[SEMSAD] enviar_codigo: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error en base de datos: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
