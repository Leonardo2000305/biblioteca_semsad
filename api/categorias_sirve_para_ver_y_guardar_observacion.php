<?php
// ARCHIVO: categorias_sirve_para_ver_y_guardar_observacion.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: ver las categorías de la biblioteca y guardar su observación (texto corto).
// QUÉ HACE: Solo personal. GET devuelve todas las categorías con su observación.
//   POST recibe {id_categoria, observacion} y guarda la observación en la tabla categoria.
//   Una observación vacía se guarda como NULL. Máximo 255 caracteres.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; obtener_libros_sirve_para_mostrar_catalogo.php (muestra la observación en el catálogo).
require_once 'conexion_sirve_para_conectar_base_datos.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $filas = $conexion->query("SELECT id_categoria, nombre, id_categoria_padre, observacion FROM categoria ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
        semsad_responder(['status' => 'success', 'categorias' => $filas]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
    }

    $entrada = semsad_leer_json();
    $idCategoria = (int)($entrada['id_categoria'] ?? 0);
    $observacion = trim((string)($entrada['observacion'] ?? ''));

    if ($idCategoria <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Falta la categoría.'], 400);
    }
    if (mb_strlen($observacion) > 255) {
        semsad_responder(['status' => 'error', 'mensaje' => 'La observación no puede pasar de 255 caracteres.'], 400);
    }

    $existe = $conexion->prepare("SELECT nombre FROM categoria WHERE id_categoria = :id");
    $existe->execute([':id' => $idCategoria]);
    $nombre = $existe->fetchColumn();
    if ($nombre === false) {
        semsad_responder(['status' => 'error', 'mensaje' => 'La categoría no existe.'], 404);
    }

    $guardar = $conexion->prepare("UPDATE categoria SET observacion = :o WHERE id_categoria = :id");
    $guardar->execute([':o' => $observacion === '' ? null : $observacion, ':id' => $idCategoria]);

    semsad_bitacora('Editar categoría', 'Inventario', 'Observación de la categoría: ' . $nombre, false);
    semsad_responder(['status' => 'success', 'mensaje' => 'Observación guardada.']);
} catch (Throwable $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al procesar la categoría.' . semsad_detalle_error($e)], 500);
}
