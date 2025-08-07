<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../sql/conn.php';
 
function error_response($msg, $debugInfo = null) {
    $resp = ['success' => false, 'message' => $msg];
    if ($debugInfo) $resp['debug'] = $debugInfo;
    echo json_encode($resp);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
 
if ($method === 'GET') {
    // --- PETICIONES GET ---
 
    // RUTA 1: Búsqueda Universal (por ID, usuario, marca, etc.)
    if (isset($_GET['q'])) {
        $q = trim($_GET['q']);
        if (empty($q)) {
            error_response('El término de búsqueda no puede estar vacío.');
        }

        $searchTerm = "%" . $q . "%";

        $sql = "SELECT 
                    e.*, 
                    u.id as usuario_id, 
                    u.nombre as usuario_nombre
                FROM equipos e
                LEFT JOIN (
                    SELECT a1.asset_id, a1.usuario_id
                    FROM asignaciones a1
                    INNER JOIN (
                        SELECT asset_id, MAX(fecha_asignacion) as max_fecha
                        FROM asignaciones
                        GROUP BY asset_id
                    ) a2 ON a1.asset_id = a2.asset_id AND a1.fecha_asignacion = a2.max_fecha
                ) as ultima_asignacion ON e.asset_id = ultima_asignacion.asset_id
                LEFT JOIN usuarios u ON ultima_asignacion.usuario_id = u.id AND u.activo = 1
                WHERE e.asset_id = ? 
                   OR e.fabricante LIKE ? 
                   OR e.modelo LIKE ? 
                   OR e.serie LIKE ?
                   OR u.nombre LIKE ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issss", $q, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();

        $equipos = [];
        while ($row = $result->fetch_assoc()) {
            $equipos[] = $row;
        }

        if (count($equipos) > 0) {
            echo json_encode(['success' => true, 'equipos' => $equipos]);
        } else {
            error_response('No se encontraron resultados para "' . htmlspecialchars($q) . '".');
        }
        $stmt->close();
        exit;
    }
    // RUTA 2: Obtener usuario por ID (para el modal de edición)
    elseif (isset($_GET['usuario_id'])) {
        $id = intval($_GET['usuario_id']);
        if ($id <= 0) {
            error_response('ID de usuario no válido.');
        }

        $stmt = $conn->prepare("SELECT id, nombre, correo, area, ubicacion FROM usuarios WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($usuario = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'usuario' => $usuario]);
        } else {
            error_response('Usuario no encontrado.');
        }
        $stmt->close();
        exit;
    }
    // RUTA 3: Obtener todos los usuarios (para el modal de asignación)
    elseif (isset($_GET['get_all_users'])) {
        $usuarios = [];
        $result = $conn->query("SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $usuarios[] = $row;
            }
            echo json_encode(['success' => true, 'usuarios' => $usuarios]);
        } else {
            error_response('Error al obtener la lista de usuarios.');
        }
        exit;
    }
    // Si no se proporciona un parámetro GET válido
    else {
        error_response('Parámetros GET no válidos.');
    }

} elseif ($method === 'POST') {
    // --- PETICIONES POST (simulando PUT y DELETE para formularios HTML) ---

    // Usamos un parámetro 'action' o '_method' para diferenciar las operaciones POST
    $action = $_POST['_method'] ?? $_POST['action'] ?? null;

    // ACCIÓN 1: Actualizar usuario
    if ($action === 'PUT') {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $nombre = $_POST['nombre'] ?? '';
        $correo = $_POST['correo'] ?? '';
        $area = $_POST['area'] ?? '';
        $ubicacion = $_POST['ubicacion'] ?? '';

        if ($id <= 0) error_response('ID de usuario no válido para actualizar.');
        if (empty($nombre)) error_response('El nombre no puede estar vacío.');

        $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, correo = ?, area = ?, ubicacion = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $nombre, $correo, $area, $ubicacion, $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente.']);
        } else {
            error_response('Error al actualizar el usuario.', $conn->error);
        }
        $stmt->close();
        exit;
    }
    // ACCIÓN 2: Desactivar Usuario (Borrado Lógico)
    elseif ($action === 'DELETE') {
        $usuario_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if ($usuario_id <= 0) error_response('ID de usuario no válido para desactivar.');

        $stmt = $conn->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
        $stmt->bind_param("i", $usuario_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Usuario desactivado correctamente.']);
        } else {
            error_response('Error al desactivar el usuario o ya estaba inactivo.', $conn->error);
        }
        $stmt->close();
        exit;
    }
    // ACCIÓN 3: Asignar un usuario a un equipo
    elseif ($action === 'assign_user') {
        $asset_id = isset($_POST['asset_id']) ? intval($_POST['asset_id']) : 0;
        $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : 0;
        if ($asset_id <= 0 || $usuario_id <= 0) error_response('IDs de activo o usuario no válidos.');
        
        $stmt = $conn->prepare("INSERT INTO asignaciones (asset_id, usuario_id, fecha_asignacion) VALUES (?, ?, NOW())");
        $stmt->bind_param("ii", $asset_id, $usuario_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Equipo asignado correctamente.']);
        } else {
            error_response('Error al registrar la asignación.', $conn->error);
        }
        $stmt->close();
        exit;
    }
}

// Si ninguna ruta coincide
error_response('Método de petición no válido o no soportado.');