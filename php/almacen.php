<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once('../sql/conn.php');

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos'
    ]);
    exit;
}

// Función helper para errores
function error_response($msg, $conn = null) {
    $error_info = $conn ? $conn->error : '';
    echo json_encode([
        'success' => false,
        'message' => $msg,
        'db_error' => $error_info
    ]);
    exit;
}

// Obtener método HTTP
$method = $_SERVER['REQUEST_METHOD'];
if (isset($_POST['_method'])) {
    $method = $_POST['_method'];
}

try {
    switch ($method) {
        case 'GET':
            // Parámetros de paginación y búsqueda
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $search = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
            $categoria = isset($_GET['categoria']) ? $conn->real_escape_string($_GET['categoria']) : '';

            // Construir consulta
            $where = [];
            $params = [];
            
            if (!empty($search)) {
                $where[] = "(tipo LIKE ? OR descripcion LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            if (!empty($categoria)) {
                $where[] = "categoria = ?";
                $params[] = $categoria;
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Contar total
            $countQuery = "SELECT COUNT(*) as total FROM inventario $whereClause";
            $stmt = $conn->prepare($countQuery);
            if (!empty($params)) {
                $types = str_repeat('s', count($params));
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];

            // Obtener items
            $query = "SELECT * FROM inventario $whereClause ORDER BY id DESC LIMIT ? OFFSET ?";
            $stmt = $conn->prepare($query);
            if (!empty($params)) {
                $params[] = $limit;
                $params[] = $offset;
                $types = str_repeat('s', count($params) - 2) . 'ii';
                $stmt->bind_param($types, ...$params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);

            echo json_encode([
                'success' => true,
                'items' => $items,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => ceil($total / $limit),
                    'total' => $total
                ]
            ]);
            break;

        case 'POST':
            $stmt = $conn->prepare("INSERT INTO inventario (tipo, descripcion, cantidad, ubicacion, categoria) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiss", 
                $_POST['tipo'],
                $_POST['descripcion'],
                $_POST['cantidad'],
                $_POST['ubicacion'],
                $_POST['categoria']
            );
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Item creado correctamente'
                ]);
            } else {
                throw new Exception("Error al crear el item");
            }
            break;

        case 'PUT':
            $stmt = $conn->prepare("UPDATE inventario SET tipo=?, descripcion=?, cantidad=?, ubicacion=?, categoria=? WHERE id=?");
            $stmt->bind_param("ssissi", 
                $_POST['tipo'],
                $_POST['descripcion'],
                $_POST['cantidad'],
                $_POST['ubicacion'],
                $_POST['categoria'],
                $_POST['id']
            );
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Item actualizado correctamente'
                ]);
            } else {
                throw new Exception("Error al actualizar el item");
            }
            break;

        case 'DELETE':
            $stmt = $conn->prepare("DELETE FROM inventario WHERE id=?");
            $stmt->bind_param("i", $_POST['id']);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Item eliminado correctamente'
                ]);
            } else {
                throw new Exception("Error al eliminar el item");
            }
            break;

        default:
            throw new Exception("Método no permitido");
    }
} catch (Exception $e) {
    error_response($e->getMessage(), $conn);
}

$conn->close();