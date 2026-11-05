<?php
// ARCHIVO: registrar_prestamo_sirve_para_pedir_libro_estudiante.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: dejar que un estudiante o docente pida un libro prestado desde su cuenta.
// QUÉ HACE: Identifica al usuario por su sesión y revisa las reglas: cuenta activa, sin multas, turno en la lista de espera, sin repetir el libro y máximo 5 préstamos.
//   Si todo está bien, crea el préstamo a 7 días y responde en JSON.
// CONECTA CON: principal/index.html (botón de pedir libro del estudiante); usa usuario_actual_sirve_para_identificar_quien_pide.php y reglas_prestamos_sirve_para_multas_y_plazos.php
require_once 'conexion_sirve_para_conectar_base_datos.php';
require_once __DIR__ . '/usuario_actual_sirve_para_identificar_quien_pide.php';
require_once __DIR__ . '/reglas_prestamos_sirve_para_multas_y_plazos.php';
semsad_iniciar_sesion();

// Solo se acepta POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lee el JSON enviado; si no llega nada, usa un arreglo vacío.
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

    // Id del libro: se busca en id_material o id_libro, primero en el JSON y luego en el formulario.
    $id_material = (int)(
        $input['id_material'] ?? 
        $_POST['id_material'] ?? 
        $input['id_libro'] ?? 
        $_POST['id_libro'] ?? 0
    );

    // revisar: el id y el correo que llegan del navegador se leen pero no se usan; quien pide el libro sale de la sesión.
    $req_id_user = (int)(
        $input['id_usuario'] ?? 
        $input['id'] ?? 
        $input['usuario_id'] ?? 
        $input['user_id'] ?? 
        $_POST['id_usuario'] ?? 0
    );

    $req_email = trim(
        $input['correo'] ?? 
        $input['email'] ?? 
        $input['userCorreo'] ?? 
        $_POST['correo'] ?? ''
    );


    // Validación: el libro debe tener un id válido.
    if ($id_material <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Identificador de libro no válido.']);
    }

    try {
        // Quién pide el libro: solo la sesión del servidor (ver usuario_actual_sirve_para_identificar_quien_pide.php). Un id/correo enviados
        // por el navegador ya no sirven para pedir libros a nombre de otra persona.
        $usuario = semsad_usuario_actual($conexion);

        // Sin sesión: se pide que vuelva a iniciar sesión.
        if (!$usuario) {
            semsad_responder([
                'status'  => 'error',
                'codigo'  => 'sin_sesion',
                'mensaje' => 'Tu sesión expiró. Por favor, inicia sesión nuevamente para solicitar el libro.'
            ]);
        }

        // Bloquear préstamos a Administradores (rol 1) y Bibliotecarios (rol 2)
        // Permisos: las cuentas de Administrador (rol 1) y Bibliotecario (rol 2) no pueden pedir libros.
        if ((int)($usuario['id_rol'] ?? 0) === 1 || (int)($usuario['id_rol'] ?? 0) === 2 || strtolower(trim($usuario['correo'])) === 'admin@semsad.edu.bo') {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => 'Las cuentas administrativas (Bibliotecario / Admin) no pueden solicitar préstamos de libros. Inicia sesión con una cuenta de Estudiante.'
            ]);
        }

        $id_usuario = (int)$usuario['id_usuario'];
        $nombreCompleto = trim(($usuario['nombres'] ?? '') . ' ' . ($usuario['apellidos'] ?? ''));
        if (empty($nombreCompleto)) {
            $nombreCompleto = $usuario['correo'];
        }

        // Regla: solo se atiende a cuentas Activas. Las bloqueadas o suspendidas reciben un aviso.
        if (($usuario['estado'] ?? 'Activo') !== 'Activo') {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => "Tu cuenta ($nombreCompleto) está " . $usuario['estado'] . ". Contacta a la administración."
            ]);
        }

        // RF13: con multas pendientes no se prestan más libros
        try {
            $stmtMulta = $conexion->prepare("SELECT COUNT(*) FROM multa m INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'");
            $stmtMulta->execute([':u' => $id_usuario]);
            if ((int)$stmtMulta->fetchColumn() > 0) {
                semsad_responder([
                    'status'  => 'error',
                    'mensaje' => "$nombreCompleto, tienes multas pendientes. Regulariza tu cuenta en ventanilla para pedir libros."
                ]);
            }
        } catch (PDOException $ignorar) { /* sin tabla Multa: no se aplica la regla */ }

        // Lista de espera: las copias libres son para los primeros turnos
        semsad_caducar_reservas($conexion);
        // Regla de lista de espera: si hay cola, solo puede llevarse el libro quien tiene turno.
        $motivoTurno = semsad_turno_permite($conexion, $id_usuario, $id_material);
        if ($motivoTurno !== null) {
            semsad_responder(['status' => 'error', 'mensaje' => $motivoTurno]);
        }

        // Verificar si el estudiante ya tiene este mismo libro activo
        $stmtYaPrestado = $conexion->prepare("
            SELECT COUNT(*) FROM prestamo p
            INNER JOIN detalle_prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ('Activo', 'Vencido', 'Con Mora')
        ");
        $stmtYaPrestado->execute([':u' => $id_usuario, ':m' => $id_material]);
        if ((int)$stmtYaPrestado->fetchColumn() > 0) {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => "$nombreCompleto, ya tienes una reserva activa de este libro."
            ]);
        }

        // Límite máximo de 5 préstamos activos
        $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM prestamo WHERE id_usuario = :u AND estado IN ('Activo', 'Vencido', 'Con Mora')");
        $stmtCount->execute([':u' => $id_usuario]);
        if ((int)$stmtCount->fetchColumn() >= 5) {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => "$nombreCompleto, ya tienes el límite máximo de 5 préstamos activos."
            ]);
        }

        // Transacción: se bloquea una copia libre (FOR UPDATE) para que no la pidan dos personas a la vez.
        $conexion->beginTransaction();

        // Buscar ejemplar disponible con bloqueo de fila
        $stmtCheck = $conexion->prepare("SELECT e.id_ejemplar FROM ejemplar e WHERE e.id_material = :m AND " . semsad_sql_ejemplar_libre('e') . " LIMIT 1 FOR UPDATE");
        $stmtCheck->execute([':m' => $id_material]);
        $ejemplar = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        // Si ya no queda ninguna copia libre, se cancela la transacción y se avisa.
        if (!$ejemplar) {
            $conexion->rollBack();
            semsad_responder(['status' => 'error', 'mensaje' => 'No hay copias disponibles de este libro.']);
        }

        $id_ejemplar = (int)$ejemplar['id_ejemplar'];
        // revisar: el bibliotecario que entrega el libro está fijo en el id 1.
        $id_bibliotecario = 1;
        // Plazo del préstamo: 7 días desde hoy.
        $dias_prestamo = 7;
        $fecha_salida = date('Y-m-d H:i:s');
        $fecha_limite = date('Y-m-d H:i:s', strtotime("+$dias_prestamo days"));

        // Insertar préstamo
        $stmtP = $conexion->prepare("INSERT INTO prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado) VALUES (:u, :b, :salida, :limite, 'Activo')");
        $stmtP->execute([
            ':u'      => $id_usuario,
            ':b'      => $id_bibliotecario,
            ':salida' => $fecha_salida,
            ':limite' => $fecha_limite
        ]);
        $id_prestamo = (int)$conexion->lastInsertId();

        // Insertar detalle y actualizar estado del ejemplar
        $stmtD = $conexion->prepare("INSERT INTO detalle_prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)");
        $stmtD->execute([':p' => $id_prestamo, ':e' => $id_ejemplar]);

        $stmtE = $conexion->prepare("UPDATE ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :e");
        $stmtE->execute([':e' => $id_ejemplar]);

        // Confirma la transacción: el préstamo y el cambio de la copia se guardan juntos.
        $conexion->commit();
        // Si el usuario tenía reserva de este libro, la marca como atendida (ya fuera de la transacción).
        semsad_atender_reserva($conexion, $id_usuario, $id_material);

        $fecha_fmt = date('d/m/Y', strtotime("+$dias_prestamo days"));

        // Respuesta JSON de éxito con el id del préstamo y la fecha de devolución.
        semsad_responder([
            'status'           => 'success',
            'mensaje'          => "¡Préstamo registrado exitosamente a nombre de $nombreCompleto! Tienes hasta el $fecha_fmt (7 días) para devolverlo.",
            'id_prestamo'      => $id_prestamo,
            'usuario'          => $nombreCompleto,
            'fecha_devolucion' => $fecha_fmt
        ]);

    } catch (PDOException $e) {
        // Error de base de datos: si la transacción sigue abierta, se deshace.
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        semsad_responder(['status' => 'error', 'mensaje' => 'Error en base de datos.' . semsad_detalle_error($e)], 500);
    }
} else {
    // Si la petición no es POST, responde con error 405.
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido. Use POST.'], 405);
}
