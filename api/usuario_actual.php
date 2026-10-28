<?php
/**
 * Identifica QUIÉN está haciendo la petición, SOLO con la sesión del servidor (la que crea login.php).
 *
 * Antes también se aceptaba un id + correo enviados por el navegador. Con eso, cualquiera que conociera
 * el id y el correo de un estudiante podía leer su historial de préstamos y pedir libros a su nombre
 * (hallazgo del análisis de seguridad, confirmado en la base de pruebas). Ahora, sin sesión, nadie
 * queda identificado y la API responde como "sin sesión".
 *
 * Devuelve la fila del usuario (id_usuario, nombres, apellidos, correo, estado, id_rol) o null.
 */
function semsad_usuario_actual(PDO $conexion): ?array
{
    if (session_status() === PHP_SESSION_NONE && function_exists('semsad_iniciar_sesion')) {
        semsad_iniciar_sesion();
    }

    $idSesion = (int)($_SESSION['id_usuario'] ?? 0);
    if ($idSesion <= 0) {
        return null;
    }

    $q = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM usuario WHERE id_usuario = :id LIMIT 1");
    $q->execute([':id' => $idSesion]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}
