<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada       = semsad_leer_json();
$identificador = strtolower(trim((string)($entrada['correo'] ?? '')));   // correo o código (CI / empleado)
$clave         = (string)($entrada['clave'] ?? '');

if ($identificador === '' || $clave === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa tu correo y contraseña.']);
}

try {
    $stmt = $conexion->prepare(
        "SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.clave, u.codigo_empleado,
                u.estado, r.nombre AS rol
           FROM usuario u
     INNER JOIN rol r ON r.id_rol = u.id_rol
          WHERE LOWER(u.correo) = :correo OR LOWER(u.codigo_empleado) = :codigo
          LIMIT 1"
    );
    $stmt->execute([':correo' => $identificador, ':codigo' => $identificador]);
    $usuario = $stmt->fetch();

    // Mismo mensaje para "no existe" y "clave incorrecta": no revelamos qué correos están registrados.
    $mensajeFallo = 'El correo o la contraseña son incorrectos.';
    if (!$usuario) {
        semsad_responder(['status' => 'error', 'mensaje' => $mensajeFallo]);
    }

    $hash  = (string)($usuario['clave'] ?? '');
    $valida = false;

    if ($hash !== '') {
        if (password_verify($clave, $hash)) {
            $valida = true;
        } elseif (!preg_match('/^\$2[aby]\$/', $hash) && hash_equals($hash, $clave)) {
            // Clave heredada en texto plano: se acepta una vez y se convierte a bcrypt.
            $valida = true;
            $upd = $conexion->prepare('UPDATE usuario SET clave = :hash WHERE id_usuario = :id');
            $upd->execute([':hash' => password_hash($clave, PASSWORD_BCRYPT), ':id' => $usuario['id_usuario']]);
        }
    }

    if (!$valida) {
        semsad_responder(['status' => 'error', 'mensaje' => $mensajeFallo]);
    }

    if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
        semsad_responder([
            'status'  => 'error',
            'mensaje' => 'Tu cuenta no está activa (estado: ' . $usuario['estado'] . '). Contacta a la biblioteca.',
        ]);
    }

    session_regenerate_id(true);
    $nombreCompleto = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);
    $_SESSION['id_usuario'] = (int)$usuario['id_usuario'];
    $_SESSION['nombre']     = $nombreCompleto;
    $_SESSION['rol']        = $usuario['rol'];
    $_SESSION['ci']         = $usuario['codigo_empleado'] ?? '';

    $esPersonal = in_array($usuario['rol'], ['Administrador', 'Bibliotecario'], true);

    semsad_responder([
        'status'   => 'success',
        'mensaje'  => '¡Inicio de sesión exitoso!',
        'redirect' => $esPersonal ? '/principal/admin.html' : '/principal/index.html',
        'usuario'  => [
            'id_usuario' => (int)$usuario['id_usuario'],
            'nombre'     => $nombreCompleto,
            'correo'     => $usuario['correo'],
            'rol'        => $usuario['rol'],
            'ci'         => $usuario['codigo_empleado'] ?? '',
        ],
    ]);
} catch (PDOException $e) {
    error_log('[SEMSAD] login: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error de base de datos: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
