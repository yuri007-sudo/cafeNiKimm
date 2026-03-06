<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../middleware/auth.php';
require __DIR__ . '/../middleware/role.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$currentUser = checkAuth();
checkRole(['admin', 'cashier'], $currentUser);

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$errors = [];
$items = $data['items'] ?? [];
$paymentMethod = $data['payment_method'] ?? 'cash';
$amountPaid = (float)($data['amount_paid'] ?? 0);

if (empty($items) || !is_array($items)) {
    $errors['items'] = 'At least one item is required';
}

foreach ($items as $index => $item) {
    if (empty($item['product_id'])) {
        $errors["items[$index]"] = 'Product ID is required';
    }
    if (empty($item['quantity']) || (int)$item['quantity'] <= 0) {
        $errors["items[$index]"] = 'Quantity must be greater than 0';
    }
    if (!isset($item['price']) || (float)$item['price'] < 0) {
        $errors["items[$index]"] = 'Price must be set';
    }
}

if (!empty($errors)) {
    http_response_code(HTTP_UNPROCESSABLE_ENTITY);
    echo json_encode(['success' => false, 'error' => 'Validation failed', 'errors' => $errors]);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $orderCode = $data['order_code'] ?? 'ORD-' . date('Ymd-') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $totalAmount = 0;
    foreach ($items as $item) {
        $totalAmount += (float)$item['price'] * (int)$item['quantity'];
    }
    
    $orderStmt = $pdo->prepare("
        INSERT INTO orders 
        (order_code, total_amount, order_status, payment_method, created_by, notes) 
        VALUES (:order_code, :total_amount, 'paid', :payment_method, :created_by, :notes)
    ");
    $orderStmt->execute([
        ':order_code' => $orderCode,
        ':total_amount' => round($totalAmount, 2),
        ':payment_method' => $paymentMethod,
        ':created_by' => $currentUser['user_id'],
        ':notes' => $data['notes'] ?? null
    ]);
    
    $orderId = (int) $pdo->lastInsertId();
    
    $orderItems = [];
    $stockWarnings = [];
    
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items 
        (order_id, product_id, quantity, price) 
        VALUES (:order_id, :product_id, :quantity, :price)
    ");
    
    $deductStmt = $pdo->prepare("
        UPDATE ingredients 
        SET current_stock = current_stock - :deduct_amount 
        WHERE ingredient_id = :ingredient_id AND current_stock >= :deduct_amount
    ");
    
    $recipeStmt = $pdo->prepare("
        SELECT ingredient_id, quantity, unit 
        FROM product_ingredients 
        WHERE product_id = ?
    ");
    
    foreach ($items as $item) {
        $productId = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        $price = (float)$item['price'];
        
        $itemStmt->execute([
            ':order_id' => $orderId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':price' => round($price, 2)
        ]);
        
        $orderItems[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'price' => round($price, 2)
        ];
        
        $recipeStmt->execute([$productId]);
        $recipe = $recipeStmt->fetchAll();
        
        foreach ($recipe as $ingredient) {
            $deductAmount = (float)$ingredient['quantity'] * $quantity;
            
            $deductStmt->execute([
                ':deduct_amount' => round($deductAmount, 2),
                ':ingredient_id' => (int)$ingredient['ingredient_id']
            ]);
            
            if ($deductStmt->rowCount() === 0) {
                $pdo->rollBack();
                http_response_code(HTTP_CONFLICT);
                echo json_encode([
                    'success' => false,
                    'error' => "Insufficient stock for ingredient ID: {$ingredient['ingredient_id']}",
                    'code' => 'INSUFFICIENT_STOCK',
                    'ingredient_id' => (int)$ingredient['ingredient_id']
                ]);
                exit;
            }
            
            $checkStmt = $pdo->prepare("SELECT name, current_stock, critical_level FROM ingredients WHERE ingredient_id = ?");
            $checkStmt->execute([(int)$ingredient['ingredient_id']]);
            $ingInfo = $checkStmt->fetch();
            
            if ($ingInfo['current_stock'] <= $ingInfo['critical_level']) {
                $stockWarnings[] = [
                    'ingredient_id' => (int)$ingredient['ingredient_id'],
                    'name' => $ingInfo['name'],
                    'current_stock' => (float)$ingInfo['current_stock'],
                    'critical_level' => (float)$ingInfo['critical_level'],
                    'message' => "⚠️ {$ingInfo['name']} is now low on stock!"
                ];
            }
            
            $logStmt = $pdo->prepare("
                INSERT INTO inventory_logs 
                (ingredient_id, change_amount, action, user_id, order_id, notes) 
                VALUES (:ingredient_id, :change_amount, 'OUT', :user_id, :order_id, :notes)
            ");
            $logStmt->execute([
                ':ingredient_id' => (int)$ingredient['ingredient_id'],
                ':change_amount' => -round($deductAmount, 2),
                ':user_id' => $currentUser['user_id'],
                ':order_id' => $orderId,
                ':notes' => "Sold via order {$orderCode}"
            ]);
        }
    }
    
    $pdo->commit();
    
    $change = $amountPaid > 0 ? round($amountPaid - $totalAmount, 2) : 0;
    
    error_log(
        "Order created: {$orderCode} (ID: {$orderId}, Total: ₱{$totalAmount}) " .
        "by {$currentUser['username']}"
    );
    
    http_response_code(HTTP_CREATED);
    echo json_encode([
        'success' => true,
        'message' => 'Order created successfully',
        'data' => [
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'items' => $orderItems,
            'total_amount' => round($totalAmount, 2),
            'payment_method' => $paymentMethod,
            'amount_paid' => $amountPaid,
            'change' => $change,
            'created_at' => date(DATE_ATOM)
        ],
        'warnings' => $stockWarnings,
        'timestamp' => getTimestamp()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Order creation failed: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Failed to create order', 'code' => 'DATABASE_ERROR']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Unexpected error in order store: " . $e->getMessage());
    http_response_code(HTTP_INTERNAL_SERVER_ERROR);
    echo json_encode(['success' => false, 'error' => 'Server error', 'code' => 'SERVER_ERROR']);
}

exit;