<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../sql/conn.php';

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
    // LEER todos los equipos con paginación y búsqueda
    try {
        // --- PAGINACIÓN: Definir límite y página actual ---
        $limit = 20;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        // --- BÚSQUEDA: Preparar el término de búsqueda si existe ---
        $searchQuery = $_GET['q'] ?? '';
        $whereClauses = [];
        $params = [];
        $types = '';

        if (!empty($searchQuery)) {
            $searchTerm = "%" . $searchQuery . "%";
            $whereClauses[] = "(e.asset_id = ? OR e.tipo LIKE ? OR e.fabricante LIKE ? OR e.modelo LIKE ? OR e.serie LIKE ?)";
            $params[] = $searchQuery;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'issss';
        }

        $whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        // --- PAGINACIÓN: 1. Contar el total de registros (respetando el filtro de búsqueda) ---
        $totalSql = "SELECT COUNT(*) as total FROM equipos e $whereSql";
        $stmtTotal = $conn->prepare($totalSql);
        if (!empty($searchQuery)) {
            $stmtTotal->bind_param($types, ...$params);
        }
        $stmtTotal->execute();
        $totalRecords = $stmtTotal->get_result()->fetch_assoc()['total'];
        $totalPages = ceil($totalRecords / $limit);
        $stmtTotal->close();

        // --- BÚSQUEDA Y PAGINACIÓN: 2. Obtener solo los registros de la página actual (respetando el filtro) ---
        $sql = "SELECT 
                    e.*, 
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
                $whereSql
                ORDER BY e.asset_id DESC LIMIT ? OFFSET ?"; // <-- LIMIT y OFFSET para paginación
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $equipos = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success' => true, 
            'equipos' => $equipos,
            'pagination' => ['currentPage' => $page, 'totalPages' => $totalPages, 'searchQuery' => $searchQuery]
        ]);
    } catch (Exception $e) {
        error_response('Error al obtener los equipos.', $conn);
    }
    exit;
}

if ($action === 'POST') {
    // CREAR un nuevo equipo
    $asset_id = intval($_POST['asset_id'] ?? 0);
    $tipo = $_POST['tipo'] ?? '';
    $fabricante = $_POST['fabricante'] ?? '';
    $modelo = $_POST['modelo'] ?? '';
    $serie = $_POST['serie'] ?? '';
    $usuario_id = intval($_POST['usuario_id'] ?? 0);

    if ($asset_id <= 0) error_response('El Asset ID es inválido.');

    $conn->begin_transaction();
    try {
        // 1. Crear el activo
        $stmt1 = $conn->prepare("INSERT INTO activos (id, descripcion) VALUES (?, ?)");
        $descripcion = "Activo para equipo $tipo $modelo";
        $stmt1->bind_param("is", $asset_id, $descripcion);
        if (!$stmt1->execute()) throw new Exception('Error al crear el activo.');
        $stmt1->close();

        // 2. Crear el equipo
        $stmt2 = $conn->prepare("INSERT INTO equipos (asset_id, tipo, fabricante, modelo, serie) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param("issss", $asset_id, $tipo, $fabricante, $modelo, $serie);
        if (!$stmt2->execute()) throw new Exception('Error al crear el equipo.');
        $stmt2->close();

        // 3. Si se seleccionó un usuario, crear la asignación
        if ($usuario_id > 0) {
            $stmt3 = $conn->prepare("INSERT INTO asignaciones (asset_id, usuario_id, fecha_asignacion) VALUES (?, ?, NOW())");
            $stmt3->bind_param("ii", $asset_id, $usuario_id);
            if (!$stmt3->execute()) throw new Exception('Error al asignar el equipo al usuario.');
            $stmt3->close();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Equipo creado correctamente.']);

    } catch (Exception $e) {
        $conn->rollback();
        if ($conn->errno === 1062) { // Error de clave duplicada
            error_response("El Asset ID '$asset_id' ya existe. Por favor, usa uno diferente.");
        } else {
            error_response($e->getMessage(), $conn);
        }
    }
    exit;
}

if ($action === 'PUT') {
    // ACTUALIZAR un equipo
    $id = intval($_POST['id'] ?? 0);
    $tipo = $_POST['tipo'] ?? '';
    $fabricante = $_POST['fabricante'] ?? '';
    $modelo = $_POST['modelo'] ?? '';
    $serie = $_POST['serie'] ?? '';
    $usuario_id = intval($_POST['usuario_id'] ?? 0);

    if ($id <= 0) error_response('ID de equipo inválido.');

    $conn->begin_transaction();
    try {
        // 1. Actualizar los datos del equipo
        $stmt1 = $conn->prepare("UPDATE equipos SET tipo = ?, fabricante = ?, modelo = ?, serie = ? WHERE id = ?");
        $stmt1->bind_param("ssssi", $tipo, $fabricante, $modelo, $serie, $id);
        if (!$stmt1->execute()) throw new Exception('Error al actualizar el equipo.');
        $stmt1->close();

        // 2. Actualizar la asignación
        $asset_id_res = $conn->query("SELECT asset_id FROM equipos WHERE id = $id");
        $asset_id = $asset_id_res->fetch_assoc()['asset_id'];
        $conn->query("DELETE FROM asignaciones WHERE asset_id = $asset_id"); // Borrar asignación anterior
        if ($usuario_id > 0) { // Si se seleccionó un nuevo usuario, crear la nueva asignación
            $stmt2 = $conn->prepare("INSERT INTO asignaciones (asset_id, usuario_id, fecha_asignacion) VALUES (?, ?, NOW())");
            $stmt2->bind_param("ii", $asset_id, $usuario_id);
            if (!$stmt2->execute()) throw new Exception('Error al reasignar el equipo.');
            $stmt2->close();
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Equipo actualizado correctamente.']);
    } catch (Exception $e) {
        $conn->rollback();
        error_response($e->getMessage(), $conn);
    }
    exit;
}

if ($action === 'DELETE') {
    // BORRAR un equipo
    $id = intval($_POST['id'] ?? 0);
    $asset_id = intval($_POST['asset_id'] ?? 0);
    if ($id <= 0 || $asset_id <= 0) error_response('IDs inválidos para borrar.');

    $conn->begin_transaction();
    try {
        // 1. Borrar asignaciones de forma segura
        $stmt1 = $conn->prepare("DELETE FROM asignaciones WHERE asset_id = ?");
        $stmt1->bind_param("i", $asset_id);
        if (!$stmt1->execute()) throw new Exception('Error al borrar las asignaciones del equipo.');
        $stmt1->close();

        // 2. Borrar el equipo de forma segura
        $stmt2 = $conn->prepare("DELETE FROM equipos WHERE id = ?");
        $stmt2->bind_param("i", $id);
        if (!$stmt2->execute()) throw new Exception('Error al borrar el equipo.');
        $stmt2->close();

        // 3. Borrar el activo de forma segura
        $stmt3 = $conn->prepare("DELETE FROM activos WHERE id = ?");
        $stmt3->bind_param("i", $asset_id);
        if (!$stmt3->execute()) throw new Exception('Error al borrar el activo.');
        $stmt3->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Equipo borrado permanentemente.']);
    } catch (Exception $e) {
        $conn->rollback();
        error_response($e->getMessage(), $conn);
    }
    exit;
}

error_response('Método no soportado.');
