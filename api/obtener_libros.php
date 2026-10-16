<?php
require_once 'conexion.php';
semsad_iniciar_sesion();

header('Content-Type: application/json; charset=utf-8');

try {
    $req_id = (int)($_GET['id_usuario'] ?? $_GET['usuario_id'] ?? $_SESSION['id_usuario'] ?? 0);
    $req_email = trim($_GET['correo'] ?? $_GET['email'] ?? $_SESSION['correo'] ?? '');

    $id_usuario = 0;
    if ($req_id > 0) {
        $id_usuario = $req_id;
    } else if (!empty($req_email)) {
        $stmtU = $conexion->prepare("SELECT id_usuario FROM Usuario WHERE LOWER(correo) = LOWER(:e) LIMIT 1");
        $stmtU->execute([':e' => $req_email]);
        $id_usuario = (int)$stmtU->fetchColumn();
    }

    // 1. Obtener libros base
    $sql = "SELECT * FROM MaterialBibliografico ORDER BY id_material DESC";
    $stmt = $conexion->query($sql);
    $materiales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Obtener ejemplares y agrupar disponibilidades en PHP para evitar errores de ONLY_FULL_GROUP_BY
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
        if (strtolower($ej['estado_ejemplar']) === 'disponible') {
            $ejemplaresPorMaterial[$mId]['disponibles']++;
        }
    }

    // 3. Obtener préstamos activos del usuario logueado
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

    // 4. Formatear respuesta estandarizada
    $libros = [];
    foreach ($materiales as $m) {
        $mId = (int)($m['id_material'] ?? $m['id'] ?? 0);
        $disp = $ejemplaresPorMaterial[$mId]['disponibles'] ?? 0;
        $tot  = $ejemplaresPorMaterial[$mId]['total'] ?? 0;
        $reservado = in_array($mId, $prestadosPorUsuario);

        $libros[] = [
            'id_material'      => $mId,
            'id'               => $mId,
            'titulo'           => $m['titulo'] ?? $m['nombre'] ?? 'Sin título',
            'autores'          => $m['autores'] ?? $m['autor'] ?? 'Autor no especificado',
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
