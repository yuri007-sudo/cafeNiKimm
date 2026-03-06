<?php
declare(strict_types=1);

/**
 * Application Constants
 * 
 * Centralized configuration for validation rules, HTTP codes, and app settings
 * Load this file in any endpoint that needs these constants
 * 
 * Usage:
 *   require __DIR__ . '/constants.php';
 *   echo MAX_INGREDIENT_NAME_LENGTH; // 100
 */

// ============================================================================
// APPLICATION SETTINGS
// ============================================================================

/**
 * Application Environment
 * Values: 'development', 'staging', 'production'
 */
define('APP_ENV', $_ENV['APP_ENV'] ?? 'development');

/**
 * Debug Mode
 * true = Show detailed errors (development only)
 * false = Show generic errors (production)
 */
define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));

/**
 * Application URL
 * Base URL for the application (used for CORS, redirects, etc.)
 */
define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost/cafe_system');

/**
 * API Version
 * Used for API versioning (e.g., /api/v1/...)
 */
define('API_VERSION', 'v1');

// ============================================================================
// DATABASE SETTINGS
// ============================================================================

/**
 * Database Charset
 */
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

/**
 * Use Persistent Connections
 * true = Faster repeated connections
 * false = Fresh connection each time (recommended for most cases)
 */
define('DB_PERSISTENT', filter_var($_ENV['DB_PERSISTENT'] ?? false, FILTER_VALIDATE_BOOLEAN));

// ============================================================================
// SESSION SETTINGS
// ============================================================================

/**
 * Session Timeout (seconds)
 * User will be logged out after this period of inactivity
 * Default: 30 minutes (1800 seconds)
 */
define('SESSION_TIMEOUT', (int)($_ENV['SESSION_TIMEOUT'] ?? 1800));

/**
 * Session Lifetime (seconds)
 * Maximum session duration before forced re-login
 * Default: 2 hours (7200 seconds)
 */
define('SESSION_LIFETIME', (int)($_ENV['SESSION_LIFETIME'] ?? 7200));

/**
 * Cookie Domain
 * Leave empty for current domain, or set for subdomain sharing (e.g., '.yourdomain.com')
 */
define('COOKIE_DOMAIN', $_ENV['COOKIE_DOMAIN'] ?? '');

// ============================================================================
// SECURITY SETTINGS
// ============================================================================

/**
 * Rate Limiting
 * Maximum requests per minute per user/IP
 */
define('RATE_LIMIT', (int)($_ENV['RATE_LIMIT'] ?? 60));

/**
 * Login Rate Limit
 * Maximum failed login attempts before lockout
 */
define('LOGIN_RATE_LIMIT', 5);

/**
 * Login Lockout Window (minutes)
 * How long to wait after too many failed login attempts
 */
define('LOGIN_LOCKOUT_WINDOW', 15);

/**
 * Allowed CORS Origins
 * Comma-separated list of allowed frontend URLs
 */
define('CORS_ORIGINS', array_map('trim', explode(',', $_ENV['CORS_ORIGINS'] ?? 'http://localhost:5173,http://localhost:3000')));

// ============================================================================
// VALIDATION RULES - INGREDIENTS
// ============================================================================

/**
 * Ingredient name length limits
 */
define('MIN_INGREDIENT_NAME_LENGTH', 1);
define('MAX_INGREDIENT_NAME_LENGTH', 100);

/**
 * Barcode length limits
 */
define('MIN_BARCODE_LENGTH', 1);
define('MAX_BARCODE_LENGTH', 50);

/**
 * Unit of measure length limit
 */
define('MAX_UNIT_LENGTH', 20);

/**
 * Valid base units for ingredients
 */
define('VALID_BASE_UNITS', ['ml', 'g', 'pcs']);

/**
 * Stock value limits
 */
define('MIN_STOCK_VALUE', 0.00);
define('MAX_STOCK_VALUE', 999999.99);

/**
 * Decimal precision for stock values
 */
define('STOCK_DECIMAL_PLACES', 2);

// ============================================================================
// VALIDATION RULES - PRODUCTS (Future)
// ============================================================================

