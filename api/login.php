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
        // 1. Detectar todas las columnas de la tabla usuario
        $stmtCols = $conexion->query("SHOW COLUMNS FROM usuario");
        $cols = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];

        if (empty($cols)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo acceder a la tabla de usuarios.']);
            exit;
        }

        // 2. Construir la consulta WHERE buscando por correo, email, usuario o código
        $whereClauses = [];
        foreach (['correo', 'email', 'correo_electronico', 'usuario', 'codigo_empleado', 'ci', 'dni', 'codigo'] as $col) {
            if (in_array($col, $cols)) {
                $whereClauses[] = "LOWER(TRIM({$col})) = :login";
            }
        }

        if (empty($whereClauses)) {
            $whereClauses[] = "1=1";
        }

        $sql = "SELECT * FROM usuario WHERE (" . implode(' OR ', $whereClauses) . ") LIMIT 1";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([':login' => $loginInput]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El correo o la contraseña son incorrectos.']);
            exit;
        }

        // 3. Verificación de contraseña multi-algoritmo (BCRYPT, Texto Plano, MD5, SHA1, SHA256)
        $claveValida = false;
        $columnaContrasenaUsada = null;

        $posiblesColClave = ['clave', 'password', 'contrasena', 'contraseña', 'pass', 'codigo_empleado', 'ci'];
        
        foreach ($posiblesColClave as $col) {
            if (isset($usuario[$col]) && $usuario[$col] !== null && $usuario[$col] !== '') {
                $dbVal = trim((string)$usuario[$col]);

                // Check A: BCRYPT / Argon2
                if (password_verify($claveInput, $dbVal)) {
                    $claveValida = true;
                    $columnaContrasenaUsada = $col;
                    break;
                }

                // Check B: Texto plano exacto (sensible e insensible a mayúsculas)
                if ($dbVal === $claveInput || strcasecmp($dbVal, $claveInput) === 0) {
                    $claveValida = true;
                    $columnaContrasenaUsada = $col;
                    break;
                }

                // Check C: Hash MD5
                if (strtolower($dbVal) === md5($claveInput) || strtolower($dbVal) === md5(trim($claveInput))) {
                    $claveValida = true;
                    $columnaContrasenaUsada = $col;
                    break;
                }

                // Check D: Hash SHA1
                if (strtolower($dbVal) === sha1($claveInput) || strtolower($dbVal) === sha1(trim($claveInput))) {
                    $claveValida = true;
                    $columnaContrasenaUsada = $col;
                    break;
                }

                // Check E: Hash SHA256
                if (strtolower($dbVal) === hash('sha256', $claveInput)) {
                    $claveValida = true;
                    $columnaContrasenaUsada = $col;
                    break;
                }
            }
        }

        if ($claveValida) {
            // Si la clave estaba en texto plano o MD5/SHA1, actualizarla a BCRYPT automáticamente
            if ($columnaContrasenaUsada && !password_verify($claveInput, $usuario[$columnaContrasenaUsada])) {
                $colId = in_array('id_usuario', $cols) ? 'id_usuario' : (in_array('id', $cols) ? 'id' : $cols[0]);
                $nuevoHash = password_hash($claveInput, PASSWORD_BCRYPT);
                $stmtUpd = $conexion->prepare("UPDATE usuario SET {$columnaContrasenaUsada} = :hash WHERE {$colId} = :id");
                $stmtUpd->execute([':hash' => $nuevoHash, ':id' => $usuario[$colId]]);
            }

            // Detectar Nombre y Apellidos
            $nombres = $usuario['nombres'] ?? $usuario['nombre'] ?? '';
            $apellidos = $usuario['apellidos'] ?? $usuario['apellido'] ?? '';
            $nombreCompleto = trim("{$nombres} {$apellidos}");

            // Detectar Rol
            $rol = 'estudiante';
            foreach (['rol', 'tipo', 'tipo_usuario', 'nivel', 'cargo'] as $rCol) {
                if (isset($usuario[$rCol]) && !empty($usuario[$rCol])) {
                    $rol = strtolower(trim($usuario[$rCol]));
                    break;
                }
            }
            if (strpos($loginInput, 'admin') !== false || strpos(strtolower($nombreCompleto), 'admin') !== false) {
                $rol = 'admin';
            }

            $idUsuario = $usuario['id_usuario'] ?? $usuario['id'] ?? 1;

            $_SESSION['id_usuario'] = $idUsuario;
            $_SESSION['nombre_usuario'] = $nombreCompleto ?: $loginInput;
            $_SESSION['rol'] = $rol;

            $redirect = ($rol === 'admin' || strpos($rol, 'admin') !== false) ? 'admin/dashboard.html' : 'dashboard.html';

            echo json_encode([
                'status'   => 'success',
                'mensaje'  => '¡Inicio de sesión exitoso!',
                'redirect' => $redirect,
                'usuario'  => [
                    'nombres'   => $nombres,
                    'apellidos' => $apellidos,
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
