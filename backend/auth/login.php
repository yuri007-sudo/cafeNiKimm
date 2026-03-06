<?php
declare(strict_types=1);

/**
 * User Authentication Endpoint
 * POST /backend/auth/login.php
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require __DIR__ . '/../config/database.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// 1. Parse Input
try {
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new InvalidArgumentException('Request body is required');
    }
    $data = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON format']);
    exit;
}

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';

// Basic validation
if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password are required']);
    exit;
}

// 2. Rate Limiting: Max 5 attempts per 15 minutes per IP
function isRateLimited(string $identifier, int $maxAttempts = 5, int $windowMinutes = 15): bool {
    $key = "login_attempts_{$identifier}";
    $now = time();
    $window = $windowMinutes * 60;
    
    $attempts = $_SESSION[$key] ?? [];
    $attempts = array_filter($attempts, fn($t) => $t > ($now - $window));
    
    if (count($attempts) >= $maxAttempts) {
        return true;
    }
    
    $attempts[] = $now;
    $_SESSION[$key] = array_values($attempts);
    
    return false;
}

$rateLimitKey = md5(($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '_' . $username);

if (isRateLimited($rateLimitKey)) {
    error_log("Login rate limited: {$username} from {$_SERVER['REMOTE_ADDR']}");
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many login attempts. Please try again later.']);
    exit;
}

// 3. Query User
try {
    $stmt = $pdo->prepare("SELECT user_id, username, password, role, fullname FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Login query failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Authentication service error']);
    exit;
}

// 4. Verify Credentials
if (!$user || !password_verify($password, $user['password'])) {
    error_log("Failed login attempt for: {$username} from {$_SERVER['REMOTE_ADDR']}");
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    exit;
}

// 5. Regenerate session ID (prevent session fixation)
session_regenerate_id(true);

// 6. Set session variables
$_SESSION['user_id'] = (int) $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];
$_SESSION['loggedin_at'] = time();
$_SESSION['last_activity'] = time();

// Generate API token for frontend
$apiToken = bin2hex(random_bytes(32));
$_SESSION['api_token'] = $apiToken;

// Log successful login
error_log("Login successful: {$user['username']} (ID: {$user['user_id']}) from {$_SERVER['REMOTE_ADDR']}");

// 7. Return response
echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'data' => [
        'user_id' => (int) $user['user_id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'fullname' => $user['fullname'],
        'token' => $apiToken
    ]
], JSON_UNESCAPED_UNICODE);

unset($password, $data, $rawInput);
exit;