<?php
/**
 * Identifica QUIÉN está haciendo la petición. Se usa en reservas y catálogo.
 *
 * Regla única (para que nunca se confunda a un usuario con otro):
 *   1. Manda la SESIÓN del servidor (la que se crea en login.php).
 *   2. Solo si no hay sesión, se acepta el id + correo que envía la página, y únicamente
 *      si AMBOS coinciden con el MISMO usuario. Nunca se "adivina" ni se busca por parecido.
 *
 * Devuelve la fila del usuario (id_usuario, nombres, apellidos, correo, estado, id_rol) o null.
 */
function semsad_usuario_actual(PDO $conexion, int $idCliente = 0, string $correoCliente = ''): ?array
{
    $cols = 'id_usuario, nombres, apellidos, correo, estado, id_rol';

    $idSesion = (int)($_SESSION['id_usuario'] ?? 0);
    if ($idSesion > 0) {
        $q = $conexion->prepare("SELECT $cols FROM usuario WHERE id_usuario = :id LIMIT 1");
        $q->execute([':id' => $idSesion]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            return $u;
        }
    }

    $correoCliente = strtolower(trim($correoCliente));
    if ($idCliente > 0 && $correoCliente !== '') {
        $q = $conexion->prepare("SELECT $cols FROM usuario WHERE id_usuario = :id AND LOWER(TRIM(correo)) = :c LIMIT 1");
        $q->execute([':id' => $idCliente, ':c' => $correoCliente]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            return $u;
        }
    }

    return null;
}
