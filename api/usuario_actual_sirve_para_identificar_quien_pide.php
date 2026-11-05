<?php
// ARCHIVO: usuario_actual_sirve_para_identificar_quien_pide.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: saber qué usuario está conectado, usando solo la sesión del servidor.
// QUÉ HACE: Si la sesión tiene un id de usuario, busca esa cuenta en la base y la devuelve.
//   Si no hay sesión, devuelve null: nadie queda identificado.
// CONECTA CON: lo usan obtener_libros_sirve_para_mostrar_catalogo.php, registrar_prestamo_sirve_para_pedir_libro_estudiante.php y mis_prestamos_sirve_para_historial_del_estudiante.php; la sesión la crea login_sirve_para_iniciar_sesion.php
/**
 * Identifica QUIÉN está haciendo la petición, SOLO con la sesión del servidor (la que crea login_sirve_para_iniciar_sesion.php).
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
    // Si la sesión de PHP no está iniciada, la inicia antes de leerla.
    if (session_status() === PHP_SESSION_NONE && function_exists('semsad_iniciar_sesion')) {
        semsad_iniciar_sesion();
    }

    // Id guardado en la sesión al iniciar sesión.
    $idSesion = (int)($_SESSION['id_usuario'] ?? 0);
    // Sin id en la sesión, nadie queda identificado: se devuelve null.
    if ($idSesion <= 0) {
        return null;
    }

    // Busca esa cuenta en la base. La consulta no trae la contraseña.
    $q = $conexion->prepare("SELECT id_usuario, nombres, apellidos, correo, estado, id_rol FROM usuario WHERE id_usuario = :id LIMIT 1");
    $q->execute([':id' => $idSesion]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    // Devuelve la fila del usuario, o null si no existe.
    return $u ?: null;
}
