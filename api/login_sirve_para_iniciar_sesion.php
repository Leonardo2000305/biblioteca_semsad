<?php
// ARCHIVO: login_sirve_para_iniciar_sesion.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Iniciar sesión con correo o CI, y contraseña.
// QUÉ HACE: Solo POST. Revisa bloqueos por demasiados fallos (en la sesión y en la base de datos), busca al usuario y compara la contraseña con bcrypt.
// Si falla, cuenta el intento. Si es correcto, crea la sesión en el servidor y responde con el rol y la página de destino.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de inicio de sesión de la web (revisar qué archivo HTML la llama); logout_sirve_para_cerrar_sesion.php (cierra la sesión que este archivo crea).
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_iniciar_sesion();

// Solo se acepta POST.
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

// Lee el correo (o la CI) y la contraseña. El identificador se pasa a minúsculas para comparar sin distinguir mayúsculas.
$entrada       = semsad_leer_json();
$identificador = strtolower(trim((string)($entrada['correo'] ?? '')));   // correo o código (CI / empleado)
$clave         = (string)($entrada['clave'] ?? '');

// Validación: los dos datos son obligatorios.
if ($identificador === '' || $clave === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa tu correo y contraseña.']);
}
// Si los textos son demasiado largos, se rechaza sin consultar la base de datos.
if (strlen($identificador) > 150 || strlen($clave) > 200) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
}

// 2. Límites guardados en la base: por cuenta (5 fallos -> 15 min) y por conexión (50 fallos -> 10 min).
//    Están en la base y no en la sesión, así que borrar cookies o cambiar de navegador no los salta.
$claveCuenta = 'login:cuenta:' . $identificador;
$claveIp     = 'login:ip:' . semsad_ip();
// Se toma la espera más larga entre la de la cuenta y la de la conexión.
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
    // Busca al usuario por correo o por CI (el mismo campo sirve para los dos) y une la tabla de roles para saber su rol.
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
    // Si la cuenta existe, se compara la contraseña con el hash guardado.
    if ($usuario) {
        $hash = (string)($usuario['contrasena'] ?? '');
        if ($hash !== '') {
            // Forma normal: se compara la contraseña con bcrypt (password_verify).
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

        // Al tercer fallo seguido en esta sesión se bloquea 30 segundos y el contador vuelve a cero.
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

    // Regla: una cuenta suspendida o dada de baja no puede entrar, aunque la contraseña sea correcta.
    if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
        semsad_responder([
            'status'  => 'error',
            'mensaje' => 'Tu cuenta no está activa (estado: ' . $usuario['estado'] . '). Contacta a la biblioteca.',
        ]);
    }

    // Cambia el número de sesión al entrar. Así no sirve una sesión que alguien haya fijado de antemano.
    session_regenerate_id(true);
    $nombreCompleto = trim($usuario['nombres'] . ' ' . $usuario['apellidos']);
    // Guarda en la sesión del servidor los datos del usuario que entró.
    $_SESSION['id_usuario'] = (int)$usuario['id_usuario'];
    $_SESSION['nombre']     = $nombreCompleto;
    $_SESSION['rol']        = $usuario['rol'];
    $_SESSION['ci']         = $usuario['codigo_empleado'] ?? '';

    // El personal (Administrador o Bibliotecario) va al panel de administración. El resto va a la página principal.
    $esPersonal = in_array($usuario['rol'], ['Administrador', 'Bibliotecario'], true);

    semsad_responder([
        'status'   => 'success',
        'mensaje'  => '¡Inicio de sesión exitoso!',
        // Página a la que la web debe redirigir después de entrar.
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
    // Error de base de datos: se registra en el log. El detalle solo se muestra en modo depuración.
    error_log('[SEMSAD] login: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error de base de datos: ' . $e->getMessage() : 'Error interno del servidor.',
    ], 500);
}
