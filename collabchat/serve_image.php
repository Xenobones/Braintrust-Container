<?php
/**
 * BrainTrust IDE - Image Server
 * Serves project image files with auth check and path validation
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Not authenticated');
}

require_once '/var/www/secure_config/braintrust_config.php';

if (!defined('BT3_PROJECTS_ROOT')) {
    define('BT3_PROJECTS_ROOT', '/var/www/html/collabchat/projects/');
}

$project = basename(trim($_GET['project'] ?? ''));
$path    = trim($_GET['path'] ?? '');

if (!$project || !$path) {
    http_response_code(400);
    exit('Missing parameters');
}

$real_root = realpath(rtrim(BT3_PROJECTS_ROOT, '/'));
$full_path = realpath($real_root . '/' . $project . '/' . $path);

// Path traversal check
if (!$full_path || !$real_root || strpos($full_path, $real_root . '/') !== 0) {
    http_response_code(403);
    exit('Access denied');
}

if (!is_file($full_path)) {
    http_response_code(404);
    exit('File not found');
}

$ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'bmp'  => 'image/bmp',
    'ico'  => 'image/x-icon',
    'tiff' => 'image/tiff',
    'tif'  => 'image/tiff',
];

$mime = $mime_map[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, max-age=300');
readfile($full_path);
exit;
?>
