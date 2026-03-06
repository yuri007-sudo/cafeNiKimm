<?php
declare(strict_types=1);

/**
 * User Logout Endpoint
 * POST /backend/auth/logout.php
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Capture user info for logging BEFORE destroying session
$userId = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'unknown';

// Log logout event (file log only, no DB required)
if ($userId) {
    error_log("Logout: {$username} (ID: {$userId}) from {$_SERVER['REMOTE_ADDR']}");
}

// Clear all session variables
$_SESSION = [];

// Delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Return response
echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully',
    'timestamp' => date(DATE_ATOM)
], JSON_UNESCAPED_UNICODE);

exit;