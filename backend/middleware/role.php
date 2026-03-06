<?php
declare(strict_types=1);

/**
 * Role-Based Access Control Middleware
 * Include this AFTER auth.php to check user permissions
 * 
 * Usage:
 *   require __DIR__ . '/../middleware/auth.php';
 *   require __DIR__ . '/../middleware/role.php';
 *   
 *   checkRole(['admin']); // Only admin
 *   checkRole(['admin', 'barista']); // Admin OR barista
 *   
 *   // OR with explicit auth:
 *   $user = checkAuth();
 *   checkRole(['admin'], $user);
 */

// Security headers (in case not set by auth.php)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/**
 * Check if user has one of the allowed roles
 * 
 * @param array $allowed_roles Array of allowed role names (e.g., ['admin', 'barista'])
 * @param array|null $user Optional user data from checkAuth() - uses session if not provided
 * @return bool True if authorized (script continues), otherwise exits with 403
 */
function checkRole(array $allowed_roles, ?array $user = null): bool
{
    // 1. Get user data (from parameter or session)
    if ($user === null) {
        // Fallback to session (for backward compatibility)
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            // User not authenticated - should have been caught by auth.php
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required',
                'code' => 'UNAUTHORIZED'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $user = [
            'user_id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? 'unknown',
            'role' => $_SESSION['role'],
        ];
    }
    
    // 2. Validate allowed_roles parameter
    if (empty($allowed_roles) || !is_array($allowed_roles)) {
        error_log("checkRole() called with invalid allowed_roles parameter");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server configuration error',
            'code' => 'SERVER_ERROR'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 3. Check if user's role is in allowed list
    $userRole = $user['role'] ?? '';
    $isAuthorized = in_array($userRole, $allowed_roles, true);
    
    if (!$isAuthorized) {
        // Log the forbidden access attempt
        $endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userId = $user['user_id'] ?? 'unknown';
        $username = $user['username'] ?? 'unknown';
        
        error_log(
            "Forbidden access: User {$username} (ID: {$userId}, Role: {$userRole}) " .
            "tried to access {$endpoint} from {$ip}. " .
            "Required roles: " . implode(', ', $allowed_roles)
        );
        
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Access forbidden',
            'code' => 'FORBIDDEN',
            'message' => 'You do not have permission to perform this action',
            'required_roles' => $allowed_roles,
            'your_role' => $userRole
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 4. Authorized - continue execution
    return true;
}

/**
 * Helper: Check if user has specific role (returns boolean, doesn't exit)
 * 
 * @param string $role Role to check
 * @param array|null $user Optional user data
 * @return bool
 */
function hasRole(string $role, ?array $user = null): bool
{
    if ($user === null) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
    return ($user['role'] ?? '') === $role;
}

/**
 * Helper: Check if user has ANY of the specified roles (returns boolean, doesn't exit)
 * 
 * @param array $roles Roles to check
 * @param array|null $user Optional user data
 * @return bool
 */
function hasAnyRole(array $roles, ?array $user = null): bool
{
    if ($user === null) {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
    }
    return in_array($user['role'] ?? '', $roles, true);
}