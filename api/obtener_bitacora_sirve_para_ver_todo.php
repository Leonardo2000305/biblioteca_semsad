<?php
// ARCHIVO: obtener_bitacora_sirve_para_ver_todo.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: dar al personal la BITÁCORA: todo lo que pasa en el sistema (inicios y cierres de sesión,
//   préstamos, devoluciones, cambios de libros, usuarios y multas) y los intentos de cambio de contraseña.
// QUÉ HACE: Solo personal. Une la tabla bitacora con auditoria_recuperacion y responde la lista filtrada,
//   un resumen de tarjetas y las listas de módulos y acciones para llenar los filtros.
//   Filtros (GET): buscar (texto en descripción, correo, nombre o IP), modulo, accion, solo_claves (1 = solo auditorías claves).
// CONECTA CON: principal/admin.html (módulo Bitácora); tabla bitacora (db/migracion_solicitudes_bitacora_pagos.sql).
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    // Une los eventos de la bitácora con los intentos de contraseña (auditoria_recuperacion), con el mismo formato.
    $base = "SELECT b.fecha_hora, b.id_usuario, b.correo, b.accion, b.modulo, b.descripcion,
                    b.ip_origen AS ip, b.clave, 'bitacora' AS origen
               FROM bitacora b
              UNION ALL
             SELECT a.fecha_hora, a.id_usuario, a.correo_ingresado AS correo, 'Cambio de contraseña' AS accion,
                    'Seguridad' AS modulo, a.estado AS descripcion, a.ip_origen AS ip, 1 AS clave, 'recuperacion' AS origen
               FROM auditoria_recuperacion a";

    // Filtros: se arman con parámetros (nunca se pega texto del usuario en la consulta).
    $where = []; $params = [];
    $buscar = trim((string)($_GET['buscar'] ?? ''));
    if ($buscar !== '') {
        $where[] = "(x.descripcion LIKE :q1 OR x.correo LIKE :q2 OR x.ip LIKE :q3 OR x.accion LIKE :q4 OR u.nombres LIKE :q5 OR u.apellidos LIKE :q6)";
        foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) { $params[':' . $k] = '%' . $buscar . '%'; }
    }
    if (!empty($_GET['modulo'])) { $where[] = 'x.modulo = :modulo'; $params[':modulo'] = (string)$_GET['modulo']; }
    if (!empty($_GET['accion'])) { $where[] = 'x.accion = :accion'; $params[':accion'] = (string)$_GET['accion']; }
    if (($_GET['solo_claves'] ?? '') === '1') { $where[] = 'x.clave = 1'; }
    $filtro = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $desde = "FROM ($base) x LEFT JOIN usuario u ON u.id_usuario = x.id_usuario";

    // Lista (máximo 300 eventos, los más recientes primero)
    $stmt = $conexion->prepare("SELECT x.*, TRIM(CONCAT_WS(' ', u.nombres, u.apellidos)) AS nombre $desde $filtro ORDER BY x.fecha_hora DESC LIMIT 300");
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resumen para las tarjetas (sobre todo lo que existe, sin filtros de búsqueda)
    $resumen = $conexion->query("SELECT COUNT(*) AS total,
                                        SUM(x.accion = 'Inicio de sesión') AS inicios,
                                        SUM(x.modulo IN ('Usuarios', 'Inventario', 'Préstamos', 'Multas')) AS cambios,
                                        SUM(x.clave = 1) AS seguridad
                                   $desde")->fetch(PDO::FETCH_ASSOC);

    // Listas para los filtros
    $modulos = $conexion->query("SELECT DISTINCT x.modulo FROM ($base) x ORDER BY x.modulo")->fetchAll(PDO::FETCH_COLUMN);
    $acciones = $conexion->query("SELECT DISTINCT x.accion FROM ($base) x ORDER BY x.accion")->fetchAll(PDO::FETCH_COLUMN);

    semsad_responder([
        'status'   => 'success',
        'eventos'  => $filas,
        'resumen'  => [
            'total'     => (int)($resumen['total'] ?? 0),
            'inicios'   => (int)($resumen['inicios'] ?? 0),
            'cambios'   => (int)($resumen['cambios'] ?? 0),
            'seguridad' => (int)($resumen['seguridad'] ?? 0),
        ],
        'modulos'  => $modulos,
        'acciones' => $acciones,
    ]);
} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_bitacora: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo cargar la bitácora. Si es la primera vez, ejecuta db/migracion_solicitudes_bitacora_pagos.sql en phpMyAdmin.' . semsad_detalle_error($e)], 500);
}
