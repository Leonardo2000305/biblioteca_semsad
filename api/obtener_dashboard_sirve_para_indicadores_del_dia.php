<?php
// ARCHIVO: obtener_dashboard_sirve_para_indicadores_del_dia.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Dar los tres números del inicio del panel de personal: títulos en el catálogo, títulos con copias disponibles y préstamos activos.
// QUÉ HACE: Solo personal. Hace tres consultas de conteo y responde un JSON con total_libros, disponibles y prestamos_activos.
// Revisar: el nombre dice «del día», pero las consultas no filtran por fecha; cuentan todo lo que hay en la base.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de inicio del panel de administración (revisar qué archivo HTML la llama).
require_once 'conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    // 1. Total de Títulos de Libros en el Catálogo
    $stmt1 = $conexion->query("SELECT COUNT(*) FROM materialbibliografico");
    $total_libros = (int)$stmt1->fetchColumn();

    // 2. Libros (Títulos Únicos) con al menos un Ejemplar Disponible
    $stmt2 = $conexion->query("SELECT COUNT(DISTINCT e.id_material) FROM ejemplar e INNER JOIN materialbibliografico m ON m.id_material = e.id_material WHERE " . semsad_sql_ejemplar_libre('e') . "");
    $disponibles = (int)$stmt2->fetchColumn();

    // 3. Préstamos y Reservas Activas en Ventanilla
    $stmt3 = $conexion->query("SELECT COUNT(*) FROM prestamo WHERE estado IN ('Activo', 'Vencido', 'Con Mora')");
    $prestamos_activos = (int)$stmt3->fetchColumn();

    // Respuesta de éxito con los tres números.
    semsad_responder([
        'status'            => 'success',
        'total_libros'      => $total_libros,
        'disponibles'       => $disponibles,
        'prestamos_activos' => $prestamos_activos
    ]);

} catch (PDOException $e) {
    // Si falla alguna consulta, se responde con error 500. El detalle técnico solo aparece en modo depuración.
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al consultar dashboard.' . semsad_detalle_error($e)
    ], 500);
}
?>
