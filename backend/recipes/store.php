<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';
require __DIR__ . '/../middleware/role.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = checkAuth();
checkRole(['admin'], $currentUser);

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$errors = [];
$productId = (int)($data['product_id'] ?? 0);
$ingredients = $data['ingredients'] ?? [];

if ($productId <= 0) {
    $errors['product_id'] = 'Valid product ID is required';
}

if (empty($ingredients) || !is_array($ingredients)) {
    $errors['ingredients'] = 'At least one ingredient is required';
}

foreach ($ingredients as $index => $ing) {
    if (empty($ing['ingredient_id'])) {
        $errors["ingredients[$index]"] = 'Ingredient ID is required';
    }
    if (!isset($ing['quantity']) || (float)$ing['quantity'] <= 0) {
        $errors["ingredients[$index]"] = 'Quantity must be greater than 0';
    }
    if (empty($ing['unit']) || !in_array($ing['unit'], VALID_BASE_UNITS, true)) {
        $errors["ingredients[$index]"] = "Unit must be one of: " . implode(', ', VALID_BASE_UNITS);
    }
}

if (!empty($errors)) {
    http_response_code(HTTP_UNPROCESSABLE_ENTITY);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'code' => 'VALIDATION_ERROR',
        'errors' => $errors
    ]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $productStmt = $pdo->prepare("SELECT product_id, product_name, price FROM products WHERE product_id = ?");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    
    if (!$product) {
        $pdo->rollBack();
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }
    
    $ingredientIds = array_column($ingredients, 'ingredient_id');
    $ingStmt = $pdo->prepare("SELECT ingredient_id, name, base_unit, current_stock FROM ingredients WHERE ingredient_id IN (" . implode(',', array_fill(0, count($ingredientIds), '?')) . ")");
    $ingStmt->execute($ingredientIds);
    $existingIngredients = $ingStmt->fetchAll();
    
    if (count($existingIngredients) !== count($ingredientIds)) {
        $pdo->rollBack();
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode(['success' => false, 'error' => 'One or more ingredients not found']);
        exit;
    }
    
    $deleteStmt = $pdo->prepare("DELETE FROM product_ingredients WHERE product_id = ?");
    $deleteStmt->execute([$productId]);
    
    $insertStmt = $pdo->prepare("
        INSERT INTO product_ingredients 
        (product_id, ingredient_id, quantity, unit) 
        VALUES (:product_id, :ingredient_id, :quantity, :unit)
    ");
    
    $recipeDetails = [];
    foreach ($ingredients as $ing) {
        $insertStmt->execute([
            ':product_id' => $productId,
            ':ingredient_id' => (int)$ing['ingredient_id'],
            ':quantity' => round((float)$ing['quantity'], STOCK_DECIMAL_PLACES),
            ':unit' => $ing['unit']
        ]);
        
        $recipeDetails[] = [
            'ingredient_id' => (int)$ing['ingredient_id'],
            'quantity' => round((float)$ing['quantity'], STOCK_DECIMAL_PLACES),
            'unit' => $ing['unit']
        ];
    }
    
    $costStmt = $pdo->prepare("
        SELECT SUM(pi.quantity * i.cost) as total_cost
        FROM product_ingredients pi
        INNER JOIN ingredients i ON pi.ingredient_id = i.ingredient_id
        WHERE pi.product_id = ?
    ");
    $costStmt->execute([$productId]);
    $calculatedCost = (float)($costStmt->fetchColumn() ?? 0);
    
    $updateCostStmt = $pdo->prepare("UPDATE products SET cost = ? WHERE product_id = ?");
    $updateCostStmt->execute([round($calculatedCost, 2), $productId]);
    
    $pdo->commit();
    
    error_log(
        "Recipe created/updated for: {$product['product_name']} (ID: {$productId}) " .
        "by {$currentUser['username']}"
    );
    
    http_response_code(HTTP_OK);
    echo json_encode([
        'success' => true,
        'message' => 'Recipe saved successfully',
        'data' => [
            'product_id' => $productId,
            'product_name' => $product['product_name'],
            'ingredients' => $recipeDetails,
            'calculated_cost' => round($calculatedCost, 2),
            'selling_price' => (float)$product['price'],
            'profit_margin' => $calculatedCost > 0 ? round((($product['price'] - $calculatedCost) / $product['price']) * 100, 2) : 0
        ],
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Recipe save failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Database error', 'code' => 'DATABASE_ERROR']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Unexpected error in recipe store: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Server error', 'code' => 'SERVER_ERROR']);
}

exit;