<?php
require_once 'conexion.php';
require_once __DIR__ . '/usuario_actual.php';
require_once __DIR__ . '/ayudas_prestamo.php';
semsad_iniciar_sesion();

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Auto-sincronización silenciosa de stock para corregir ejemplares inconsistentes
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

    // Solo la sesión del servidor identifica al usuario (ver usuario_actual.php)
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
        $reservado = in_array($mId, $prestadosPorUsuario);

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

    semsad_responder([
        'status' => 'success',
        'libros' => $libros,
        'data'   => $libros,
        'datos'  => $libros
    ]);

} catch (Exception $e) {
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al obtener los libros.' . semsad_detalle_error($e)
    ], 500);
}
