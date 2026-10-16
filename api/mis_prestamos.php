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
        $stmtU = $conexion->prepare("SELECT id_usuario FROM Usuario WHERE LOWER(TRIM(correo)) = LOWER(TRIM(:e)) LIMIT 1");
        $stmtU->execute([':e' => $req_email]);
        $id_usuario = (int)$stmtU->fetchColumn();
    }

    if ($id_usuario <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Usuario no identificado.'], 400);
    }

    $conexion->exec("
        UPDATE Prestamo 
        SET estado = 'Vencido' 
        WHERE estado = 'Activo' AND fecha_limite < NOW()
    ");

    $sql = "SELECT 
                p.id_prestamo,
                m.titulo AS libro_titulo,
                m.autores AS libro_autores,
                m.isbn AS libro_isbn,
                p.fecha_salida,
                p.fecha_limite,
                p.estado
            FROM Prestamo p
            INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            INNER JOIN MaterialBibliografico m ON e.id_material = m.id_material
            WHERE p.id_usuario = :u
            ORDER BY p.id_prestamo DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([':u' => $id_usuario]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $misPrestamos = [];
    foreach ($filas as $f) {
        $misPrestamos[] = [
            'id_prestamo'   => (int)$f['id_prestamo'],
            'titulo'        => $f['libro_titulo'] ?? 'Libro',
            'autores'       => $f['libro_autores'] ?? 'S/N',
            'isbn'          => $f['libro_isbn'] ?? 'S/N',
            'fecha_salida'  => date('d/m/Y H:i', strtotime($f['fecha_salida'])),
            'fecha_limite'  => date('d/m/Y', strtotime($f['fecha_limite'])),
            'estado'        => $f['estado'] ?? 'Activo'
        ];
    }

    semsad_responder([
        'status' => 'success',
        'prestamos' => $misPrestamos,
        'data' => $misPrestamos
    ]);

} catch (Exception $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al obtener tus préstamos: ' . $e->getMessage()], 500);
}
