<?php
declare(strict_types=1);

/**
 * Database Configuration
 * 
 * Supports environment variables via .env file or $_ENV
 * Falls back to defaults for XAMPP development
 */

// Load .env file if exists (simple parser, no composer dependency)
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Skip comments
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Database credentials (env > default)
$host = $_ENV['DB_HOST'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? 'cafe_system';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

// App mode (for error handling)
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

// PDO options for security & performance
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // Return associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                       // Use native prepared statements
    PDO::ATTR_PERSISTENT         => filter_var($_ENV['DB_PERSISTENT'] ?? false, FILTER_VALIDATE_BOOLEAN),
];

// Build DSN
$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Optional: Verify connection in debug mode
    if ($debug) {
        // Test query to ensure connection is working
        $pdo->query('SELECT 1')->fetchColumn();
    }
    
} catch (PDOException $e) {
    // Log error for server logs (never expose to client)
    error_log("Database connection failed: " . $e->getMessage());
    error_log("DSN: {$dsn}, User: {$user}");
    
    // Return appropriate response based on context
    if (php_sapi_name() === 'cli') {
        // CLI context (e.g., migration scripts)
        fwrite(STDERR, "❌ Database connection failed\n");
        if ($debug) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        }
        exit(1);
    } else {
        // Web context (API requests)
        http_response_code(500);
        header('Content-Type: application/json');
        
        if ($debug) {
            // Show details in development only
            echo json_encode([
                'success' => false,
                'error' => 'Database connection failed',
                'debug' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            ]);
        } else {
            // Generic message in production
            echo json_encode([
                'success' => false,
                'error' => 'Service temporarily unavailable'
            ]);
        }
    }
    exit;
}