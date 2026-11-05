<?php
// ARCHIVO: obtener_auditoria_sirve_para_ver_intentos_de_acceso.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Mostrar al personal el registro de intentos de recuperación de contraseña (quién, cuándo, desde qué IP y con qué resultado).
// QUÉ HACE: Solo personal de la biblioteca. Trae los 200 intentos más recientes y, si la cuenta existe, les añade el nombre, la CI y el teléfono del usuario.
// Responde un JSON con la lista en el campo data. Revisar: el archivo no comprueba el método HTTP, así que responde igual a cualquier método.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php (la función semsad_auditar guarda estos intentos); pantalla de auditoría del panel de administración (revisar qué archivo HTML la llama).
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';

// La auditoría de seguridad es solo para el personal de la biblioteca.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    // Une cada intento con los datos del usuario (si la cuenta existe) para que el administrador sepa quién fue.
    // Une cada intento con su usuario. LEFT JOIN: si la cuenta ya no existe, el intento igual aparece.
    $stmt = $conexion->query(
        'SELECT a.correo_ingresado, a.fecha_hora, a.estado, a.ip_origen,
                u.nombres, u.apellidos, u.codigo_empleado, u.telefono
           FROM auditoria_recuperacion a
      LEFT JOIN usuario u ON u.id_usuario = a.id_usuario
       ORDER BY a.fecha_hora DESC
          LIMIT 200'
    );
    // Responde con los datos (máximo 200 registros).
    semsad_responder(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    // Si la consulta falla, se registra el error y se responde con 500.
    error_log('[SEMSAD] obtener_auditoria: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener la auditoría.'], 500);
}
