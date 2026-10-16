<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Inicia sesión nuevamente.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$nombres = trim((string)($input['nombres'] ?? ''));
$apellidos = trim((string)($input['apellidos'] ?? ''));
$telefono = trim((string)($input['telefono'] ?? ''));
$carrera = trim((string)($input['carrera'] ?? ''));
$foto = trim((string)($input['fotografia'] ?? ''));

if ($nombres === '' || $apellidos === '') semsad_responder(['status' => 'error', 'mensaje' => 'Nombre y apellidos son obligatorios.'], 400);
if (mb_strlen($nombres) > 100 || mb_strlen($apellidos) > 100 || mb_strlen($telefono) > 50 || mb_strlen($carrera) > 120) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Uno de los campos supera la longitud permitida.'], 400);
}
if ($foto !== '') {
    if (mb_strlen($foto) > 500 || !filter_var($foto, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($foto, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'La fotografía debe ser una URL pública http/https válida.'], 400);
    }
}

try {
    $columns = $conexion->query("SHOW COLUMNS FROM Usuario")->fetchAll(PDO::FETCH_COLUMN, 0);
    $sets = ['nombres = :n', 'apellidos = :a'];
    $params = [':n' => $nombres, ':a' => $apellidos, ':id' => $id_usuario];

    if (in_array('telefono', $columns, true)) {
        $sets[] = 'telefono = :tel';
        $params[':tel'] = ($telefono !== '' ? $telefono : null);
    }
    $hasCarrera = in_array('carrera', $columns, true);
    $hasFoto = in_array('fotografia', $columns, true);
    if ($hasCarrera) {
        $sets[] = 'carrera = :carrera';
        $params[':carrera'] = ($carrera !== '' ? $carrera : null);
    }
    if ($hasFoto) {
        $sets[] = 'fotografia = :foto';
        $params[':foto'] = ($foto !== '' ? $foto : null);
    }

    $stmt = $conexion->prepare('UPDATE Usuario SET ' . implode(', ', $sets) . ' WHERE id_usuario = :id');
    $stmt->execute($params);

    $nombreCompleto = trim($nombres . ' ' . $apellidos);
    $_SESSION['nombre'] = $nombreCompleto;

    semsad_responder([
        'status' => 'success',
        'mensaje' => 'Tu perfil se actualizó correctamente.',
        'perfil' => [
            'id_usuario' => $id_usuario,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'nombre_completo' => $nombreCompleto,
            'telefono' => $telefono,
            'carrera' => $hasCarrera ? $carrera : '',
            'fotografia' => $hasFoto ? $foto : '',
            'campos_perfil_avanzado_disponibles' => $hasCarrera && $hasFoto
        ],
        'requiere_migracion_perfil_avanzado' => !$hasCarrera || !$hasFoto
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] actualizar_perfil: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo actualizar tu perfil.'], 500);
}
?>