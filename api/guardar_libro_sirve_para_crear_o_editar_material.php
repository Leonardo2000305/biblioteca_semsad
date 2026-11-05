<?php
// ARCHIVO: guardar_libro_sirve_para_crear_o_editar_material.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Crear un libro nuevo en el catálogo o editar uno que ya existe (datos, autores y número de copias).
// QUÉ HACE: Solo personal. Recibe los datos en JSON. Si id_material viene vacío o en 0, crea el libro con sus copias.
// Si trae un id, actualiza el libro y agrega copias si se piden más. Guarda los autores y responde un JSON. Si el ISBN ya existe, responde error 409.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de administración de libros (revisar qué archivo HTML la llama).
require_once 'conexion_sirve_para_conectar_base_datos.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
// Solo personal de la biblioteca puede crear o editar libros.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
// Función auxiliar: busca un dato primero en el JSON, luego en el formulario y, si no está, usa un valor por defecto.
$campo = function (string $k, $defecto = '') use ($input) {
    return $input[$k] ?? $_POST[$k] ?? $defecto;
};

// Lee los datos del libro. Si id_material es 0, se trata de un libro nuevo.
$id_material      = (int)$campo('id_material', 0);
$titulo           = trim((string)$campo('titulo'));
$autoresTexto     = trim((string)$campo('autores'));
$isbn             = trim((string)$campo('isbn'));
$agno_publicacion = (int)$campo('agno_publicacion', date('Y'));
$num_ejemplares   = (int)$campo('num_ejemplares', 1);

// Validación: el título es obligatorio.
if ($titulo === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'El título del libro es obligatorio.']);
}

/* Primer id existente de una tabla de apoyo (tipo, categoría, ubicación). Evita FK vacías en columnas NOT NULL. */
function gl_primer_id(PDO $c, string $tabla, string $col, string $preferido = ''): ?int
{
    // Si se indica un nombre preferido (por ejemplo «Libro»), ese registro se toma primero. Si no, se toma el primero por id.
    $orden = $preferido !== '' ? "ORDER BY (nombre = " . $c->quote($preferido) . ") DESC, $col" : "ORDER BY $col";
    $v = $c->query("SELECT $col FROM $tabla $orden LIMIT 1")->fetchColumn();
    return $v === false ? null : (int)$v;
}

/* Guarda la lista de autores ("A, B; C") en Autor y Material_Autor, reemplazando la anterior. */
function gl_guardar_autores(PDO $c, int $idMaterial, string $texto): void
{
    // Borra la lista de autores anterior de este libro. Después se guarda la nueva.
    $c->prepare('DELETE FROM material_autor WHERE id_material = :m')->execute([':m' => $idMaterial]);

    // Separa la lista por comas o punto y coma, y quita los nombres vacíos.
    $nombres = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $texto)), 'strlen'));
    $usados = [];
    $orden = 1;
    foreach ($nombres as $nombreCompleto) {
        // La última palabra se toma como apellido y el resto como nombre. Si solo hay una palabra, se guarda como nombre.
        $partes = preg_split('/\s+/', $nombreCompleto);
        if (count($partes) > 1) {
            $apellidos = array_pop($partes);
            $nombre = implode(' ', $partes);
        } else {
            $nombre = $partes[0];
            $apellidos = '';
        }

        // Busca si el autor ya existe (sin importar mayúsculas). Si no existe, lo crea.
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

        // Relaciona el autor con el libro y guarda su orden dentro de la lista.
        $c->prepare('INSERT INTO material_autor (id_material, id_autor, orden_autor) VALUES (:m, :a, :o)')
          ->execute([':m' => $idMaterial, ':a' => $idAutor, ':o' => $orden++]);
    }
}

try {
    // Todo el guardado va en una transacción: si algo falla, no queda un libro a medias.
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

    // Si ya hay id, se actualiza el libro existente.
    if ($id_material > 0) {
        $sets = [];
        $params = [':id' => $id_material];
        foreach ($datos as $col => $val) {
            $sets[] = "$col = :$col";
            $params[":$col"] = $val;
        }
        // Actualiza los datos del libro con los valores recibidos.
        $conexion->prepare('UPDATE materialbibliografico SET ' . implode(', ', $sets) . ' WHERE id_material = :id')
                 ->execute($params);

        $stmtCount = $conexion->prepare('SELECT COUNT(*) FROM ejemplar WHERE id_material = :id');
        $stmtCount->execute([':id' => $id_material]);
        $countActual = (int)$stmtCount->fetchColumn();

        // Si ahora se piden más copias que las que hay, se agregan las que faltan: disponibles y en la primera ubicación.
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
        // Arma el INSERT: la lista de columnas y los marcadores (:titulo, :agno_publicacion, etc.) que se llenan después.
        $cols = implode(', ', array_keys($datos));
        $marcas = implode(', ', array_map(fn($c) => ":$c", array_keys($datos)));
        $params = [];
        foreach ($datos as $col => $val) {
            $params[":$col"] = $val;
        }
        $conexion->prepare("INSERT INTO materialbibliografico ($cols) VALUES ($marcas)")->execute($params);
        // Toma el id del libro recién creado para usarlo al crear sus copias.
        $id_material = (int)$conexion->lastInsertId();

        $idUbicacion = gl_primer_id($conexion, 'ubicacionfisica', 'id_ubicacion');
        $stmtAddE = $conexion->prepare("INSERT INTO ejemplar (id_material, id_ubicacion, estado_ejemplar) VALUES (:id, :u, 'Disponible')");
        // Como mínimo se crea una copia, aunque el número recibido sea 0 o menor.
        $cant = max(1, $num_ejemplares);
        for ($k = 0; $k < $cant; $k++) {
            $stmtAddE->execute([':id' => $id_material, ':u' => $idUbicacion]);
        }

        $msg = "Nuevo libro \"$titulo\" registrado con $cant ejemplar(es) disponible(s).";
    }

    // Si la tabla no tiene columna de autores, los autores se guardan en las tablas autor y material_autor.
    if (!$autoresEnColumna) {
        gl_guardar_autores($conexion, $id_material, $autoresTexto);
    }

    // Confirma todos los cambios del libro.
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
