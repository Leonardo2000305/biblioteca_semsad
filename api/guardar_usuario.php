<?php
require_once 'conexion.php';
semsad_iniciar_sesion();
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

    if (empty($nombres) || empty($correo)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El nombre y el correo son obligatorios.']);
    }

    try {
        if ($id_usuario > 0) {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conexion->prepare("
                    UPDATE Usuario 
                    SET nombres = :n, apellidos = :a, correo = :c, password_hash = :p, telefono = :t, codigo_empleado = :cod, id_rol = :r 
                    WHERE id_usuario = :id
                ");
                $stmt->execute([
                    ':n' => $nombres, ':a' => $apellidos, ':c' => $correo, ':p' => $hash,
                    ':t' => $telefono, ':cod' => $codigo_empleado, ':r' => $id_rol, ':id' => $id_usuario
                ]);
            } else {
                $stmt = $conexion->prepare("
                    UPDATE Usuario 
                    SET nombres = :n, apellidos = :a, correo = :c, telefono = :t, codigo_empleado = :cod, id_rol = :r 
                    WHERE id_usuario = :id
                ");
                $stmt->execute([
                    ':n' => $nombres, ':a' => $apellidos, ':c' => $correo,
                    ':t' => $telefono, ':cod' => $codigo_empleado, ':r' => $id_rol, ':id' => $id_usuario
                ]);
            }
            $msg = "Usuario \"$nombres $apellidos\" actualizado correctamente.";
        } else {
            $stmtChk = $conexion->prepare("SELECT COUNT(*) FROM Usuario WHERE LOWER(TRIM(correo)) = LOWER(TRIM(:c))");
            $stmtChk->execute([':c' => $correo]);
            if ((int)$stmtChk->fetchColumn() > 0) {
                semsad_responder(['status' => 'error', 'mensaje' => "El correo $correo ya se encuentra registrado."]);
            }

            $passFinal = !empty($password) ? $password : 'semsad2024';
            $hash = password_hash($passFinal, PASSWORD_BCRYPT);

            if (empty($codigo_empleado)) {
                $codigo_empleado = 'EST-' . rand(100000, 999999);
            }

            $stmt = $conexion->prepare("
                INSERT INTO Usuario (nombres, apellidos, correo, password_hash, telefono, codigo_empleado, id_rol, estado) 
                VALUES (:n, :a, :c, :p, :t, :cod, :r, 'Activo')
            ");
            $stmt->execute([
                ':n' => $nombres, ':a' => $apellidos, ':c' => $correo, ':p' => $hash,
                ':t' => $telefono, ':cod' => $codigo_empleado, ':r' => $id_rol
            ]);
            $id_usuario = (int)$conexion->lastInsertId();
            $msg = "Usuario \"$nombres $apellidos\" registrado exitosamente.";
        }

        semsad_responder(['status' => 'success', 'mensaje' => $msg, 'id_usuario' => $id_usuario]);

    } catch (Exception $e) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar usuario: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
