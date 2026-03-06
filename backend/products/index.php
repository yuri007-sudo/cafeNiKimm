<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = checkAuth();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$category = (int)($_GET['category_id'] ?? 0);
$active = $_GET['is_active'] ?? null;

try {
    $where = [];
    $params = [];
    
    if ($category > 0) {
        $where[] = "p.category_id = ?";
        $params[] = $category;
    }
    
    if ($active !== null) {
        $where[] = "p.is_active = ?";
        $params[] = (int)$active;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT 
            p.product_id as id,
            p.product_name as name,
            p.category_id,
            c.category_name,
            p.price,
            p.cost,
            p.is_active,
            p.prep_time,
            (SELECT COUNT(*) FROM product_ingredients WHERE product_id = p.product_id) as ingredient_count,
            (SELECT MIN(i.current_stock / pi.quantity) 
             FROM product_ingredients pi 
             JOIN ingredients i ON pi.ingredient_id = i.ingredient_id 
             WHERE pi.product_id = p.product_id) as max_servings,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM product_ingredients pi 
                    JOIN ingredients i ON pi.ingredient_id = i.ingredient_id 
                    WHERE pi.product_id = p.product_id 
                    AND i.current_stock <= i.critical_level
                ) THEN 1 ELSE 0 
            END as has_low_stock_ingredient
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        {$whereClause}
        ORDER BY p.product_name ASC
        LIMIT :limit OFFSET :offset
    ");
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param);
    }
    $stmt->execute();
    $products = $stmt->fetchAll();
    
    http_response_code(HTTP_OK);
    echo json_encode([
        'success' => true,
        'data' => $products,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit)
        ],
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Product list failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

exit;