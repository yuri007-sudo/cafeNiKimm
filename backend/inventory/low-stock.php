<?php
declare(strict_types=1);

/**
 * Low Stock Alerts Endpoint
 * GET /backend/inventory/low-stock.php
 * 
 * Query Params:
 * ?include_critical=1    - Only show items at/below critical level (default)
 * ?include_zero=1        - Include out-of-stock items
 * ?limit=50              - Max results (default: 100)
 */

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = checkAuth();

// Query params
$includeCritical = filter_var($_GET['include_critical'] ?? true, FILTER_VALIDATE_BOOLEAN);
$includeZero = filter_var($_GET['include_zero'] ?? false, FILTER_VALIDATE_BOOLEAN);
$limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

try {
    // Build WHERE clause
    $where = [];
    
    if ($includeZero) {
        $where[] = "i.current_stock <= i.critical_level";
    } else {
        $where[] = "i.current_stock <= i.critical_level AND i.current_stock > 0";
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Fetch low stock ingredients
    $stmt = $pdo->prepare("
        SELECT 
            i.ingredient_id as id,
            i.name,
            i.base_unit,
            i.current_stock,
            i.critical_level,
            (i.critical_level - i.current_stock) as shortage,
            ib.barcode,
            CASE 
                WHEN i.current_stock = 0 THEN 'out_of_stock'
                WHEN i.current_stock <= (i.critical_level * 0.5) THEN 'critical'
                ELSE 'low'
            END as stock_status
        FROM ingredients i
        LEFT JOIN ingredient_barcodes ib ON i.ingredient_id = ib.ingredient_id
        {$whereClause}
        ORDER BY i.current_stock ASC, shortage DESC
        LIMIT :limit
    ");
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $lowStockItems = $stmt->fetchAll();
    
    // Calculate summary statistics
    $summaryStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_ingredients,
            SUM(CASE WHEN current_stock <= critical_level THEN 1 ELSE 0 END) as low_stock_count,
            SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as out_of_stock_count
        FROM ingredients
    ");
    $summary = $summaryStmt->fetch();
    
    http_response_code(HTTP_OK);
    echo json_encode([
        'success' => true,
        'data' => $lowStockItems,
        'summary' => [
            'total_ingredients' => (int) $summary['total_ingredients'],
            'low_stock_count' => (int) $summary['low_stock_count'],
            'out_of_stock_count' => (int) $summary['out_of_stock_count'],
            'filters' => [
                'include_critical' => $includeCritical,
                'include_zero' => $includeZero,
                'limit' => $limit
            ]
        ],
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Low stock query failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'code' => 'DATABASE_ERROR'
    ]);
}

exit;