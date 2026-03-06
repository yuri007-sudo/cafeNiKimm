<?php
declare(strict_types=1);

/**
 * Update Ingredient Endpoint
 * PUT /backend/inventory/update.php
 * 
 * Required Role: admin
 * 
 * Request Body:
 * {
 *   "id": 1,
 *   "name": "Updated Name",           // Optional
 *   "base_unit": "g",                 // Optional
 *   "critical_level": 500.00,         // Optional
 *   "barcode": "NEW-BARCODE",         // Optional (replaces existing)
 *   "barcode_unit_size": 1000.00,     // Optional (required if barcode provided)
 *   "barcode_unit": "ml"              // Optional
 * }
 */

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';
require __DIR__ . '/../middleware/role.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = checkAuth();
checkRole(['admin'], $currentUser);

// Parse input (support PUT via POST with _method or direct PUT)
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

if ($method !== 'PUT' && $method !== 'POST') {
    http_response_code(HTTP_METHOD_NOT_ALLOWED);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Validation
$errors = [];
$ingredientId = (int)($data['id'] ?? 0);

if ($ingredientId <= 0) {
    $errors['id'] = 'Valid ingredient ID is required';
}

// Validate name if provided
if (isset($data['name'])) {
    $name = trim($data['name']);
    if ($name === '') {
        $errors['name'] = 'Name cannot be empty';
    } elseif (mb_strlen($name) > MAX_INGREDIENT_NAME_LENGTH) {
        $errors['name'] = "Name must be max " . MAX_INGREDIENT_NAME_LENGTH . " characters";
    }
}

// Validate base_unit if provided
if (isset($data['base_unit'])) {
    if (!in_array($data['base_unit'], VALID_BASE_UNITS, true)) {
        $errors['base_unit'] = "Unit must be one of: " . implode(', ', VALID_BASE_UNITS);
    }
}

// Validate critical_level if provided
if (isset($data['critical_level'])) {
    if (!is_numeric($data['critical_level']) || (float)$data['critical_level'] < MIN_STOCK_VALUE) {
        $errors['critical_level'] = "Critical level must be at least " . MIN_STOCK_VALUE;
    }
}

// Validate barcode if provided
$hasBarcode = isset($data['barcode']) && $data['barcode'] !== '';
if ($hasBarcode) {
    if (mb_strlen($data['barcode']) > MAX_BARCODE_LENGTH) {
        $errors['barcode'] = "Barcode must be max " . MAX_BARCODE_LENGTH . " characters";
    }
    if (!isset($data['barcode_unit_size']) || (float)($data['barcode_unit_size'] ?? 0) <= 0) {
        $errors['barcode_unit_size'] = 'Barcode unit size is required when barcode is provided';
    }
    if (isset($data['barcode_unit']) && !in_array($data['barcode_unit'], VALID_BASE_UNITS, true)) {
        $errors['barcode_unit'] = "Barcode unit must be one of: " . implode(', ', VALID_BASE_UNITS);
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
    
    // Verify ingredient exists
    $checkStmt = $pdo->prepare("SELECT ingredient_id, name, base_unit FROM ingredients WHERE ingredient_id = ?");
    $checkStmt->execute([$ingredientId]);
    $existing = $checkStmt->fetch();
    
    if (!$existing) {
        $pdo->rollBack();
        http_response_code(HTTP_NOT_FOUND);
        echo json_encode(['success' => false, 'error' => 'Ingredient not found']);
        exit;
    }
    
    // Check name uniqueness (if name is being changed)
    if (isset($data['name']) && $data['name'] !== $existing['name']) {
        $nameCheckStmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM ingredients WHERE LOWER(name) = LOWER(?) AND ingredient_id != ?)");
        $nameCheckStmt->execute([$data['name'], $ingredientId]);
        if ($nameCheckStmt->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(HTTP_CONFLICT);
            echo json_encode([
                'success' => false,
                'error' => 'Ingredient name already exists',
                'code' => 'DUPLICATE_NAME'
            ]);
            exit;
        }
    }
    
    // Build update query dynamically (only update provided fields)
    $updateFields = [];
    $updateParams = [];
    
    if (isset($data['name'])) {
        $updateFields[] = "name = :name";
        $updateParams[':name'] = trim($data['name']);
    }
    if (isset($data['base_unit'])) {
        $updateFields[] = "base_unit = :base_unit";
        $updateParams[':base_unit'] = $data['base_unit'];
    }
    if (isset($data['critical_level'])) {
        $updateFields[] = "critical_level = :critical_level";
        $updateParams[':critical_level'] = round((float)$data['critical_level'], STOCK_DECIMAL_PLACES);
    }
    
    // Update ingredient if there are fields to update
    if (!empty($updateFields)) {
        $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
        $updateParams[':ingredient_id'] = $ingredientId;
        
        $updateStmt = $pdo->prepare("
            UPDATE ingredients 
            SET " . implode(', ', $updateFields) . "
            WHERE ingredient_id = :ingredient_id
        ");
        $updateStmt->execute($updateParams);
    }
    
    // Handle barcode update (delete old, insert new if barcode provided)
    if ($hasBarcode) {
        // Check barcode uniqueness
        $barcodeCheckStmt = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM ingredient_barcodes WHERE barcode = ?)");
        $barcodeCheckStmt->execute([$data['barcode']]);
        if ($barcodeCheckStmt->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(HTTP_CONFLICT);
            echo json_encode([
                'success' => false,
                'error' => 'Barcode already registered to another ingredient',
                'code' => 'DUPLICATE_BARCODE'
            ]);
            exit;
        }
        
        // Delete existing barcode for this ingredient
        $deleteBarcodeStmt = $pdo->prepare("DELETE FROM ingredient_barcodes WHERE ingredient_id = ?");
        $deleteBarcodeStmt->execute([$ingredientId]);
        
        // Insert new barcode
        $insertBarcodeStmt = $pdo->prepare("
            INSERT INTO ingredient_barcodes 
            (barcode, ingredient_id, unit_size, unit) 
            VALUES (:barcode, :ingredient_id, :unit_size, :unit)
        ");
        $insertBarcodeStmt->execute([
            ':barcode' => trim($data['barcode']),
            ':ingredient_id' => $ingredientId,
            ':unit_size' => round((float)$data['barcode_unit_size'], STOCK_DECIMAL_PLACES),
            ':unit' => $data['barcode_unit'] ?? $data['base_unit'] ?? $existing['base_unit'],
        ]);
    }
    
    $pdo->commit();
    
    // Fetch updated ingredient for response
    $fetchStmt = $pdo->prepare("
        SELECT 
            i.ingredient_id as id,
            i.name,
            i.base_unit,
            i.current_stock,
            i.critical_level,
            ib.barcode,
            ib.unit_size as barcode_unit_size,
            ib.unit as barcode_unit
        FROM ingredients i
        LEFT JOIN ingredient_barcodes ib ON i.ingredient_id = ib.ingredient_id
        WHERE i.ingredient_id = ?
    ");
    $fetchStmt->execute([$ingredientId]);
    $updated = $fetchStmt->fetch();
    
    // Log the update
    error_log(
        "Ingredient updated: {$updated['name']} (ID: {$ingredientId}) " .
        "by {$currentUser['username']} (ID: {$currentUser['user_id']})"
    );
    
    http_response_code(HTTP_OK);
    echo json_encode([
        'success' => true,
        'message' => 'Ingredient updated successfully',
        'data' => $updated,
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Ingredient update failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update ingredient',
        'code' => 'DATABASE_ERROR'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Unexpected error in update.php: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred',
        'code' => 'SERVER_ERROR'
    ]);
}

exit;