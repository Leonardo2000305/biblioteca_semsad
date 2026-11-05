<?php
// ARCHIVO: guardar_usuario_sirve_para_crear_o_editar_cuenta.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Crear una cuenta de usuario nueva o editar una que ya existe (nombre, correo, teléfono, CI, contraseña y rol).
// QUÉ HACE: Solo personal. Recibe los datos en JSON. Si id_usuario viene con valor, edita la cuenta (la contraseña cambia solo si se escribe una nueva).
// Si no, crea la cuenta: exige el código de verificación enviado al correo y revisa que el correo, la CI y el teléfono no estén repetidos. Responde un JSON.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; enviar_codigo_sirve_para_registro_de_usuario.php (envía el código que se pide al crear); pantalla de administración de usuarios (revisar qué archivo HTML la llama).
require_once 'conexion_sirve_para_conectar_base_datos.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

// Solo se acepta POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

// Lee los datos enviados en JSON. Si no llega nada válido, usa un arreglo vacío.
$input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

$id_usuario     = (int)($input['id_usuario'] ?? 0);
$nombres        = trim((string)($input['nombres'] ?? ''));
$apellidos      = trim((string)($input['apellidos'] ?? ''));
$correo         = trim((string)($input['correo'] ?? ''));
$password       = trim((string)($input['password'] ?? ''));
$telefono       = trim((string)($input['telefono'] ?? ''));
$codigo_empleado= trim((string)($input['codigo_empleado'] ?? ''));
$id_rol         = (int)($input['id_rol'] ?? 3);
$codigoOtp      = trim((string)($input['codigo_otp'] ?? ''));
// Dice si quien hace la petición es Administrador. Se toma de la sesión del servidor, no de lo que envía el navegador.
$esAdminSesion  = ($_SESSION['rol'] ?? '') === 'Administrador';

// Validación: el nombre y el correo son obligatorios.
if (empty($nombres) || empty($correo)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El nombre y el correo son obligatorios.']);
}
// Nombre y apellidos: solo letras, espacios, guiones y apóstrofes. Así no se puede guardar código HTML ni etiquetas como <script>.
if (!semsad_nombre_valido($nombres) || ($apellidos !== '' && !semsad_nombre_valido($apellidos))) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El nombre y los apellidos solo pueden tener letras, espacios, guiones y apóstrofes (máximo 60 caracteres).']);
}

