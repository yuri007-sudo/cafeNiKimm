<?php
session_start();
require __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    exit;
}

// Get JSON body
$data = json_decode(file_get_contents('php://input'), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

// Fetch user
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['message' => 'Invalid credentials']);
    exit;
}

// Set session
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

// Return user info (no password)
echo json_encode([
    'user_id' => $user['user_id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'fullname' => $user['fullname']
]);