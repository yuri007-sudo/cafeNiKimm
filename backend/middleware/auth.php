<?php
declare(strict_types=1);

/**
 * Authentication Middleware
 * Include this in any protected endpoint to require authentication
 * 
 * Usage:
 *   require __DIR__ . '/../middleware/auth.php';
 *   // OR with token validation:
 *   require __DIR__ . '/../middleware/auth.php';
 *   checkAuth(); // Explicit call
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/**
 * Check if user is authenticated
 * Supports both session-based AND token-based auth
 * 
 * @param bool $requireToken If true, requires valid API token (for API calls)
 * @return array User data if authenticated
 * @throws Exception If not authenticated
 */
function checkAuth(bool $requireToken = false): array
{
    // 1. Check session-based auth
    $isLoggedIn = isset($_SESSION['user_id']) 
               && isset($_SESSION['username']) 
               && isset($_SESSION['role']);
    
    // 2. Check session timeout (30 minutes of inactivity)
    $sessionTimeout = (int)($_ENV['SESSION_TIMEOUT'] ?? 1800); // 30 min default
    $lastActivity = $_SESSION['last_activity'] ?? 0;
    $isSessionExpired = $lastActivity > 0 && (time() - $lastActivity) > $sessionTimeout;
    
    if ($isLoggedIn && $isSessionExpired) {
        // Session expired - clear and require re-login
        error_log("Session expired for user_id: {$_SESSION['user_id']}");
        $_SESSION = [];
        session_destroy();
        $isLoggedIn = false;
    }
    
    // 3. Check token-based auth (for API requests with Authorization header)
    $hasToken = false;
    $tokenValid = false;
    
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    $providedToken = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    
    if (!empty($providedToken) && isset($_SESSION['api_token'])) {
        $hasToken = true;
        // Use constant-time comparison to prevent timing attacks
        $tokenValid = hash_equals($_SESSION['api_token'], $providedToken);
    }
    
    // 4. Determine authentication status
    $isAuthenticated = false;
    
    if ($requireToken) {
        // API endpoint: require valid token
        $isAuthenticated = $hasToken && $tokenValid;
    } else {
        // Regular endpoint: session OR token is fine
        $isAuthenticated = $isLoggedIn || ($hasToken && $tokenValid);
    }
    
    // 5. Handle authentication failure
    if (!$isAuthenticated) {
        // Log unauthorized access attempt
        $endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        error_log("Unauthorized access attempt: {$endpoint} from {$ip}");
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication required',
            'code' => 'UNAUTHORIZED'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 6. Update last activity timestamp
    $_SESSION['last_activity'] = time();
    
    // 7. Return user data for use in endpoint
    return [
        'user_id' => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'loggedin_at' => $_SESSION['loggedin_at'] ?? null,
    ];
}

// Auto-execute check (legacy compatibility)
// New code should call checkAuth() explicitly to get user data
if (!function_exists('checkAuthCalled')) {
    // Only auto-check if this file is included directly in an endpoint
    // and no explicit call was made
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $calledExplicitly = false;
    
    foreach ($backtrace as $frame) {
        if (isset($frame['function']) && $frame['function'] === 'checkAuth') {
            $calledExplicitly = true;
            break;
        }
    }
    
    // For simplicity: auto-check for backward compatibility
    // Remove this block if you want explicit calls only
    checkAuth();
}