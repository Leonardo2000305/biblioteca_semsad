<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $sql = "
        SELECT
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
        WHERE p.estado IN ('Activo', 'Con Mora')
        ORDER BY p.id_prestamo DESC
    ";
    $filas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $hoy = strtotime(date('Y-m-d'));
    $prestamos = [];

    foreach ($filas as $f) {
        $idPrestamo = (int)$f['id_prestamo'];
        $limiteTs = !empty($f['fecha_limite']) ? strtotime($f['fecha_limite']) : false;
        $nombre = trim(($f['user_nombres'] ?? '') . ' ' . ($f['user_apellidos'] ?? ''));
        if ($nombre === '') $nombre = (string)($f['user_correo'] ?? 'Estudiante');

        $vencido = ($f['estado'] === 'Con Mora')
            || ($limiteTs !== false && $limiteTs < $hoy);
        $fechaSalida = !empty($f['fecha_salida']) ? date('Y-m-d', strtotime($f['fecha_salida'])) : '';
        $fechaLimite = $limiteTs !== false ? date('Y-m-d', $limiteTs) : 'S/F';
        $diasRestantes = $limiteTs !== false ? (int)floor(($limiteTs - $hoy) / 86400) : 0;

        $prestamos[] = [
            'id' => $idPrestamo,
            'id_prestamo' => $idPrestamo,
            'id_usuario' => (int)$f['id_usuario'],
            'userName' => $nombre,
            'usuario' => $nombre,
            'userCorreo' => (string)($f['user_correo'] ?? ''),
            'correo' => (string)($f['user_correo'] ?? ''),
            'userCi' => (string)($f['user_ci'] ?? ''),
            'userTelefono' => (string)($f['user_telefono'] ?? ''),
            'bookTitle' => (string)($f['libro_titulo'] ?? 'Libro'),
            'libro' => (string)($f['libro_titulo'] ?? 'Libro'),
            'code' => 'PRE-' . $idPrestamo,
            'issueDate' => $fechaSalida,
            'fecha_salida' => $fechaSalida,
            'dueDate' => $fechaLimite,
            'fecha_limite' => $fechaLimite,
            'daysLeft' => max(0, $diasRestantes),
            'overdueDays' => $vencido && $limiteTs !== false ? max(1, (int)floor(($hoy - $limiteTs) / 86400)) : 0,
            'status' => $vencido ? 'Vencido' : 'Activo',
            'estado' => (string)$f['estado']
        ];
    }

    semsad_responder([
        'status' => 'success',
        'prestamos' => $prestamos,
        'datos' => $prestamos,
        'data' => $prestamos
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] listar_prestamos: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron listar los préstamos.'], 500);
}
?>