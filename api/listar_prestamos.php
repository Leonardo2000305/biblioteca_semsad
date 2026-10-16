<?php
require_once 'conexion.php';
semsad_iniciar_sesion();

header('Content-Type: application/json; charset=utf-8');

try {
    $sql = "SELECT 
                p.id_prestamo,
                p.id_usuario,
                u.nombres AS user_nombres,
                u.apellidos AS user_apellidos,
                u.correo AS user_correo,
                u.codigo_empleado AS user_ci,
                u.telefono AS user_telefono,
                m.titulo AS libro_titulo,
                p.fecha_salida,
                p.fecha_limite,
                p.estado
            FROM Prestamo p
            INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
            INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            INNER JOIN MaterialBibliografico m ON e.id_material = m.id_material
            WHERE p.estado = 'Activo'
            ORDER BY p.id_prestamo DESC";

    $stmt = $conexion->query($sql);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $prestamos = [];
    foreach ($filas as $f) {
        $nombreCompleto = trim(($f['user_nombres'] ?? '') . ' ' . ($f['user_apellidos'] ?? ''));
        if (empty($nombreCompleto)) {
            $nombreCompleto = $f['user_correo'] ?? 'Estudiante';
        }

        $prestamos[] = [
            'id'            => (int)$f['id_prestamo'],
            'id_prestamo'   => (int)$f['id_prestamo'],
            'id_usuario'    => (int)$f['id_usuario'],
            'userName'      => $nombreCompleto,
            'usuario'       => $nombreCompleto,
            'userCorreo'    => $f['user_correo'] ?? '',
            'correo'        => $f['user_correo'] ?? '',
            'userCi'        => !empty($f['user_ci']) ? $f['user_ci'] : 'EST-202401',
            'userTelefono'  => $f['user_telefono'] ?? '',
            'bookTitle'     => $f['libro_titulo'] ?? 'Libro',
            'libro'         => $f['libro_titulo'] ?? 'Libro',
            'fecha_salida'  => $f['fecha_salida'] ?? '',
            'dueDate'       => !empty($f['fecha_limite']) ? date('Y-m-d', strtotime($f['fecha_limite'])) : 'S/F',
            'fecha_limite'  => !empty($f['fecha_limite']) ? date('Y-m-d', strtotime($f['fecha_limite'])) : 'S/F',
            'status'        => $f['estado'] ?? 'Activo',
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
