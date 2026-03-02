<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

require_once '/var/www/secure_config/braintrust_config.php';

$data = json_decode(file_get_contents('php://input'), true);
$sessionId = $data['session_id'] ?? null;
$message = $data['message'] ?? null;

if (!$sessionId || !$message) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Insert system message
$stmt = $conn->prepare("
    INSERT INTO braintrust_messages (session_id, sender_type, message_text, token_count)
    VALUES (?, 'system', ?, 0)
");
$stmt->bind_param("is", $sessionId, $message);
$success = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $success]);