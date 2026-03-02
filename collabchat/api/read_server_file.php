<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$path = $data['path'] ?? '';

$realPath = realpath($path);

if (!$realPath || !file_exists($realPath) || !is_file($realPath)) {
    echo json_encode(['success' => false, 'error' => 'File not found']);
    exit();
}

$content = file_get_contents($realPath);

echo json_encode([
    'success' => true,
    'content' => $content,
    'path' => $realPath
]);
?>