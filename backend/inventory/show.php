<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = checkAuth();

$ingredientId = (int)($_GET['id'] ?? 0);

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
    $stmt = $pdo->prepare("
        SELECT 
            i.ingredient_id as id,
            i.name,
            i.base_unit,
            i.current_stock,
            i.critical_level,
            ib.barcode,
            ib.unit_size as barcode_unit_size,
            ib.unit as barcode_unit,
            CASE WHEN i.current_stock <= i.critical_level THEN 1 ELSE 0 END as is_low_stock
        FROM ingredients i
        LEFT JOIN ingredient_barcodes ib ON i.ingredient_id = ib.ingredient_id
        WHERE i.ingredient_id = ?
        LIMIT 1
    ");
    $stmt->execute([$ingredientId]);
    $ingredient = $stmt->fetch();
    
    if (!$ingredient) {
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode([
            'success' => false