<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

    $id_usuario     = (int)($input['id_usuario'] ?? $_POST['id_usuario'] ?? 0);
    $nombres        = trim($input['nombres'] ?? $_POST['nombres'] ?? '');
    $apellidos      = trim($input['apellidos'] ?? $_POST['apellidos'] ?? '');
    $correo         = trim($input['correo'] ?? $_POST['correo'] ?? '');
    $password       = trim($input['password'] ?? $_POST['password'] ?? '');
    $telefono       = trim($input['telefono'] ?? $_POST['telefono'] ?? '');
    $codigo_empleado= trim($input['codigo_empleado'] ?? $_POST['codigo_empleado'] ?? '');
    $id_rol         = (int)($input['id_rol'] ?? $_POST['id_rol'] ?? 3);
    $codigoOtp      = trim($input['codigo_otp'] ?? $_POST['codigo_otp'] ?? '');

    if (empty($nombres) || empty($correo)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El nombre y el correo son obligatorios.']);
    }

    try {
        if ($id_usuario > 0) {
            // ── EDITAR (sin cambios de comportamiento) ──
            if (!empty($password)) {
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
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                semsad_responder(['status' => 'error', 'mensaje' => 'El correo electrónico no tiene un formato válido.']);
            }
            if ($codigo_empleado === '') {
                semsad_responder(['status' => 'error', 'mensaje' => 'La CI / código de registro es obligatorio.']);
            }
            if (!preg_match('/^[0-9]{7,10}$/', $telefono)) {
                semsad_responder(['status' => 'error', 'mensaje' => 'Ingresa un teléfono válido (7 a 10 dígitos).']);
            }
            if (strlen($password) < 6) {
                semsad_responder(['status' => 'error', 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.']);
            }
            if (!semsad_tiene_columna($conexion, 'usuario', 'telefono')) {
                semsad_responder(['status' => 'error', 'mensaje' => 'Falta la columna telefono en la tabla usuario. Hay que agregarla antes de registrar usuarios.']);
            }

            // Código enviado al correo del nuevo usuario (misma tabla que el registro público)
            $otpValido = false;
            try {
                $q = $conexion->prepare('SELECT codigo, expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c');
                $q->execute([':c' => $correo]);
                foreach ($q->fetchAll() as $fila) {
                    if ($codigoOtp !== '' && hash_equals((string)$fila['codigo'], $codigoOtp) && strtotime($fila['expiracion']) >= time()) {
                        $otpValido = true;
                        break;
                    }
                }
            } catch (PDOException $ignorar) { $otpValido = false; }
            if (!$otpValido) {
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
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar usuario: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
