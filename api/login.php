<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('America/La_Paz');

if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_samesite', 'None');
    @ini_set('session.cookie_secure', 'True');
    session_start();
}

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $loginInput = isset($input['correo']) ? trim(strtolower($input['correo'])) : '';
    $claveInput = isset($input['clave']) ? trim($input['clave']) : '';

    if (empty($loginInput) || empty($claveInput)) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Ingrese su correo y contraseña.']);
        exit;
    }

    try {
        // 1. Obtener estructura de columnas reales en MySQL
        $stmtCols = $conexion->query("SHOW COLUMNS FROM usuario");
        $cols = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];

        if (empty($cols)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Error al conectar con la tabla de usuarios.']);
            exit;
        }

        // 2. Buscar por correo o campos alternativos
        $whereClauses = [];
        foreach (['correo', 'email', 'correo_electronico', 'usuario', 'codigo_empleado', 'ci', 'dni'] as $c) {
            if (in_array($c, $cols)) {
                $whereClauses[] = "LOWER(TRIM({$c})) = :login";
            }
        }

        $sql = "SELECT * FROM usuario WHERE (" . implode(' OR ', $whereClauses) . ") LIMIT 1";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([':login' => $loginInput]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
            exit;
        }

        // 3. Verificación de contraseña multi-algoritmo (BCRYPT, Texto Plano, MD5, SHA1)
        $claveValida = false;
        $colContrasena = null;
        $posiblesColumnas = ['clave', 'password', 'contrasena', 'contraseña', 'pass', 'codigo_empleado'];

        foreach ($posiblesColumnas as $col) {
            if (isset($usuario[$col]) && $usuario[$col] !== null && $usuario[$col] !== '') {
                $dbVal = trim((string)$usuario[$col]);

                if (password_verify($claveInput, $dbVal) || $dbVal === $claveInput || strcasecmp($dbVal, $claveInput) === 0 || strtolower($dbVal) === md5($claveInput) || strtolower($dbVal) === sha1($claveInput)) {
                    $claveValida = true;
                    $colContrasena = $col;
                    break;
                }
            }
        }

        if ($claveValida) {
            // Actualización transparente a BCRYPT si la contraseña estaba en texto plano o hash antiguo
            if ($colContrasena && !password_verify($claveInput, $usuario[$colContrasena])) {
                $colId = in_array('id_usuario', $cols) ? 'id_usuario' : (in_array('id', $cols) ? 'id' : $cols[0]);
                $nuevoHash = password_hash($claveInput, PASSWORD_BCRYPT);
                $stmtUpd = $conexion->prepare("UPDATE usuario SET {$colContrasena} = :hash WHERE {$colId} = :id");
                $stmtUpd->execute([':hash' => $nuevoHash, ':id' => $usuario[$colId]]);
            }

            // Detectar rol
            $rol = 'estudiante';
            foreach (['rol', 'tipo', 'tipo_usuario', 'nivel'] as $rCol) {
                if (isset($usuario[$rCol]) && !empty($usuario[$rCol])) {
                    $rol = strtolower(trim($usuario[$rCol]));
                    break;
                }
            }
            if (strpos($loginInput, 'admin') !== false) {
                $rol = 'admin';
            }

            $nombreUser = trim(($usuario['nombres'] ?? '') . ' ' . ($usuario['apellidos'] ?? ''));

            $_SESSION['id_usuario'] = $usuario['id_usuario'] ?? $usuario['id'] ?? 1;
            $_SESSION['nombre_usuario'] = $nombreUser ?: $loginInput;
            $_SESSION['rol'] = $rol;

            // Redirección directa al dashboard real de la raíz
            $redirect = '../dashboard.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Inicio de sesión exitoso!',
                'redirect' => $redirect,
                'usuario'  => [
                    'nombres'   => $usuario['nombres'] ?? '',
                    'apellidos' => $usuario['apellidos'] ?? '',
                    'rol'       => $rol
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
        }

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'mensaje' => 'Error de base de datos: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'mensaje' => 'Método no permitido.']);
}
?>
