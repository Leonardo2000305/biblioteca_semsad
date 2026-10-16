<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$input = semsad_leer_json();
$id_usuario = (int)($input['id_usuario'] ?? 0);
$nombres = trim((string)($input['nombres'] ?? ''));
$apellidos = trim((string)($input['apellidos'] ?? ''));
$correo = strtolower(trim((string)($input['correo'] ?? '')));
$password = (string)($input['password'] ?? $input['contrasena'] ?? '');
$telefono = trim((string)($input['telefono'] ?? ''));
$codigo = trim((string)($input['codigo_empleado'] ?? ''));
$id_rol = (int)($input['id_rol'] ?? 4);

if ($nombres === '' || $apellidos === '' || $correo === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Nombres, apellidos y correo son obligatorios.'], 400);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El correo no tiene un formato válido.'], 400);
}
if (!in_array($id_rol, [1, 2, 3, 4], true)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El rol seleccionado no es válido.'], 400);
}
if ($id_usuario === 0 && strlen($password) < 6) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Define una contraseña de al menos 6 caracteres para el nuevo usuario.'], 400);
}
if ($password !== '' && strlen($password) < 6) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.'], 400);
}

try {
    $conexion->beginTransaction();

    $stmtDup = $conexion->prepare("
        SELECT id_usuario
        FROM Usuario
        WHERE (LOWER(correo) = :correo OR (:codigo <> '' AND codigo_empleado = :codigo))
          AND id_usuario <> :id
        LIMIT 1
    ");
    $stmtDup->execute([':correo' => $correo, ':codigo' => $codigo, ':id' => $id_usuario]);
    if ($stmtDup->fetchColumn()) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'El correo o código institucional ya pertenece a otro usuario.'], 409);
    }

    if ($id_usuario > 0) {
        $check = $conexion->prepare("SELECT id_usuario FROM Usuario WHERE id_usuario = :id LIMIT 1");
        $check->execute([':id' => $id_usuario]);
        if (!$check->fetchColumn()) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'El usuario que intentas editar no existe.'], 404);
        }

        if ($password !== '') {
            $stmt = $conexion->prepare("
                UPDATE Usuario
                SET nombres = :n, apellidos = :a, correo = :c, contrasena = :p,
                    telefono = :t, codigo_empleado = :cod, id_rol = :r
                WHERE id_usuario = :id
            ");
            $stmt->execute([
                ':n' => $nombres, ':a' => $apellidos, ':c' => $correo,
                ':p' => password_hash($password, PASSWORD_BCRYPT),
                ':t' => ($telefono !== '' ? $telefono : null),
                ':cod' => ($codigo !== '' ? $codigo : null),
                ':r' => $id_rol, ':id' => $id_usuario
            ]);
        } else {
            $stmt = $conexion->prepare("
                UPDATE Usuario
                SET nombres = :n, apellidos = :a, correo = :c,
                    telefono = :t, codigo_empleado = :cod, id_rol = :r
                WHERE id_usuario = :id
            ");
            $stmt->execute([
                ':n' => $nombres, ':a' => $apellidos, ':c' => $correo,
                ':t' => ($telefono !== '' ? $telefono : null),
                ':cod' => ($codigo !== '' ? $codigo : null),
                ':r' => $id_rol, ':id' => $id_usuario
            ]);
        }
        $msg = "Usuario \"$nombres $apellidos\" actualizado correctamente.";
    } else {
        if ($codigo === '') {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'El código institucional es obligatorio para nuevos usuarios.'], 400);
        }
        $stmt = $conexion->prepare("
            INSERT INTO Usuario
                (nombres, apellidos, correo, contrasena, id_rol, codigo_empleado, telefono, nivel_permiso, estado)
            VALUES (:n, :a, :c, :p, :r, :cod, :t, 1, 'Activo')
        ");
        $stmt->execute([
            ':n' => $nombres,
            ':a' => $apellidos,
            ':c' => $correo,
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            ':r' => $id_rol,
            ':cod' => $codigo,
            ':t' => ($telefono !== '' ? $telefono : null)
        ]);
        $id_usuario = (int)$conexion->lastInsertId();
        $msg = "Usuario \"$nombres $apellidos\" registrado correctamente.";
    }

    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => $msg, 'id_usuario' => $id_usuario]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] guardar_usuario: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo guardar el usuario. Verifica los datos e inténtalo nuevamente.'], 500);
}
?>