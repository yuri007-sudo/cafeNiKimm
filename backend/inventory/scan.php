<?php
declare(strict_types=1);

/**
 * Barcode Scanner Lookup Endpoint
 * POST /backend/inventory/scan.php
 * 
 * Scans barcode and returns ingredient info
 * 
 * Request Body:
 * {
 *   "barcode": "MILK-1L-001"
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "ingredient_id": 5,
 *     "name": "Whole Milk",
 *     "base_unit": "ml",
 *     "current_stock": 8500.00,
 *     "critical_level": 2000.00,
 *     "barcode_unit_size": 1000.00,
 *     "barcode_unit": "ml"
 *   }
 * }
 */

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Authenticate user (any logged-in user can scan)
$currentUser = checkAuth();

// Parse input
try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$barcode = trim($data['barcode'] ?? '');

if ($barcode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Barcode is required']);
    exit;
}

// Lookup barcode in ingredient_barcodes table
try {
    $stmt = $pdo->prepare("
        SELECT 
            i.ingredient_id,
            i.name,
            i.base_unit,
            i.current_stock,
            i.critical_level,
            ib.unit_size as barcode_unit_size,
            ib.unit as barcode_unit
        FROM ingredient_barcodes ib
        INNER JOIN ingredients i ON ib.ingredient_id = i.ingredient_id
        WHERE ib.barcode = ?
        LIMIT 1
    ");
    $stmt->execute([$barcode]);
    $ingredient = $stmt->fetch();
    
    if (!$ingredient) {
        // Log failed scan attempt
        error_log("Barcode not found: {$barcode} by user {$currentUser['username']}");
        
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Barcode not found in system',
            'code' => 'BARCODE_NOT_FOUND',
            'barcode' => $barcode
        ]);
        exit;
    }
    
    // Log successful scan
    error_log("Barcode scanned: {$barcode} -> {$ingredient['name']} by {$currentUser['username']}");
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Ingredient found',
        'data' => $ingredient,
        'timestamp' => date(DATE_ATOM)
    ]);
    
} catch (PDOException $e) {
    error_log("Barcode scan failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

exit;