<?php
require_once 'conexion.php';
require_once __DIR__ . '/usuario_actual.php';
semsad_iniciar_sesion();

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Auto-sincronización silenciosa de stock para corregir ejemplares inconsistentes
    try {
        $conexion->exec("
            UPDATE Ejemplar 
            SET estado_ejemplar = 'Disponible' 
            WHERE estado_ejemplar = 'Prestado' 
            AND id_ejemplar NOT IN (
                SELECT DISTINCT dp.id_ejemplar 
                FROM Detalle_Prestamo dp 
                INNER JOIN Prestamo p ON dp.id_prestamo = p.id_prestamo 
                WHERE p.estado = 'Activo'
            )
        ");
    } catch (Exception $ex) {}

    // La sesión del servidor manda; los datos del navegador solo cuentan si coinciden (ver usuario_actual.php)
    $usuarioActual = semsad_usuario_actual($conexion, (int)($_GET['id_usuario'] ?? 0), (string)($_GET['correo'] ?? ''));
    $id_usuario = $usuarioActual ? (int)$usuarioActual['id_usuario'] : 0;

    // 2. Obtener libros base
    $sql = "SELECT * FROM MaterialBibliografico ORDER BY id_material DESC";
    $stmt = $conexion->query($sql);
    $materiales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener ejemplares y agrupar disponibilidades en PHP
    $sqlEjemplares = "SELECT id_ejemplar, id_material, estado_ejemplar FROM Ejemplar";
    $stmtE = $conexion->query($sqlEjemplares);
    $ejemplares = $stmtE->fetchAll(PDO::FETCH_ASSOC);

    $ejemplaresPorMaterial = [];
    foreach ($ejemplares as $ej) {
        $mId = (int)$ej['id_material'];
        if (!isset($ejemplaresPorMaterial[$mId])) {
            $ejemplaresPorMaterial[$mId] = ['total' => 0, 'disponibles' => 0];
        }
        $ejemplaresPorMaterial[$mId]['total']++;
        if (strtolower(trim($ej['estado_ejemplar'])) === 'disponible') {
            $ejemplaresPorMaterial[$mId]['disponibles']++;
        }
    }

    // 4. Obtener préstamos activos del usuario logueado
    $prestadosPorUsuario = [];
    if ($id_usuario > 0) {
        $stmtP = $conexion->prepare("
            SELECT DISTINCT e.id_material 
            FROM Prestamo p
            INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            WHERE p.id_usuario = :u AND p.estado = 'Activo'
        ");
        $stmtP->execute([':u' => $id_usuario]);
        $prestadosPorUsuario = $stmtP->fetchAll(PDO::FETCH_COLUMN);
    }

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
            'reservado_por_mi' => $reservado
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
        'mensaje' => 'Error al obtener los libros: ' . $e->getMessage()
    ], 500);
}
