<?php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ob_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../sql/conn.php';
if ($conn->connect_error) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos',
        'error' => $conn->connect_error
    ]);
    exit;
}

function error_response($msg, $conn = null, $stmt = null) {
    $error_info = '';
    if ($stmt) $error_info = $stmt->error;
    elseif ($conn) $error_info = $conn->error;
    
    echo json_encode(['success' => false, 'message' => $msg, 'db_error' => $error_info]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['_method'] ?? $method;

if ($action === 'GET') {
    try {
        // Paginación
        $limit = 20;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        // Búsqueda
        $searchQuery = $_GET['q'] ?? '';
        $categoria = $_GET['categoria'] ?? '';
        $whereClauses = [];
        $params = [];
        $types = '';

        if (!empty($searchQuery)) {
            $searchTerm = "%$searchQuery%";
            $whereClauses[] = "(tipo LIKE ? OR descripcion LIKE ? OR ubicacion LIKE ?)";
            array_push($params, $searchTerm, $searchTerm, $searchTerm);
            $types .= 'sss';
        }

        if (!empty($categoria)) {
            $whereClauses[] = "categoria = ?";
            $params[] = $categoria;
            $types .= 's';
        }

        $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // Contar total de registros
        $totalSql = "SELECT COUNT(*) as total FROM inventario $whereSql";
        $stmtTotal = $conn->prepare($totalSql);
        if (!empty($params)) {
            $stmtTotal->bind_param($types, ...$params);
        }
        $stmtTotal->execute();
        $totalRecords = $stmtTotal->get_result()->fetch_assoc()['total'];
        $totalPages = ceil($totalRecords / $limit);
        $stmtTotal->close();

        // Obtener registros
        $sql = "SELECT * FROM inventario $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success' => true,
            'items' => $items,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'searchQuery' => $searchQuery,
                'categoria' => $categoria
            ]
        ]);
    } catch (Exception $e) {
        error_response('Error al obtener el inventario.', $conn);
    }
    exit;
}

if ($action === 'POST') {
    $tipo = $_POST['tipo'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $cantidad = intval($_POST['cantidad'] ?? 0);
    $ubicacion = $_POST['ubicacion'] ?? 'Almacén';
    $categoria = $_POST['categoria'] ?? '';

    if (empty($tipo) || empty($descripcion) || $cantidad < 0 || empty($categoria)) {
        error_response('Todos los campos son requeridos y la cantidad debe ser mayor o igual a 0.');
    }

    try {
        $stmt = $conn->prepare("INSERT INTO inventario (tipo, descripcion, cantidad, ubicacion, categoria) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiss", $tipo, $descripcion, $cantidad, $ubicacion, $categoria);
        
        if (!$stmt->execute()) {
            throw new Exception('Error al crear el item de inventario.');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Item de inventario creado correctamente.',
            'id' => $conn->insert_id
        ]);
    } catch (Exception $e) {
        error_response($e->getMessage(), $conn);
    }
    exit;
}

if ($action === 'PUT') {
    $id = intval($_POST['id'] ?? 0);
    $tipo = $_POST['tipo'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $cantidad = intval($_POST['cantidad'] ?? 0);
    $ubicacion = $_POST['ubicacion'] ?? 'Almacén';
    $categoria = $_POST['categoria'] ?? '';

    if ($id <= 0) error_response('ID de item inválido.');

    try {
        $stmt = $conn->prepare("UPDATE inventario SET tipo = ?, descripcion = ?, cantidad = ?, ubicacion = ?, categoria = ? WHERE id = ?");
        $stmt->bind_param("ssissi", $tipo, $descripcion, $cantidad, $ubicacion, $categoria, $id);
        
        if (!$stmt->execute()) {
            throw new Exception('Error al actualizar el item de inventario.');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Item de inventario actualizado correctamente.'
        ]);
    } catch (Exception $e) {
        error_response($e->getMessage(), $conn);
    }
    exit;
}

if ($action === 'DELETE') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) error_response('ID inválido para borrar.');

    try {
        $stmt = $conn->prepare("DELETE FROM inventario WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if (!$stmt->execute()) {
            throw new Exception('Error al borrar el item de inventario.');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Item de inventario borrado permanentemente.'
        ]);
    } catch (Exception $e) {
        error_response($e->getMessage(), $conn);
    }
    exit;
}

error_response('Método no soportado.');