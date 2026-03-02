<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$session_id = intval($data['session_id'] ?? 0);
$filename   = trim($data['filename'] ?? '');
$image_data = $data['image_data'] ?? ''; // data URL: data:image/jpeg;base64,...

if (!$session_id || !$filename || !$image_data) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Sanitize filename: allow alphanumeric, dash, underscore, dot only
$filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);

// Force image extension
if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
    $filename .= '.jpg';
}

// Strip data URL prefix and decode
if (!preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,(.+)$/s', $image_data, $m)) {
    echo json_encode(['success' => false, 'error' => 'Invalid image data']);
    exit();
}
$binary = base64_decode($m[2]);
if ($binary === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to decode image']);
    exit();
}

// DB connection
require_once '/var/www/secure_config/braintrust_config.php';

$stmt = $conn->prepare("
    SELECT p.project_path
    FROM braintrust_sessions s
    JOIN braintrust_projects p ON s.project_id = p.id
    WHERE s.id = ?
");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$project_rel = $result['project_path'] ?? null;
if (!$project_rel) {
    echo json_encode(['success' => false, 'error' => 'Project not found for this session']);
    exit();
}

// Build absolute path using BT3_PROJECTS_ROOT constant (set in config)
$project_path = (defined('BT3_PROJECTS_ROOT') ? BT3_PROJECTS_ROOT : '/var/www/html/collabchat/projects/')
              . ltrim($project_rel, '/');

if (!is_dir($project_path)) {
    echo json_encode(['success' => false, 'error' => 'Project directory does not exist: ' . $project_path]);
    exit();
}

$full_path = rtrim($project_path, '/') . '/' . $filename;

// Safety: ensure we stay inside project dir
$real_project = realpath($project_path);
$parent_real  = realpath(dirname($full_path));
if (!$real_project || !$parent_real || strpos($parent_real . '/', $real_project . '/') !== 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid path']);
    exit();
}

if (file_put_contents($full_path, $binary) === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to write file']);
    exit();
}

echo json_encode([
    'success'   => true,
    'filename'  => $filename,
    'full_path' => $full_path,
]);
?>