try {
    // Un bibliotecario no puede tocar cuentas de Administrador ni crear o asignar el rol de Administrador.
    // Revisa que el rol enviado exista en la tabla de roles.
    $rolValido = $conexion->prepare('SELECT COUNT(*) FROM rol WHERE id_rol = :r');
    $rolValido->execute([':r' => $id_rol]);
    if ((int)$rolValido->fetchColumn() === 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El rol seleccionado no existe.']);
    }
    if (!$esAdminSesion && $id_rol === 1) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Solo un Administrador puede asignar el rol de Administrador.'], 403);
    }
    // Si se edita una cuenta existente, primero se revisa su rol actual: un bibliotecario no puede modificar cuentas de Administrador.
    if ($id_usuario > 0) {
        $rolActual = $conexion->prepare('SELECT id_rol FROM usuario WHERE id_usuario = :id');
        $rolActual->execute([':id' => $id_usuario]);
        if ((int)$rolActual->fetchColumn() === 1 && !$esAdminSesion) {
            semsad_responder(['status' => 'error', 'mensaje' => 'Solo un Administrador puede modificar una cuenta de Administrador.'], 403);
        }
    }

    // Separa dos caminos: si el id es mayor que cero se edita la cuenta; si no, se crea una cuenta nueva.
    if ($id_usuario > 0) {
        // ── EDITAR (sin cambios de comportamiento) ──
        // Si se escribió una contraseña nueva, se valida su largo y se guarda cifrada. Si está vacía, la contraseña actual no cambia.
        if (!empty($password)) {
            if ($error = semsad_clave_error($password)) {
                semsad_responder(['status' => 'error', 'mensaje' => $error]);
            }
            // Cifra la contraseña con bcrypt. En la base nunca se guarda en texto plano.
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conexion->prepare("
                UPDATE usuario
                SET nombres = :n, apellidos = :a, correo = :c, contrasena = :p, telefono = :t, codigo_empleado = :cod, id_rol = :r
                WHERE id_usuario = :id
            ");
            $stmt->execute([
                ':n' => $nombres, ':a' => $apellidos, ':c' => $correo, ':p' => $hash,
                ':t' => $telefono, ':cod' => $codigo_empleado, ':r' => $id_rol, ':id' => $id_usuario
            ]);
        } else {
            $stmt = $conexion->prepare("
                UPDATE usuario
                SET nombres = :n, apellidos = :a, correo = :c, telefono = :t, codigo_empleado = :cod, id_rol = :r
                WHERE id_usuario = :id
            ");
            $stmt->execute([
                ':n' => $nombres, ':a' => $apellidos, ':c' => $correo,
                ':t' => $telefono, ':cod' => $codigo_empleado, ':r' => $id_rol, ':id' => $id_usuario
            ]);
        }
        $msg = "Usuario \"$nombres $apellidos\" actualizado correctamente.";
        $id_usuario_final = $id_usuario;
    } else {
        // ── NUEVO: mismas reglas que el registro público (registrar_usuario_sirve_para_crear_cuenta_al_registrarse.php) ──
        // El correo se guarda en minúsculas para que no se repita por diferencias de mayúsculas.
        $correo = strtolower($correo);
        if (!semsad_correo_valido($correo)) {
            semsad_responder(['status' => 'error', 'mensaje' => 'El correo electrónico no tiene un formato válido.']);
        }
        if ($codigo_empleado === '') {
            semsad_responder(['status' => 'error', 'mensaje' => 'La CI / código de registro es obligatorio.']);
        }
        if (!preg_match('/^[0-9]{7,10}$/', $telefono)) {
            semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa un teléfono válido (7 a 10 dígitos).']);
        }
        if ($error = semsad_clave_error($password)) {
            semsad_responder(['status' => 'error', 'mensaje' => $error]);
        }
        // Revisa que la tabla usuario tenga la columna telefono. Si no la tiene, no se puede crear la cuenta.
        if (!semsad_tiene_columna($conexion, 'usuario', 'telefono')) {
            semsad_responder(['status' => 'error', 'mensaje' => 'Falta la columna telefono en la tabla usuario. Hay que agregarla antes de registrar usuarios.']);
        }

        // Código enviado al correo del nuevo usuario (misma tabla que el registro público)
        $resultadoOtp = $codigoOtp === '' ? 'incorrecto' : semsad_verificar_otp($conexion, $correo, $codigoOtp);
        // Si hubo demasiados intentos con el código, se pide uno nuevo y hay que esperar un poco antes de volver a intentar.
        if ($resultadoOtp === 'bloqueado') {
            semsad_responder(['status' => 'error', 'mensaje' => 'Demasiados intentos con este código. Pide un código nuevo y espera unos minutos.', 'requiere_codigo' => true]);
        }
        if ($resultadoOtp !== 'ok') {
            semsad_responder(['status' => 'error', 'mensaje' => 'El código de verificación es incorrecto o ha expirado.', 'requiere_codigo' => true]);
        }

        // Duplicados: correo, CI y teléfono (mismos mensajes que el registro público)
        $dupCorreo = $conexion->prepare('SELECT COUNT(*) FROM usuario WHERE LOWER(TRIM(correo)) = :v');
        $dupCorreo->execute([':v' => $correo]);
        if ((int)$dupCorreo->fetchColumn() > 0) {
            semsad_responder(['status' => 'error', 'mensaje' => 'El correo electrónico ya está registrado en el sistema.']);
        }
        $dupCi = $conexion->prepare('SELECT COUNT(*) FROM usuario WHERE codigo_empleado = :v');
        $dupCi->execute([':v' => $codigo_empleado]);
        if ((int)$dupCi->fetchColumn() > 0) {
            semsad_responder(['status' => 'error', 'mensaje' => 'La CI / Código de estudiante ya está registrada.']);
        }
        $dupTel = $conexion->prepare('SELECT COUNT(*) FROM usuario WHERE telefono = :v');
        $dupTel->execute([':v' => $telefono]);
        if ((int)$dupTel->fetchColumn() > 0) {
            semsad_responder(['status' => 'error', 'mensaje' => '¡Registro bloqueado! Este número de teléfono ya pertenece a otra cuenta.']);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        // Crea el usuario con estado Activo.
        $stmt = $conexion->prepare("
            INSERT INTO usuario (nombres, apellidos, correo, contrasena, telefono, codigo_empleado, id_rol, estado)
            VALUES (:n, :a, :c, :p, :t, :cod, :r, 'Activo')
        ");
        $stmt->execute([
            ':n' => $nombres, ':a' => $apellidos, ':c' => $correo, ':p' => $hash,
            ':t' => $telefono, ':cod' => $codigo_empleado, ':r' => $id_rol
        ]);
        $id_usuario_final = (int)$conexion->lastInsertId();

        // El código ya se usó: se borra para que no se pueda reutilizar
        try {
            $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
        } catch (PDOException $ignorar) {}

        $msg = "Usuario \"$nombres $apellidos\" registrado exitosamente.";
    }

    semsad_responder(['status' => 'success', 'mensaje' => $msg, 'id_usuario' => $id_usuario_final]);

} catch (Exception $e) {
    // Error inesperado: se registra en el log. La respuesta solo muestra el detalle en modo depuración.
    error_log('[SEMSAD] guardar_usuario: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar usuario.' . semsad_detalle_error($e)], 500);
}
