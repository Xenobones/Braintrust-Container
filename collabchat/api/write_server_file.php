<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$path = $data['path'] ?? '';
$content = $data['content'] ?? '';

$realPath = realpath($path);

if (!$realPath || !file_exists($realPath) || !is_file($realPath)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit();
}

if (file_put_contents($realPath, $content) !== false) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to write file']);
}
?>