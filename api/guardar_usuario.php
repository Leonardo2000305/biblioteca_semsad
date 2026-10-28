<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

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
$esAdminSesion  = ($_SESSION['rol'] ?? '') === 'Administrador';

if (empty($nombres) || empty($correo)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El nombre y el correo son obligatorios.']);
}
if (!semsad_nombre_valido($nombres) || ($apellidos !== '' && !semsad_nombre_valido($apellidos))) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El nombre y los apellidos solo pueden tener letras, espacios, guiones y apóstrofes (máximo 60 caracteres).']);
}

try {
    // Un bibliotecario no puede tocar cuentas de Administrador ni crear o asignar el rol de Administrador.
    $rolValido = $conexion->prepare('SELECT COUNT(*) FROM rol WHERE id_rol = :r');
    $rolValido->execute([':r' => $id_rol]);
    if ((int)$rolValido->fetchColumn() === 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El rol seleccionado no existe.']);
    }
    if (!$esAdminSesion && $id_rol === 1) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Solo un Administrador puede asignar el rol de Administrador.'], 403);
    }
    if ($id_usuario > 0) {
        $rolActual = $conexion->prepare('SELECT id_rol FROM usuario WHERE id_usuario = :id');
        $rolActual->execute([':id' => $id_usuario]);
        if ((int)$rolActual->fetchColumn() === 1 && !$esAdminSesion) {
            semsad_responder(['status' => 'error', 'mensaje' => 'Solo un Administrador puede modificar una cuenta de Administrador.'], 403);
        }
    }

    if ($id_usuario > 0) {
        // ── EDITAR (sin cambios de comportamiento) ──
        if (!empty($password)) {
            if ($error = semsad_clave_error($password)) {
                semsad_responder(['status' => 'error', 'mensaje' => $error]);
            }
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
        // ── NUEVO: mismas reglas que el registro público (registrar_usuario.php) ──
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
        if (!semsad_tiene_columna($conexion, 'usuario', 'telefono')) {
            semsad_responder(['status' => 'error', 'mensaje' => 'Falta la columna telefono en la tabla usuario. Hay que agregarla antes de registrar usuarios.']);
        }

        // Código enviado al correo del nuevo usuario (misma tabla que el registro público)
        $resultadoOtp = $codigoOtp === '' ? 'incorrecto' : semsad_verificar_otp($conexion, $correo, $codigoOtp);
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
    error_log('[SEMSAD] guardar_usuario: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar usuario.' . semsad_detalle_error($e)], 500);
}