define('MIN_PRODUCT_NAME_LENGTH', 1);
define('MAX_PRODUCT_NAME_LENGTH', 100);
define('MIN_PRICE_VALUE', 0.00);
define('MAX_PRICE_VALUE', 99999.99);

// ============================================================================
// VALIDATION RULES - USERS
// ============================================================================

define('MIN_USERNAME_LENGTH', 3);
define('MAX_USERNAME_LENGTH', 50);
define('MIN_PASSWORD_LENGTH', 6);
define('MAX_PASSWORD_LENGTH', 255);
define('MAX_FULLNAME_LENGTH', 100);

/**
 * Valid user roles
 */
define('VALID_ROLES', ['admin', 'cashier', 'barista']);

// ============================================================================
// HTTP STATUS CODES
// ============================================================================

define('HTTP_OK', 200);
define('HTTP_CREATED', 201);
define('HTTP_ACCEPTED', 202);
define('HTTP_NO_CONTENT', 204);

define('HTTP_BAD_REQUEST', 400);
define('HTTP_UNAUTHORIZED', 401);
define('HTTP_FORBIDDEN', 403);
define('HTTP_NOT_FOUND', 404);
define('HTTP_METHOD_NOT_ALLOWED', 405);
define('HTTP_CONFLICT', 409);
define('HTTP_UNPROCESSABLE_ENTITY', 422);
define('HTTP_TOO_MANY_REQUESTS', 429);

define('HTTP_INTERNAL_SERVER_ERROR', 500);
define('HTTP_NOT_IMPLEMENTED', 501);
define('HTTP_SERVICE_UNAVAILABLE', 503);

// ============================================================================
// INVENTORY ACTIONS
// ============================================================================

/**
 * Valid stock adjustment actions
 */
define('INVENTORY_ACTIONS', [
    'IN'      => 'Stock In (Received)',
    'OUT'     => 'Stock Out (Used)',
    'WASTE'   => 'Waste (Spoiled/Damaged)',
    'ADJUST'  => 'Adjustment (Correction)',
    'TRANSFER'=> 'Transfer'
]);

// ============================================================================
// ORDER STATUS (Future)
// ============================================================================

define('ORDER_STATUS', [
    'pending'   => 'Pending',
    'paid'      => 'Paid',
    'preparing' => 'Preparing',
    'ready'     => 'Ready',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
]);

// ============================================================================
// FILE UPLOAD SETTINGS (Future)
// ============================================================================

define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('UPLOAD_DIR', __DIR__ . '/../../uploads/');

// ============================================================================
// LOGGING SETTINGS
// ============================================================================

/**
 * Log file path
 */
define('LOG_FILE', __DIR__ . '/../../logs/app.log');

/**
 * Log levels: 'debug', 'info', 'warning', 'error'
 */
define('LOG_LEVEL', APP_DEBUG ? 'debug' : 'error');

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Check if running in development mode
 * @return bool
 */
function isDevelopment(): bool {
    return APP_ENV === 'development' || APP_DEBUG === true;
}

/**
 * Check if running in production mode
 * @return bool
 */
function isProduction(): bool {
    return APP_ENV === 'production' && APP_DEBUG === false;
}

/**
 * Get current timestamp in ISO 8601 format
 * @return string
 */
function getTimestamp(): string {
    return date(DATE_ATOM);
}

/**
 * Generate a unique request ID for tracking
 * @return string
 */
function generateRequestId(): string {
    return 'req_' . bin2hex(random_bytes(8));
}

/**
 * Sanitize output for JSON response
 * @param string $value
 * @return string
 */
function sanitizeOutput(string $value): string {
    return trim(htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8'));
}

/**
 * Validate stock action
 * @param string $action
 * @return bool
 */
function isValidInventoryAction(string $action): bool {
    return array_key_exists($action, INVENTORY_ACTIONS);
}

/**
 * Validate user role
 * @param string $role
 * @return bool
 */
function isValidRole(string $role): bool {
    return in_array($role, VALID_ROLES, true);
}

/**
 * Validate base unit
 * @param string $unit
 * @return bool
 */
function isValidBaseUnit(string $unit): bool {
    return in_array($unit, VALID_BASE_UNITS, true);
}