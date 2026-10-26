<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

// 1. Verificar si la sesión está bloqueada temporalmente por intentos fallidos
$ahora = time();
if (!empty($_SESSION['login_bloqueado_hasta']) && $_SESSION['login_bloqueado_hasta'] > $ahora) {
    $restante = $_SESSION['login_bloqueado_hasta'] - $ahora;
    semsad_responder([
        'status'          => 'error',
        'bloqueado'       => true,
        'tiempo_restante' => $restante,
        'mensaje'         => "Has superado el límite de intentos. Por seguridad, espera {$restante} segundos."
    ], 429);
}

$entrada       = semsad_leer_json();
$identificador = strtolower(trim((string)($entrada['correo'] ?? '')));   // correo o código (CI / empleado)
$clave         = (string)($entrada['clave'] ?? '');

if ($identificador === '' || $clave === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa tu correo y contraseña.']);
}
if (strlen($identificador) > 150 || strlen($clave) > 200) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
}

// 2. Límites guardados en la base: por cuenta (5 fallos -> 15 min) y por conexión (50 fallos -> 10 min).
//    Están en la base y no en la sesión, así que borrar cookies o cambiar de navegador no los salta.
$claveCuenta = 'login:cuenta:' . $identificador;
$claveIp     = 'login:ip:' . semsad_ip();
$esperaBase  = max(semsad_limite_espera($conexion, $claveCuenta), semsad_limite_espera($conexion, $claveIp));
if ($esperaBase > 0) {
    semsad_responder([
        'status'          => 'error',
        'bloqueado'       => true,
        'tiempo_restante' => $esperaBase,
        'mensaje'         => "Demasiados intentos fallidos. Por seguridad, espera {$esperaBase} segundos."
    ], 429);
}

try {
    $stmt = $conexion->prepare(
        "SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.contrasena, u.codigo_empleado,
                u.estado, r.nombre AS rol
           FROM usuario u
     INNER JOIN rol r ON r.id_rol = u.id_rol
          WHERE LOWER(u.correo) = :correo OR LOWER(u.codigo_empleado) = :codigo
          LIMIT 1"
    );
    $stmt->execute([':correo' => $identificador, ':codigo' => $identificador]);
    $usuario = $stmt->fetch();

    $valida = false;
    if ($usuario) {
        $hash = (string)($usuario['contrasena'] ?? '');
        if ($hash !== '') {
            if (password_verify($clave, $hash)) {
                $valida = true;
            } elseif (!preg_match('/^\$2[aby]\$/', $hash) && hash_equals($hash, $clave)) {
                // Clave heredada en texto plano: se acepta una vez y se convierte a bcrypt.
                $valida = true;
                $upd = $conexion->prepare('UPDATE usuario SET contrasena = :hash WHERE id_usuario = :id');
                $upd->execute([':hash' => password_hash($clave, PASSWORD_BCRYPT), ':id' => $usuario['id_usuario']]);
            }
        }
    } else {
        // Mismo tiempo de respuesta exista o no la cuenta (evita adivinar correos por la velocidad)
        password_verify($clave, '$2y$10$Ccx99E9TwEimAbb20pscbO.tD.rBNiEXoDM6c/eKzKWjNR9h/QPmO');
    }

    // 3. Control de intentos fallidos (sesión: 3 intentos -> 30 s; base de datos: ver arriba)
    if (!$usuario || !$valida) {
        semsad_limite_fallo($conexion, $claveCuenta, 5, 900, 900);
        semsad_limite_fallo($conexion, $claveIp, 50, 900, 600);

        $_SESSION['intentos_login'] = ($_SESSION['intentos_login'] ?? 0) + 1;
        $intentosHechos = $_SESSION['intentos_login'];

        if ($intentosHechos >= 3) {
            $_SESSION['login_bloqueado_hasta'] = time() + 30; // 30 segundos de penalización
            $_SESSION['intentos_login'] = 0;                  // Reiniciar contador

            semsad_responder([
                'status'          => 'error',
                'bloqueado'       => true,
                'tiempo_restante' => 30,
                'mensaje'         => 'Has superado el límite de 3 intentos fallidos. Por seguridad, debes esperar 30 segundos.'
            ], 429);
        } else {
            semsad_responder([
                'status'   => 'error',
                'intentos' => $intentosHechos,
                'mensaje'  => "El correo o la contraseña son incorrectos. (Intento {$intentosHechos} de 3)"
            ]);
        }
    }

    // 4. Credenciales válidas: limpiar contadores de intentos
    unset($_SESSION['intentos_login']);
    unset($_SESSION['login_bloqueado_hasta']);
    semsad_limite_reset($conexion, $claveCuenta);

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
