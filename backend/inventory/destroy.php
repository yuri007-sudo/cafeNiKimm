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

$ingredientId = (int)($data['id'] ?? 0);

if ($ingredientId <= 0) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode([
        'success' => false,
        'error' => 'Valid ingredient ID is required',
        'code' => 'INVALID_ID'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $fetchStmt = $pdo->prepare("SELECT ingredient_id, name, current_stock FROM ingredients WHERE ingredient_id = ?");
    $fetchStmt->execute([$ingredientId]);
    $ingredient = $fetchStmt->fetch();
    
    if (!$ingredient) {
        $pdo->rollBack();
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode([
            'success' => false,
            'error' => 'Ingredient not found',
            'code' => 'NOT_FOUND'
        ]);
        exit;
    }
    
    $recipeCheckStmt = $pdo->prepare("
        SELECT COUNT(*) as recipe_count, GROUP_CONCAT(p.product_name) as products
        FROM product_ingredients pi
        INNER JOIN products p ON pi.product_id = p.product_id
        WHERE pi.ingredient_id = ?
    ");
    $recipeCheckStmt->execute([$ingredientId]);
    $recipeInfo = $recipeCheckStmt->fetch();
    
    if ($recipeInfo['recipe_count'] > 0) {
        $pdo->rollBack();
        http_response_code(HTTP_CONFLICT);
        echo json_encode([
            'success' => false,
            'error' => 'Cannot delete ingredient. It is used in ' . $recipeInfo['recipe_count'] . ' product(s): ' . $recipeInfo['products'],
            'code' => 'RECIPE_REFERENCE',
            'recipe_count' => (int)$recipeInfo['recipe_count'],
            'products' => $recipeInfo['products']
        ]);
        exit;
    }
    
    $barcodeCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM ingredient_barcodes WHERE ingredient_id = ?");
    $barcodeCheckStmt->execute([$ingredientId]);
    $barcodeCount = (int) $barcodeCheckStmt->fetchColumn();
    
    if ($barcodeCount > 0) {
        $deleteBarcodeStmt = $pdo->prepare("DELETE FROM ingredient_barcodes WHERE ingredient_id = ?");
        $deleteBarcodeStmt->execute([$ingredientId]);
    }
    
    $deleteStmt = $pdo->prepare("DELETE FROM ingredients WHERE ingredient_id = ?");
    $deleteStmt->execute([$ingredientId]);
    
    if ($deleteStmt->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(HTTP_INTERNAL_SERVER_ERROR);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to delete ingredient',
            'code' => 'DELETE_FAILED'
        ]);
        exit;
    }
    
    $pdo->commit();
    
    error_log(
        "Ingredient deleted: {$ingredient['name']} (ID: {$ingredientId}, Stock: {$ingredient['current_stock']}) " .
        "by {$currentUser['username']} (ID: {$currentUser['user_id']})"
    );
    
    http_response_code(HTTP_OK);
    echo json_encode([
        'success' => true,
        'message' => 'Ingredient deleted successfully',
        'data' => [
            'deleted_id' => $ingredientId,
            'deleted_name' => $ingredient['name'],
            'deleted_stock' => $ingredient['current_stock']
        ],
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Ingredient delete failed: " . $e->getMessage());
    
    if (str_contains($e->getMessage(), 'foreign key')) {
        http_response_code(HTTP_CONFLICT);
        echo json_encode([
            'success' => false,
            'error' => 'Cannot delete ingredient. It is referenced by other records.',
            'code' => 'FOREIGN_KEY_CONSTRAINT'
        ]);
    } else {
        http_response_code(HTTP_INTERNAL_SERVER_ERROR);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to delete ingredient',
            'code' => 'DATABASE_ERROR'
        ]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Unexpected error in destroy.php: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred',
        'code' => 'SERVER_ERROR'
    ]);
}

exit;