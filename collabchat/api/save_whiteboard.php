<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

require_once '/var/www/secure_config/braintrust_config.php';

$data = json_decode(file_get_contents('php://input'), true);
$sessionId = $data['project_id'] ?? null;
$projectPath = $data['project_path'] ?? null;
$diagramHtml = $data['diagram_html'] ?? null;
$diagramSource = $data['diagram_source'] ?? null;

if (!$sessionId || !$projectPath || !$diagramHtml || !$diagramSource) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Sanitize project path
$safePath = str_replace(['..', '//', '\\'], '', $projectPath);
$projectDir = "/var/www/html/collabchat/projects/{$safePath}";

// Create whiteboards directory
$whiteboardDir = "{$projectDir}/whiteboards";
if (!is_dir($whiteboardDir)) {
    mkdir($whiteboardDir, 0755, true);
}

// Generate filename with timestamp
$timestamp = date('Y-m-d_His');
$baseFilename = "diagram_{$timestamp}";

// Save .mmd source file
$mmdFile = "{$whiteboardDir}/{$baseFilename}.mmd";
file_put_contents($mmdFile, $diagramSource);

// Save .svg rendered file
$svgFile = "{$whiteboardDir}/{$baseFilename}.svg";
if (preg_match('/<svg[^>]*>.*?<\/svg>/s', $diagramHtml, $matches)) {
    file_put_contents($svgFile, $matches[0]);
} else {
    file_put_contents($svgFile, $diagramHtml);
}

echo json_encode([
    'success' => true,
    'files' => [
        'source' => "{$baseFilename}.mmd",
        'rendered' => "{$baseFilename}.svg"
    ]
]);