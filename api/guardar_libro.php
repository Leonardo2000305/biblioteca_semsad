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
$id_tipo_material = (int)($input['id_tipo_material'] ?? 1);
$id_editorial = (int)($input['id_editorial'] ?? 0);
$id_categoria = (int)($input['id_categoria'] ?? 0);
$edicion = trim((string)($input['edicion'] ?? ''));
$idioma = trim((string)($input['idioma'] ?? ''));

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
    $columnasMaterial = $conexion->query("SHOW COLUMNS FROM MaterialBibliografico")->fetchAll(PDO::FETCH_COLUMN, 0);
    $tieneEdicion = in_array('edicion', $columnasMaterial, true);
    $tieneIdioma = in_array('idioma', $columnasMaterial, true);

    if ($id_tipo_material <= 0) {
        $tipoMaterialDefault = $conexion->query("SELECT id_tipo_material FROM TipoMaterial WHERE nombre = 'Libro' LIMIT 1")->fetchColumn();
        $id_tipo_material = (int)$tipoMaterialDefault;
    }
    if ($id_categoria <= 0) {
        $categoriaDefault = $conexion->query("
            SELECT c.id_categoria
            FROM Categoria c
            WHERE NOT EXISTS (
                SELECT 1 FROM Categoria hija WHERE hija.id_categoria_padre = c.id_categoria
            )
            ORDER BY c.id_categoria
            LIMIT 1
        ")->fetchColumn();
        $id_categoria = (int)$categoriaDefault;
    }

    $qTipo = $conexion->prepare("SELECT COUNT(*) FROM TipoMaterial WHERE id_tipo_material = :id");
    $qTipo->execute([':id' => $id_tipo_material]);
    $qCategoria = $conexion->prepare("SELECT COUNT(*) FROM Categoria WHERE id_categoria = :id");
    $qCategoria->execute([':id' => $id_categoria]);
    $qEditorialOk = true;
    if ($id_editorial > 0) {
        $qEditorial = $conexion->prepare("SELECT COUNT(*) FROM Editorial WHERE id_editorial = :id");
        $qEditorial->execute([':id' => $id_editorial]);
        $qEditorialOk = (int)$qEditorial->fetchColumn() > 0;
    }

    if (!$ubicacion || $id_tipo_material <= 0 || (int)$qTipo->fetchColumn() <= 0
        || $id_categoria <= 0 || (int)$qCategoria->fetchColumn() <= 0 || !$qEditorialOk) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La ubicación, tipo, editorial o categoría seleccionados no existen en el catálogo. Actualiza las opciones e inténtalo de nuevo.'], 409);
    }
    if (mb_strlen($edicion) > 80 || mb_strlen($idioma) > 50) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La edición admite hasta 80 caracteres y el idioma hasta 50.'], 400);
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

        $set = [
            'titulo = :t',
            'codigo_isbn_issn = :i',
            'agno_publicacion = :y',
            'id_tipo_material = :tipo',
            'id_editorial = :editorial',
            'id_categoria = :categoria'
        ];
        $paramsMaterial = [
            ':t' => $titulo,
            ':i' => ($isbn !== '' ? $isbn : null),
            ':y' => $anio,
            ':tipo' => $id_tipo_material,
            ':editorial' => ($id_editorial > 0 ? $id_editorial : null),
            ':categoria' => $id_categoria,
            ':id' => $id_material
        ];
        if ($tieneEdicion) {
            $set[] = 'edicion = :edicion';
            $paramsMaterial[':edicion'] = ($edicion !== '' ? $edicion : null);
        }
        if ($tieneIdioma) {
            $set[] = 'idioma = :idioma';
            $paramsMaterial[':idioma'] = ($idioma !== '' ? $idioma : null);
        }
        $stmt = $conexion->prepare('UPDATE MaterialBibliografico SET ' . implode(', ', $set) . ' WHERE id_material = :id');
        $stmt->execute($paramsMaterial);

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

        $campos = ['codigo_isbn_issn', 'titulo', 'id_tipo_material', 'id_editorial', 'id_categoria', 'agno_publicacion'];
        $valores = [':isbn', ':t', ':tipo', ':editorial', ':categoria', ':y'];
        $paramsMaterial = [
            ':isbn' => ($isbn !== '' ? $isbn : null),
            ':t' => $titulo,
            ':tipo' => $id_tipo_material,
            ':editorial' => ($id_editorial > 0 ? $id_editorial : null),
            ':categoria' => $id_categoria,
            ':y' => $anio
        ];
        if ($tieneEdicion) {
            $campos[] = 'edicion';
            $valores[] = ':edicion';
            $paramsMaterial[':edicion'] = ($edicion !== '' ? $edicion : null);
        }
        if ($tieneIdioma) {
            $campos[] = 'idioma';
            $valores[] = ':idioma';
            $paramsMaterial[':idioma'] = ($idioma !== '' ? $idioma : null);
        }
        $stmt = $conexion->prepare(
            'INSERT INTO MaterialBibliografico (' . implode(', ', $campos) . ') VALUES (' . implode(', ', $valores) . ')'
        );
        $stmt->execute($paramsMaterial);
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
    $requiereMigracionMetadata = (!$tieneEdicion && $edicion !== '') || (!$tieneIdioma && $idioma !== '');
    if ($requiereMigracionMetadata) {
        $mensaje .= ' Aviso: el catálogo SQL actual no tiene columnas para guardar edición/idioma; esos dos datos no quedaron almacenados.';
    }
    semsad_responder([
        'status' => 'success',
        'mensaje' => $mensaje,
        'id_material' => $id_material,
        'requiere_migracion_metadata' => $requiereMigracionMetadata
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] guardar_libro: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo guardar el libro. Verifica que el ISBN y los datos bibliográficos sean válidos.'], 500);
}
?>