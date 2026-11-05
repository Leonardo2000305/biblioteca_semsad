<?php
// ARCHIVO: obtener_usuarios_sirve_para_padron_de_usuarios.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: mostrar al personal la lista de todos los usuarios (el padrón).
// QUÉ HACE: Busca los usuarios con el nombre de su rol y responde con la lista en JSON.
//   No envía la contraseña.
// CONECTA CON: principal/admin.html (pantalla de usuarios); usa conexion_sirve_para_conectar_base_datos.php
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';

// Solo el personal de la biblioteca puede ver el padrón de usuarios.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    // Trae los datos de cada usuario y el nombre de su rol. LEFT JOIN: si el rol no existe, el usuario igual aparece.
    $stmt = $conexion->query(
        "SELECT u.id_usuario AS id, u.id_usuario, u.id_rol, u.nombres, u.apellidos, u.correo, u.telefono,
                u.codigo_empleado, u.estado, r.nombre AS rol
           FROM usuario u
      LEFT JOIN rol r ON r.id_rol = u.id_rol
       ORDER BY u.id_usuario DESC"
    );
    // Respuesta JSON con la lista de usuarios.
    semsad_responder(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    // Error de base de datos: se guarda en el log y se responde 500.
    error_log('[SEMSAD] obtener_usuarios: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener la lista de usuarios.'], 500);
}
