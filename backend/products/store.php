<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';
require __DIR__ . '/../middleware/role.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = checkAuth();
checkRole(['admin'], $currentUser);

try {
    $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$errors = [];
$name = trim($data['product_name'] ?? '');
$categoryId = (int)($data['category_id'] ?? 0);
$price = (float)($data['price'] ?? 0);

if ($name === '') $errors['product_name'] = 'Product name is required';
if (mb_strlen($name) > MAX_PRODUCT_NAME_LENGTH) $errors['product_name'] = "Name too long (max " . MAX_PRODUCT_NAME_LENGTH . ")";
if ($categoryId <= 0) $errors['category_id'] = 'Category is required';
if ($price < MIN_PRICE_VALUE) $errors['price'] = 'Price must be greater than 0';

if (!empty($errors)) {
    http_response_code(HTTP_UNPROCESSABLE_ENTITY);
    echo json_encode(['success' => false, 'error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO products (product_name, category_id, price, cost, prep_time, is_active) 
        VALUES (:name, :category_id, :price, :cost, :prep_time, :is_active)
    ");
    $stmt->execute([
        ':name' => $name,
        ':category_id' => $categoryId,
        ':price' => round($price, 2),
        ':cost' => round((float)($data['cost'] ?? 0), 2),
        ':prep_time' => (int)($data['prep_time'] ?? 0),
        ':is_active' => (int)($data['is_active'] ?? 1),
    ]);
    
    $productId = (int) $pdo->lastInsertId();
    
    error_log("Product created: {$name} (ID: {$productId}) by {$currentUser['username']}");
    
    http_response_code(HTTP_CREATED);
    echo json_encode([
        'success' => true,
        'message' => 'Product created successfully',
        'data' => [
            'product_id' => $productId,
            'product_name' => $name,
            'category_id' => $categoryId,
            'price' => round($price, 2)
        ],
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Product create failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

exit;