<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$path = $data['path'] ?? '';

if (empty($path)) {
    echo json_encode(['success' => false, 'error' => 'No path provided']);
    exit();
}

// Security: Only allow browsing within safe directories
$allowedBasePaths = [
    '/var/www',
    '/home',
    '/etc',  // For viewing configs (but not editing sensitive ones)
    '/usr/local'
];

$realPath = realpath($path);
$isAllowed = false;

foreach ($allowedBasePaths as $basePath) {
    if ($realPath && strpos($realPath, $basePath) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed || !$realPath) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

if (!is_dir($realPath)) {
    echo json_encode(['success' => false, 'error' => 'Not a directory']);
    exit();
}

// Get directory contents
$items = [];
$dir = new DirectoryIterator($realPath);

foreach ($dir as $item) {
    if ($item->isDot()) continue;
    
    $items[] = [
        'name' => $item->getFilename(),
        'type' => $item->isDir() ? 'dir' : 'file',
        'size' => $item->isFile() ? $item->getSize() : 0,
        'modified' => $item->getMTime()
    ];
}

// Sort: directories first, then alphabetically
usort($items, function($a, $b) {
    if ($a['type'] !== $b['type']) {
        return $a['type'] === 'dir' ? -1 : 1;
    }
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode([
    'success' => true,
    'path' => $realPath,
    'items' => $items
]);
?>