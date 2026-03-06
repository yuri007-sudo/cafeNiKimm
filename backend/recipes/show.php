<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = checkAuth();

$productId = (int)($_GET['product_id'] ?? 0);

if ($productId <= 0) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Product ID is required']);
    exit;
}

try {
    $productStmt = $pdo->prepare("SELECT product_id, product_name, price, cost, category_id FROM products WHERE product_id = ?");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    
    if (!$product) {
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }
    
    $recipeStmt = $pdo->prepare("
        SELECT 
            pi.ingredient_id,
            pi.quantity,
            pi.unit,
            i.name as ingredient_name,
            i.base_unit,
            i.current_stock,
            i.critical_level
        FROM product_ingredients pi
        INNER JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
        WHERE pi.product_id = ?
        ORDER BY i.name
    ");
    $recipeStmt->execute([$productId]);
    $ingredients = $recipeStmt->fetchAll();
    
    $lowStockIngredients = array_filter($ingredients, fn($i) => $i['current_stock'] <= $i['critical_level']);
    
    http_response_code(HTTP_OK);
    echo json_encode([
        'success' => true,
        'data' => [
            'product' => $product,
            'recipe' => $ingredients,
            'ingredient_count' => count($ingredients),
            'low_stock_count' => count($lowStockIngredients),
            'low_stock_ingredients' => array_values($lowStockIngredients)
        ],
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Recipe fetch failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

exit;