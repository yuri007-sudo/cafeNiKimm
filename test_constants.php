<?php
require __DIR__ . '/backend/config/constants.php';

echo "<h2>✅ Constants Loaded Successfully!</h2>";
echo "<pre>";
echo "APP_ENV: " . APP_ENV . "\n";
echo "APP_DEBUG: " . (APP_DEBUG ? 'true' : 'false') . "\n";
echo "SESSION_TIMEOUT: " . SESSION_TIMEOUT . " seconds\n";
echo "MAX_INGREDIENT_NAME_LENGTH: " . MAX_INGREDIENT_NAME_LENGTH . "\n";
echo "VALID_BASE_UNITS: " . implode(', ', VALID_BASE_UNITS) . "\n";
echo "HTTP_OK: " . HTTP_OK . "\n";
echo "INVENTORY_ACTIONS: " . json_encode(INVENTORY_ACTIONS) . "\n";
echo "</pre>";

echo "<h3>Helper Functions Test:</h3>";
echo "<ul>";
echo "<li>isDevelopment(): " . (isDevelopment() ? 'true' : 'false') . "</li>";
echo "<li>getTimestamp(): " . getTimestamp() . "</li>";
echo "<li>generateRequestId(): " . generateRequestId() . "</li>";
echo "<li>isValidRole('admin'): " . (isValidRole('admin') ? 'true' : 'false') . "</li>";
echo "<li>isValidBaseUnit('ml'): " . (isValidBaseUnit('ml') ? 'true' : 'false') . "</li>";
echo "</ul>";