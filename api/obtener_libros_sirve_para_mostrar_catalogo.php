<?php
// ARCHIVO: obtener_libros_sirve_para_mostrar_catalogo.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: mostrar el catálogo de libros con cuántas copias están disponibles.
// QUÉ HACE: Revisa los libros, cuenta sus ejemplares libres y marca los que el usuario tiene prestados o en lista de espera.
//   Responde con la lista de libros en formato JSON.
// CONECTA CON: principal/index.html y principal/admin.html (catálogo); usa usuario_actual_sirve_para_identificar_quien_pide.php y reglas_prestamos_sirve_para_multas_y_plazos.php
require_once 'conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/usuario_actual_sirve_para_identificar_quien_pide.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
semsad_iniciar_sesion();

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Auto-sincronización silenciosa de stock para corregir ejemplares inconsistentes
    // Si esta limpieza de stock falla, el error se ignora y el catálogo sigue cargando.
    try {
        $conexion->exec("
            UPDATE ejemplar 
            SET estado_ejemplar = 'Disponible' 
            WHERE estado_ejemplar IN ('Prestado', 'Reservado') 
            AND id_ejemplar NOT IN (
                SELECT DISTINCT dp.id_ejemplar 
                FROM detalle_prestamo dp 
                INNER JOIN prestamo p ON dp.id_prestamo = p.id_prestamo 
                WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora')
            )
        ");
    } catch (Exception $ex) {}

    // Solo la sesión del servidor identifica al usuario (ver usuario_actual_sirve_para_identificar_quien_pide.php)
    $usuarioActual = semsad_usuario_actual($conexion);
    $id_usuario = $usuarioActual ? (int)$usuarioActual['id_usuario'] : 0;

    // 2. Obtener libros base
    // Autores: columna 'autores' si existe; si no, se arman desde Material_Autor + Autor
    $exprAutores = semsad_expr_autores($conexion, 'm');
    $sql = "SELECT m.*, $exprAutores AS autores_material FROM materialbibliografico m ORDER BY m.id_material DESC";
    $stmt = $conexion->query($sql);
    $materiales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener ejemplares y agrupar disponibilidades en PHP
    $sqlEjemplares = "SELECT e.id_ejemplar, e.id_material, e.estado_ejemplar,
                             CASE WHEN " . semsad_sql_ejemplar_libre('e') . " THEN 1 ELSE 0 END AS libre
                      FROM ejemplar e";
    $stmtE = $conexion->query($sqlEjemplares);
    $ejemplares = $stmtE->fetchAll(PDO::FETCH_ASSOC);

    // Cuenta por libro el total de copias y cuántas están libres (disponibles).
    $ejemplaresPorMaterial = [];
    foreach ($ejemplares as $ej) {
        $mId = (int)$ej['id_material'];
        if (!isset($ejemplaresPorMaterial[$mId])) {
            $ejemplaresPorMaterial[$mId] = ['total' => 0, 'disponibles' => 0];
        }
        $ejemplaresPorMaterial[$mId]['total']++;
        if ((int)$ej['libre'] === 1) {
            $ejemplaresPorMaterial[$mId]['disponibles']++;
        }
    }

    // 4. Obtener préstamos activos del usuario logueado
    $prestadosPorUsuario = [];
    if ($id_usuario > 0) {
        $stmtP = $conexion->prepare("
            SELECT DISTINCT e.id_material 
            FROM prestamo p
            INNER JOIN detalle_prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            WHERE p.id_usuario = :u AND p.estado IN ('Activo', 'Vencido', 'Con Mora')
        ");
        $stmtP->execute([':u' => $id_usuario]);
        $prestadosPorUsuario = $stmtP->fetchAll(PDO::FETCH_COLUMN);
    }

    // 4b. Datos extra (no obligatorios: si la tabla no existe, simplemente no se muestran)
    $categoriaPorMaterial = [];
    try {
        foreach ($conexion->query("SELECT m.id_material, c.nombre FROM materialbibliografico m INNER JOIN categoria c ON c.id_categoria = m.id_categoria")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $categoriaPorMaterial[(int)$f['id_material']] = $f['nombre'];
        }
    } catch (Throwable $ignorar) {}
    // Datos de la lista de espera: cuántas personas esperan cada libro y en qué turno está el usuario.
    $colaPorMaterial = [];
    $miTurno = [];
    semsad_caducar_reservas($conexion);   // hora de corte de las reservas avisadas
    try {
        foreach ($conexion->query("SELECT id_material, COUNT(*) AS n FROM reserva WHERE estado = 'Pendiente' GROUP BY id_material")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $colaPorMaterial[(int)$f['id_material']] = (int)$f['n'];
        }
        if ($id_usuario > 0) {
            $q = $conexion->prepare("SELECT id_material, prioridad FROM reserva WHERE id_usuario = :u AND estado = 'Pendiente'");
            $q->execute([':u' => $id_usuario]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $miTurno[(int)$f['id_material']] = (int)$f['prioridad'];
            }
        }
    } catch (Throwable $ignorar) {}

    // 5. Formatear respuesta estandarizada
    $libros = [];
    foreach ($materiales as $m) {
        $mId = (int)($m['id_material'] ?? $m['id'] ?? 0);
        $disp = $ejemplaresPorMaterial[$mId]['disponibles'] ?? 0;
        $tot  = $ejemplaresPorMaterial[$mId]['total'] ?? 0;
        // revisar: la variable se llama 'reservado', pero en realidad indica si el usuario tiene este libro en préstamo activo.
        $reservado = in_array($mId, $prestadosPorUsuario);

        // Autor: se busca en tres lugares, en este orden; si ninguno tiene texto, se pone un texto por defecto.
        $autorTexto = 'Autor no especificado';
        if (!empty(trim($m['autores'] ?? ''))) {
            $autorTexto = trim($m['autores']);
        } else if (!empty(trim($m['autor'] ?? ''))) {
            $autorTexto = trim($m['autor']);
        } else if (!empty(trim($m['autores_material'] ?? ''))) {
            $autorTexto = trim($m['autores_material']);
        }

        $libros[] = [
            'id_material'      => $mId,
            'id'               => $mId,
            'titulo'           => !empty(trim($m['titulo'] ?? '')) ? trim($m['titulo']) : ($m['nombre'] ?? 'Sin título'),
            'autores'          => $autorTexto,
            'autor'            => $autorTexto,
            'isbn'             => $m['isbn'] ?? $m['codigo_isbn_issn'] ?? $m['codigo_barras'] ?? 'S/N',
            'agno_publicacion' => $m['agno_publicacion'] ?? $m['anio_publicacion'] ?? $m['anio'] ?? 'N/A',
            'stock_disponible' => $disp,
            'disponible'       => $disp,
            'total_ejemplares' => $tot,
            'reservado_por_mi' => $reservado,
            'categoria'        => $categoriaPorMaterial[$mId] ?? '',
            'en_cola'          => $colaPorMaterial[$mId] ?? 0,
            'mi_turno'         => $miTurno[$mId] ?? 0
        ];
    }

    // Respuesta JSON de éxito. La misma lista va en tres claves (libros, data y datos) por compatibilidad con el frontend.
    semsad_responder([
        'status' => 'success',
        'libros' => $libros,
        'data'   => $libros,
        'datos'  => $libros
    ]);

} catch (Exception $e) {
    // Error: responde con código 500 y un mensaje para el usuario.
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al obtener los libros.' . semsad_detalle_error($e)
    ], 500);
}
