<?php
declare(strict_types=1);

/**
 * List All Ingredients Endpoint
 * GET /backend/inventory/index.php
 * 
 * Query Params:
 * ?search=coffee     - Filter by name
 * ?page=1            - Pagination
 * ?limit=20          - Items per page
 * ?low_stock=1       - Only show low stock items
 */

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = checkAuth();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// Filters
$search = trim($_GET['search'] ?? '');
$lowStock = filter_var($_GET['low_stock'] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    // Build query
    $where = [];
    $params = [];
    
    if ($search !== '') {
        $where[] = "LOWER(i.name) LIKE LOWER(?)";
        $params[] = "%{$search}%";
    }
    
    if ($lowStock) {
        $where[] = "i.current_stock <= i.critical_level";
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count total
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM ingredients i
        {$whereClause}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    // Fetch ingredients
    $stmt = $pdo->prepare("
        SELECT 
            i.ingredient_id as id,
            i.name,
            i.base_unit,
            i.current_stock,
            i.critical_level,
            ib.barcode,
            CASE WHEN i.current_stock <= i.critical_level THEN 1 ELSE 0 END as is_low_stock
        FROM ingredients i
        LEFT JOIN ingredient_barcodes ib ON i.ingredient_id = ib.ingredient_id
        {$whereClause}
        ORDER BY i.name ASC
        LIMIT :limit OFFSET :offset
    ");
    
    // Bind limit/offset
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    // Bind other params
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param);
    }
    
    $stmt->execute();
    $ingredients = $stmt->fetchAll();
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $ingredients,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit)
        ],
        'timestamp' => date(DATE_ATOM)
    ]);
    
} catch (PDOException $e) {
    error_log("Ingredient list failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

exit;