<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = checkAuth();

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$errors = [];
$ingredientId = (int)($data['ingredient_id'] ?? 0);
$action = strtoupper(trim($data['action'] ?? ''));
$quantity = (float)($data['quantity'] ?? 0);
$unit = trim($data['unit'] ?? '');
$notes = trim($data['notes'] ?? '');

if ($ingredientId <= 0) $errors['ingredient_id'] = 'Valid ingredient ID is required';
if (!in_array($action, ['IN', 'OUT', 'WASTE', 'ADJUST'], true)) $errors['action'] = 'Action must be IN, OUT, WASTE, or ADJUST';
if ($quantity <= 0) $errors['quantity'] = 'Quantity must be greater than 0';

if (!empty($errors)) {
    http_response_code(HTTP_UNPROCESSABLE_ENTITY);
    echo json_encode(['success' => false, 'error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT ingredient_id, name, base_unit, current_stock, critical_level FROM ingredients WHERE ingredient_id = ?");
    $stmt->execute([$ingredientId]);
    $ingredient = $stmt->fetch();
    
    if (!$ingredient) {
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode(['success' => false, 'error' => 'Ingredient not found']);
        exit;
    }
    
    if ($unit !== '' && $unit !== $ingredient['base_unit']) {
        http_response_code(HTTP_BAD_REQUEST);
        echo json_encode([
            'success' => false,
            'error' => "Unit must be {$ingredient['base_unit']}",
            'expected_unit' => $ingredient['base_unit']
        ]);
        exit;
    }
    
    $unit = $ingredient['base_unit'];
    
} catch (PDOException $e) {
    error_log("Ingredient fetch failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$oldStock = (float)$ingredient['current_stock'];
$newStock = $oldStock;
$changeAmount = $quantity;

switch ($action) {
    case 'IN':
        $newStock = $oldStock + $quantity;
        break;
    case 'OUT':
    case 'WASTE':
        if ($quantity > $oldStock) {
            http_response_code(HTTP_BAD_REQUEST);
            echo json_encode([
                'success' => false,
                'error' => "Insufficient stock. Current: {$oldStock} {$unit}, Requested: {$quantity} {$unit}",
                'code' => 'INSUFFICIENT_STOCK',
                'current_stock' => $oldStock
            ]);
            exit;
        }
        $newStock = $oldStock - $quantity;
        break;
    case 'ADJUST':
        $changeAmount = $quantity - $oldStock;
        $newStock = $quantity;
        break;
}

if ($newStock < 0) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Stock cannot be negative']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $updateStmt = $pdo->prepare("UPDATE ingredients SET current_stock = :new_stock WHERE ingredient_id = :ingredient_id");
    $updateStmt->execute([
        ':new_stock' => round($newStock, 2),
        ':ingredient_id' => $ingredientId
    ]);
    
    $logStmt = $pdo->prepare("
        INSERT INTO inventory_logs 
        (ingredient_id, change_amount, action, user_id, notes) 
        VALUES (:ingredient_id, :change_amount, :action, :user_id, :notes)
    ");
    $logStmt->execute([
        ':ingredient_id' => $ingredientId,
        ':change_amount' => round($changeAmount, 2),
        ':action' => $action,
        ':user_id' => $currentUser['user_id'],
        ':notes' => !empty($notes) ? $notes : null
    ]);
    
    $pdo->commit();
    
    error_log(
        "Stock {$action}: {$ingredient['name']} (ID: {$ingredientId}) " .
        "changed by {$changeAmount} {$unit} ({$oldStock} → {$newStock}) " .
        "by {$currentUser['username']}"
    );
    
    $recipeCheckStmt = $pdo->prepare("
        SELECT COUNT(*) as recipe_count 
        FROM product_ingredients pi
        INNER JOIN products p ON pi.product_id = p.product_id
        WHERE pi.ingredient_id = ? AND p.is_active = 1
    ");
    $recipeCheckStmt->execute([$ingredientId]);
    $recipeCount = (int)$recipeCheckStmt->fetchColumn();
    
    $warnings = [];
    if ($recipeCount > 0 && $action === 'OUT') {
        $warnings[] = [
            'type' => 'RECIPE_IMPACT',
            'message' => "This ingredient is used in {$recipeCount} active product(s). Reducing stock may affect sales.",
            'recipe_count' => $recipeCount
        ];
    }
    
    $isLowStock = $newStock <= (float)($ingredient['critical_level'] ?? 0);
    $stockWarning = null;
    if ($isLowStock) {
        $stockWarning = [
            'type' => 'LOW_STOCK',
            'message' => "Stock is below critical level!",
            'current' => $newStock,
            'critical' => $ingredient['critical_level']
        ];
    }
    
    http_response_code(HTTP_OK);
    echo json