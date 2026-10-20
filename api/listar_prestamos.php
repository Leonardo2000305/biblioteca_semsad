<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "SELECT 
                p.id_prestamo,
                p.id_usuario,
                u.nombres AS user_nombres,
                u.apellidos AS user_apellidos,
                u.correo AS user_correo,
                u.codigo_empleado AS user_ci,
                m.titulo AS libro_titulo,
                " . semsad_expr_isbn($conexion, 'm') . " AS libro_codigo,
                p.fecha_salida,
                p.fecha_limite,
                p.estado
            FROM prestamo p
            LEFT JOIN usuario u ON p.id_usuario = u.id_usuario
            LEFT JOIN detalle_prestamo dp ON p.id_prestamo = dp.id_prestamo
            LEFT JOIN ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            LEFT JOIN materialbibliografico m ON e.id_material = m.id_material
            WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora')
            ORDER BY p.id_prestamo DESC";

    $stmt = $conexion->query($sql);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Teléfonos en consulta aparte: si la tabla no tiene esa columna, el listado igual funciona
    $telefonos = [];
    try {
        $telefonos = $conexion->query("SELECT id_usuario, telefono FROM usuario")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $ignorar) {
        $telefonos = [];
    }

    $prestamos = [];
    foreach ($filas as $f) {
        $nombreCompleto = trim(($f['user_nombres'] ?? '') . ' ' . ($f['user_apellidos'] ?? ''));
        if (empty($nombreCompleto)) {
            $nombreCompleto = $f['user_correo'] ?? '';
        }
        // Un préstamo nunca debe quedar invisible: si faltan datos se muestra una advertencia.
        if ($nombreCompleto === '') {
            $nombreCompleto = '⚠ Usuario no encontrado (ID ' . (int)$f['id_usuario'] . ')';
        }
        if (empty($f['libro_titulo'])) {
            $f['libro_titulo'] = '⚠ Libro no encontrado (préstamo #' . (int)$f['id_prestamo'] . ')';
        }

        // Para la pantalla: pasado de la fecha límite (o en mora) = Vencido, aunque la base aún diga Activo.
        $limiteTs   = !empty($f['fecha_limite']) ? strtotime($f['fecha_limite']) : false;
        $statusVista = (in_array($f['estado'] ?? '', ['Vencido', 'Con Mora'], true) || ($limiteTs !== false && $limiteTs < time()))
            ? 'Vencido' : 'Activo';

        $prestamos[] = [
            'id'            => (int)$f['id_prestamo'],
            'id_prestamo'   => (int)$f['id_prestamo'],
            'id_usuario'    => (int)$f['id_usuario'],
            'userName'      => $nombreCompleto,
            'usuario'       => $nombreCompleto,
            'userCorreo'    => $f['user_correo'] ?? '',
            'correo'        => $f['user_correo'] ?? '',
            'userCi'        => !empty($f['user_ci']) ? $f['user_ci'] : 'Sin CI',
            'userTelefono'  => (string)($telefonos[(int)$f['id_usuario']] ?? ''),
            'bookTitle'     => $f['libro_titulo'] ?? 'Libro',
            'bookCodigo'    => !empty($f['libro_codigo']) ? $f['libro_codigo'] : ('ID ' . (int)$f['id_prestamo']),
            'fechaSalida'   => !empty($f['fecha_salida']) ? date('d/m/Y H:i', strtotime($f['fecha_salida'])) : 'S/F',
            'libro'         => $f['libro_titulo'] ?? 'Libro',
            'fecha_salida'  => $f['fecha_salida'] ?? '',
            'dueDate'       => !empty($f['fecha_limite']) ? date('Y-m-d', strtotime($f['fecha_limite'])) : 'S/F',
            'fecha_limite'  => !empty($f['fecha_limite']) ? date('Y-m-d', strtotime($f['fecha_limite'])) : 'S/F',
            'status'        => $statusVista,
            'estado'        => $f['estado'] ?? 'Activo'
        ];
    }

    semsad_responder([
        'status'    => 'success',
        'prestamos' => $prestamos,
        'datos'     => $prestamos,
        'data'      => $prestamos
    ]);

} catch (Exception $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al listar préstamos: ' . $e->getMessage()], 500);
}
