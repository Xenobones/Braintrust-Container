<?php
// api/generate_project_map.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Use the actual BT3_PROJECTS_ROOT (shared with v1 so both versions see the same projects)
define('BT3_PROJECTS_ROOT', '/var/www/html/collabchat/projects/');

$project_name = basename($_GET['project'] ?? '');
if (!$project_name) {
    echo json_encode(['success' => false, 'error' => 'No project specified']);
    exit;
}

$full_path = realpath(BT3_PROJECTS_ROOT . $project_name);
$base_dir  = realpath(BT3_PROJECTS_ROOT);

if (!$full_path || !$base_dir || strpos($full_path, $base_dir) !== 0) {
    echo json_encode(['success' => false, 'error' => 'Project not found or invalid path']);
    exit;
}

// ── Scan project files ──────────────────────────────────────────────────────
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($full_path, RecursiveDirectoryIterator::SKIP_DOTS)
);

$relationships = [];
$file_list     = [];
$all_nodes     = [];   // every file we encounter (for orphan nodes)

foreach ($iterator as $file) {
    if ($file->isDir()) continue;

    // Skip hidden dirs like .snapshots
    $relative = str_replace($full_path . DIRECTORY_SEPARATOR, '', $file->getPathname());
    if (strpos($relative, '.') === 0 || strpos($relative, '/.') !== false || strpos($relative, '\\.') !== false) continue;

    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'js', 'html', 'htm'])) continue;

    $file_list[]  = $relative;
    $all_nodes[]  = $relative;
    $file_content = @file_get_contents($file->getPathname()) ?: '';

    // PHP include / require
    preg_match_all('/(?:include|require)(?:_once)?\s*[\'"]([^\'"]+)[\'"]/', $file_content, $php_matches);
    foreach ($php_matches[1] as $match) {
        $target = basename($match);
        $relationships[] = ['from' => $relative, 'to' => $target, 'type' => 'include'];
        $all_nodes[] = $target;
    }

    // JS fetch() -> .php endpoints
    preg_match_all('/fetch\s*\(\s*[\'"`]([^\'"` ]+\.php)[\'"`]/', $file_content, $fetch_matches);
    foreach ($fetch_matches[1] as $match) {
        $target = basename($match);
        $relationships[] = ['from' => $relative, 'to' => $target, 'type' => 'fetch'];
        $all_nodes[] = $target;
    }

    // JS import statements
    preg_match_all('/import\s+.*\s+from\s+[\'"]([^\'"]+)[\'"]/', $file_content, $import_matches);
    foreach ($import_matches[1] as $match) {
        $target = basename($match);
        $relationships[] = ['from' => $relative, 'to' => $target, 'type' => 'import'];
        $all_nodes[] = $target;
    }
}

// ── Build Mermaid diagram ───────────────────────────────────────────────────
// Safe node ID: replace anything non-alphanumeric with _
function nodeId($name) {
    return preg_replace('/[^a-zA-Z0-9]/', '_', $name);
}

$unique_nodes = array_unique($all_nodes);
$mermaid = "graph TD\n";

// Declare all nodes first so orphans appear even with no edges
foreach ($unique_nodes as $node) {
    $id    = nodeId($node);
    $label = htmlspecialchars($node, ENT_QUOTES);
    $mermaid .= "    {$id}[\"{$label}\"]\n";
}

// Add edges
foreach ($relationships as $rel) {
    $from  = nodeId($rel['from']);
    $to    = nodeId($rel['to']);
    $label = $rel['type'];
    $mermaid .= "    {$from} -->|{$label}| {$to}\n";
}

// ── Save .PROJECT_MAP.md to project folder ──────────────────────────────────
$map_content  = "# Project Architecture Map\n\n";
$map_content .= "> Generated: " . date('Y-m-d H:i:s') . " | Files scanned: " . count($file_list) . "\n\n";
$map_content .= "```mermaid\n" . $mermaid . "```\n\n";
$map_content .= "## Files Scanned\n";
foreach ($file_list as $f) {
    $map_content .= "- {$f}\n";
}

file_put_contents($full_path . '/.PROJECT_MAP.md', $map_content);

echo json_encode([
    'success'    => true,
    'mermaid'    => $mermaid,
    'file_count' => count($file_list),
    'edge_count' => count($relationships),
]);
