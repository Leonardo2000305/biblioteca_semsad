<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$campo = function (string $k, $defecto = '') use ($input) {
    return $input[$k] ?? $_POST[$k] ?? $defecto;
};

$id_material      = (int)$campo('id_material', 0);
$titulo           = trim((string)$campo('titulo'));
$autoresTexto     = trim((string)$campo('autores'));
$isbn             = trim((string)$campo('isbn'));
$agno_publicacion = (int)$campo('agno_publicacion', date('Y'));
$num_ejemplares   = (int)$campo('num_ejemplares', 1);

if ($titulo === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'El título del libro es obligatorio.']);
}

/* Primer id existente de una tabla de apoyo (tipo, categoría, ubicación). Evita FK vacías en columnas NOT NULL. */
function gl_primer_id(PDO $c, string $tabla, string $col, string $preferido = ''): ?int
{
    $orden = $preferido !== '' ? "ORDER BY (nombre = " . $c->quote($preferido) . ") DESC, $col" : "ORDER BY $col";
    $v = $c->query("SELECT $col FROM $tabla $orden LIMIT 1")->fetchColumn();
    return $v === false ? null : (int)$v;
}

/* Guarda la lista de autores ("A, B; C") en Autor y Material_Autor, reemplazando la anterior. */
function gl_guardar_autores(PDO $c, int $idMaterial, string $texto): void
{
    $c->prepare('DELETE FROM material_autor WHERE id_material = :m')->execute([':m' => $idMaterial]);

    $nombres = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $texto)), 'strlen'));
    $usados = [];
    $orden = 1;
    foreach ($nombres as $nombreCompleto) {
        $partes = preg_split('/\s+/', $nombreCompleto);
        if (count($partes) > 1) {
            $apellidos = array_pop($partes);
            $nombre = implode(' ', $partes);
        } else {
            $nombre = $partes[0];
            $apellidos = '';
        }

        $q = $c->prepare('SELECT id_autor FROM autor WHERE LOWER(nombres) = LOWER(:n) AND LOWER(apellidos) = LOWER(:a) LIMIT 1');
        $q->execute([':n' => $nombre, ':a' => $apellidos]);
        $idAutor = $q->fetchColumn();
        if ($idAutor === false) {
            $c->prepare('INSERT INTO autor (nombres, apellidos) VALUES (:n, :a)')->execute([':n' => $nombre, ':a' => $apellidos]);
            $idAutor = $c->lastInsertId();
        }
        $idAutor = (int)$idAutor;
        if (isset($usados[$idAutor])) {
            continue;   // el mismo autor no se repite
        }
        $usados[$idAutor] = true;

        $c->prepare('INSERT INTO material_autor (id_material, id_autor, orden_autor) VALUES (:m, :a, :o)')
          ->execute([':m' => $idMaterial, ':a' => $idAutor, ':o' => $orden++]);
    }
}

try {
    $conexion->beginTransaction();

    // Columnas reales de la base (cada instalación puede tener un esquema un poco distinto)
    $datos = ['titulo' => $titulo, 'agno_publicacion' => $agno_publicacion];
    if (semsad_tiene_columna($conexion, 'materialbibliografico', 'codigo_isbn_issn')) {
        $datos['codigo_isbn_issn'] = $isbn !== '' ? $isbn : null;
    } elseif (semsad_tiene_columna($conexion, 'materialbibliografico', 'isbn')) {
        $datos['isbn'] = $isbn;
    }
    $autoresEnColumna = semsad_tiene_columna($conexion, 'materialbibliografico', 'autores');
    if ($autoresEnColumna) {
        $datos['autores'] = $autoresTexto;
    }

    if ($id_material > 0) {
        $sets = [];
        $params = [':id' => $id_material];
        foreach ($datos as $col => $val) {
            $sets[] = "$col = :$col";
            $params[":$col"] = $val;
        }
        $conexion->prepare('UPDATE materialbibliografico SET ' . implode(', ', $sets) . ' WHERE id_material = :id')
                 ->execute($params);

        $stmtCount = $conexion->prepare('SELECT COUNT(*) FROM ejemplar WHERE id_material = :id');
        $stmtCount->execute([':id' => $id_material]);
        $countActual = (int)$stmtCount->fetchColumn();

        if ($num_ejemplares > $countActual) {
            $idUbicacion = gl_primer_id($conexion, 'ubicacionfisica', 'id_ubicacion');
            $stmtAddE = $conexion->prepare("INSERT INTO ejemplar (id_material, id_ubicacion, estado_ejemplar) VALUES (:id, :u, 'Disponible')");
            for ($k = 0; $k < $num_ejemplares - $countActual; $k++) {
                $stmtAddE->execute([':id' => $id_material, ':u' => $idUbicacion]);
            }
        }

        $msg = "Libro \"$titulo\" actualizado correctamente.";
    } else {
        // Campos obligatorios del libro nuevo: tipo "Libro" y primera categoría disponible
        $datos['id_tipo_material'] = gl_primer_id($conexion, 'tipomaterial', 'id_tipo_material', 'Libro');
        $datos['id_categoria'] = gl_primer_id($conexion, 'categoria', 'id_categoria');
        $cols = implode(', ', array_keys($datos));
        $marcas = implode(', ', array_map(fn($c) => ":$c", array_keys($datos)));
        $params = [];
        foreach ($datos as $col => $val) {
            $params[":$col"] = $val;
        }
        $conexion->prepare("INSERT INTO materialbibliografico ($cols) VALUES ($marcas)")->execute($params);
        $id_material = (int)$conexion->lastInsertId();

        $idUbicacion = gl_primer_id($conexion, 'ubicacionfisica', 'id_ubicacion');
        $stmtAddE = $conexion->prepare("INSERT INTO ejemplar (id_material, id_ubicacion, estado_ejemplar) VALUES (:id, :u, 'Disponible')");
        $cant = max(1, $num_ejemplares);
        for ($k = 0; $k < $cant; $k++) {
            $stmtAddE->execute([':id' => $id_material, ':u' => $idUbicacion]);
        }

        $msg = "Nuevo libro \"$titulo\" registrado con $cant ejemplar(es) disponible(s).";
    }

    if (!$autoresEnColumna) {
        gl_guardar_autores($conexion, $id_material, $autoresTexto);
    }

    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => $msg, 'id_material' => $id_material]);

} catch (Exception $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    // ISBN/código repetido (la columna es única): mensaje claro en vez del error SQL
    if (strpos($e->getMessage(), '1062') !== false && strpos($e->getMessage(), 'codigo_isbn_issn') !== false) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Ya existe otro libro registrado con ese ISBN/código.'], 409);
    }
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar libro.' . semsad_detalle_error($e)], 500);
}
