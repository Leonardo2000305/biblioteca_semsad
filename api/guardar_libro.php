<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$input = semsad_leer_json();
$id_material = (int)($input['id_material'] ?? 0);
$titulo = trim((string)($input['titulo'] ?? ''));
$autoresTexto = trim((string)($input['autores'] ?? ''));
$isbn = trim((string)($input['isbn'] ?? ''));
$anio = (int)($input['agno_publicacion'] ?? date('Y'));
$numEjemplares = (int)($input['num_ejemplares'] ?? 1);

if ($titulo === '' || $autoresTexto === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'El título y al menos un autor son obligatorios.'], 400);
}
if ($anio < 1400 || $anio > ((int)date('Y') + 1)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El año de publicación no es válido.'], 400);
}
if ($numEjemplares < 1 || $numEjemplares > 500) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La cantidad de ejemplares debe estar entre 1 y 500.'], 400);
}
if ($isbn === '') $isbn = '';

try {
    $conexion->beginTransaction();

    $ubicacion = $conexion->query("SELECT id_ubicacion FROM UbicacionFisica ORDER BY id_ubicacion LIMIT 1")->fetchColumn();
    $tipoMaterial = $conexion->query("SELECT id_tipo_material FROM TipoMaterial WHERE nombre = 'Libro' LIMIT 1")->fetchColumn();
    $categoria = $conexion->query("
        SELECT c.id_categoria
        FROM Categoria c
        WHERE NOT EXISTS (
            SELECT 1 FROM Categoria hija WHERE hija.id_categoria_padre = c.id_categoria
        )
        ORDER BY c.id_categoria
        LIMIT 1
    ")->fetchColumn();

    if (!$ubicacion || !$tipoMaterial || !$categoria) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Falta configurar al menos una ubicación, tipo Libro y categoría en el catálogo.'], 409);
    }

    if ($id_material > 0) {
        $check = $conexion->prepare("SELECT id_material FROM MaterialBibliografico WHERE id_material = :id LIMIT 1");
        $check->execute([':id' => $id_material]);
        if (!$check->fetchColumn()) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'El libro que intentas editar no existe.'], 404);
        }

        $dup = $conexion->prepare("
            SELECT COUNT(*) FROM MaterialBibliografico
            WHERE codigo_isbn_issn = :isbn AND id_material <> :id
        ");
        $dup->execute([':isbn' => ($isbn !== '' ? $isbn : null), ':id' => $id_material]);
        if ((int)$dup->fetchColumn() > 0) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'Ese ISBN ya está asignado a otro título.'], 409);
        }

        $stmt = $conexion->prepare("
            UPDATE MaterialBibliografico
            SET titulo = :t, codigo_isbn_issn = :i, agno_publicacion = :y
            WHERE id_material = :id
        ");
        $stmt->execute([
            ':t' => $titulo, ':i' => ($isbn !== '' ? $isbn : null),
            ':y' => $anio, ':id' => $id_material
        ]);

        $stmtCount = $conexion->prepare("
            SELECT COUNT(*) FROM Ejemplar
            WHERE id_material = :id AND estado_ejemplar <> 'Baja'
        ");
        $stmtCount->execute([':id' => $id_material]);
        $countActual = (int)$stmtCount->fetchColumn();
        $diferencia = max(0, $numEjemplares - $countActual);

        if ($diferencia > 0) {
            $stmtAdd = $conexion->prepare("
                INSERT INTO Ejemplar (id_material, id_adquisicion, id_ubicacion, estado_ejemplar, condicion_fisica)
                VALUES (:id, NULL, :ubicacion, 'Disponible', 'Bueno')
            ");
            for ($i = 0; $i < $diferencia; $i++) {
                $stmtAdd->execute([':id' => $id_material, ':ubicacion' => $ubicacion]);
            }
        }

        $totalFinal = $countActual + $diferencia;
        $mensajeCopias = ($numEjemplares < $countActual)
            ? " No se redujo la cantidad de copias porque hay ejemplares existentes que deben conservar su historial; se mantienen $countActual."
            : '';
        $mensaje = "Libro \"$titulo\" actualizado correctamente. Total de ejemplares: $totalFinal." . $mensajeCopias;
    } else {
        if ($isbn !== '') {
            $dup = $conexion->prepare("SELECT COUNT(*) FROM MaterialBibliografico WHERE codigo_isbn_issn = :isbn");
            $dup->execute([':isbn' => $isbn]);
            if ((int)$dup->fetchColumn() > 0) {
                $conexion->rollBack();
                semsad_responder(['status' => 'error', 'mensaje' => 'Ese ISBN ya está registrado.'], 409);
            }
        }

        $stmt = $conexion->prepare("
            INSERT INTO MaterialBibliografico
                (codigo_isbn_issn, titulo, id_tipo_material, id_editorial, id_categoria, agno_publicacion)
            VALUES (:isbn, :t, :tipo, NULL, :categoria, :y)
        ");
        $stmt->execute([
            ':isbn' => ($isbn !== '' ? $isbn : null), ':t' => $titulo,
            ':tipo' => $tipoMaterial, ':categoria' => $categoria, ':y' => $anio
        ]);
        $id_material = (int)$conexion->lastInsertId();

        $stmtAdd = $conexion->prepare("
            INSERT INTO Ejemplar (id_material, id_adquisicion, id_ubicacion, estado_ejemplar, condicion_fisica)
            VALUES (:id, NULL, :ubicacion, 'Disponible', 'Bueno')
        ");
        for ($i = 0; $i < $numEjemplares; $i++) {
            $stmtAdd->execute([':id' => $id_material, ':ubicacion' => $ubicacion]);
        }
        $mensaje = "Nuevo libro \"$titulo\" registrado con $numEjemplares ejemplar(es). Se asignó una categoría inicial que puedes revisar en el catálogo.";
    }

    // La base almacena autoría en Autor + Material_Autor, no en una columna autores del libro.
    $del = $conexion->prepare("DELETE FROM Material_Autor WHERE id_material = :id");
    $del->execute([':id' => $id_material]);

    $nombresAutores = array_values(array_unique(array_filter(array_map('trim', explode(',', $autoresTexto)), static function($v) {
        return $v !== '';
    })));
    $stmtFind = $conexion->prepare("
        SELECT id_autor FROM Autor
        WHERE LOWER(CONCAT(TRIM(nombres), ' ', TRIM(apellidos))) = LOWER(:nombre)
        LIMIT 1
    ");
    $stmtInsertAutor = $conexion->prepare("INSERT INTO Autor (nombres, apellidos) VALUES (:n, :a)");
    $stmtLink = $conexion->prepare("
        INSERT INTO Material_Autor (id_material, id_autor, orden_autor)
        VALUES (:m, :a, :orden)
    ");

    foreach ($nombresAutores as $idx => $nombreAutor) {
        $stmtFind->execute([':nombre' => $nombreAutor]);
        $idAutor = (int)$stmtFind->fetchColumn();
        if ($idAutor <= 0) {
            $partes = preg_split('/\s+/', $nombreAutor, 2);
            $n = trim($partes[0] ?? '');
            $a = trim($partes[1] ?? '');
            if ($n === '') continue;
            $stmtInsertAutor->execute([':n' => $n, ':a' => $a !== '' ? $a : '']);
            $idAutor = (int)$conexion->lastInsertId();
        }
        $stmtLink->execute([':m' => $id_material, ':a' => $idAutor, ':orden' => $idx + 1]);
    }

    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => $mensaje, 'id_material' => $id_material]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] guardar_libro: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo guardar el libro. Verifica que el ISBN y los datos bibliográficos sean válidos.'], 500);
}
?>