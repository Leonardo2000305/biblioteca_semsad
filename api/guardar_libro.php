<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

    $id_material     = (int)($input['id_material'] ?? $_POST['id_material'] ?? 0);
    $titulo          = trim($input['titulo'] ?? $_POST['titulo'] ?? '');
    $autores         = trim($input['autores'] ?? $_POST['autores'] ?? '');
    $isbn            = trim($input['isbn'] ?? $_POST['isbn'] ?? '');
    $agno_publicacion= (int)($input['agno_publicacion'] ?? $_POST['agno_publicacion'] ?? date('Y'));
    $num_ejemplares  = (int)($input['num_ejemplares'] ?? $_POST['num_ejemplares'] ?? 1);

    if (empty($titulo)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El título del libro es obligatorio.']);
    }

    try {
        $conexion->beginTransaction();

        if ($id_material > 0) {
            $stmt = $conexion->prepare("
                UPDATE MaterialBibliografico 
                SET titulo = :t, autores = :a, isbn = :i, agno_publicacion = :y 
                WHERE id_material = :id
            ");
            $stmt->execute([
                ':t'  => $titulo,
                ':a'  => $autores,
                ':i'  => $isbn,
                ':y'  => $agno_publicacion,
                ':id' => $id_material
            ]);

            $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM Ejemplar WHERE id_material = :id");
            $stmtCount->execute([':id' => $id_material]);
            $countActual = (int)$stmtCount->fetchColumn();

            if ($num_ejemplares > $countActual) {
                $diferencia = $num_ejemplares - $countActual;
                $stmtAddE = $conexion->prepare("INSERT INTO Ejemplar (id_material, estado_ejemplar) VALUES (:id, 'Disponible')");
                for ($k = 0; $k < $diferencia; $k++) {
                    $stmtAddE->execute([':id' => $id_material]);
                }
            }

            $msg = "Libro \"$titulo\" actualizado correctamente.";
        } else {
            $stmt = $conexion->prepare("
                INSERT INTO MaterialBibliografico (titulo, autores, isbn, agno_publicacion) 
                VALUES (:t, :a, :i, :y)
            ");
            $stmt->execute([
                ':t' => $titulo,
                ':a' => $autores,
                ':i' => $isbn,
                ':y' => $agno_publicacion
            ]);
            $id_material = (int)$conexion->lastInsertId();

            $stmtAddE = $conexion->prepare("INSERT INTO Ejemplar (id_material, estado_ejemplar) VALUES (:id, 'Disponible')");
            $cant = max(1, $num_ejemplares);
            for ($k = 0; $k < $cant; $k++) {
                $stmtAddE->execute([':id' => $id_material]);
            }

            $msg = "Nuevo libro \"$titulo\" registrado con $cant ejemplar(es) disponible(s).";
        }

        $conexion->commit();
        semsad_responder(['status' => 'success', 'mensaje' => $msg, 'id_material' => $id_material]);

    } catch (Exception $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar libro: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
