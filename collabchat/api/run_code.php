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
$projectPath = $data['project_path'] ?? null;
$filename = $data['filename'] ?? null;

if (!$sessionId || !$projectPath || !$filename) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

// Sanitize inputs
$safePath = str_replace(['..', '//', '\\'], '', $projectPath);
// Preserve subdirectory structure but sanitize for path traversal
$safeFilename = str_replace('\\', '/', $filename); // Convert backslashes to forward slashes
$safeFilename = str_replace('..', '', $safeFilename); // Block directory traversal
$safeFilename = ltrim($safeFilename, '/'); // Remove leading slashes

// Full path to project
$projectDir = "/var/www/html/braintrust-IDE-3/collabchat/projects/{$safePath}";
$scriptPath = "{$projectDir}/{$safeFilename}";

// Verify file exists
if (!file_exists($scriptPath)) {
    // Extra debug: list what IS in the directory
    $dirContents = is_dir($projectDir) ? implode(', ', array_diff(scandir($projectDir), ['.','..'])) : 'DIR NOT FOUND';
    $hexFilename = bin2hex($safeFilename);
    $debugInfo = "DEBUG INFO:\n" .
                 "Received project_path: {$projectPath}\n" .
                 "Received filename: {$filename}\n" .
                 "Filename hex: {$hexFilename}\n" .
                 "Safe path: {$safePath}\n" .
                 "Safe filename: {$safeFilename}\n" .
                 "Project dir: {$projectDir}\n" .
                 "Script path: {$scriptPath}\n" .
                 "File exists: " . (file_exists($scriptPath) ? 'yes' : 'no') . "\n" .
                 "Dir contents: {$dirContents}";

    echo json_encode([
        'success' => false,
        'error' => 'File not found',
        'output' => $debugInfo,
        'exit_code' => 'N/A'
    ]);
    exit();
}

// Get file extension
$extension = strtolower(pathinfo($safeFilename, PATHINFO_EXTENSION));

// Route to appropriate handler
switch ($extension) {
    case 'py':
        $result = runPython($projectDir, $safeFilename);
        break;
    case 'php':
    case 'html':
    case 'htm':
        $result = runBrowser($projectDir, $safeFilename);
        break;
    default:
        echo json_encode(['success' => false, 'error' => "Unsupported file type: .{$extension}"]);
        exit();
}

echo json_encode($result);

// ============== EXECUTION FUNCTIONS ==============

function runPython($projectDir, $filename) {
    // Feed 20 newlines via stdin so input() calls return "" instead of raising EOFError.
    // After 20 inputs stdin closes and python exits cleanly. Prevents infinite-loop scripts
    // from running for 30 seconds when they call input() in a loop.
    $dockerCmd = sprintf(
        '(printf "n\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\nn\\n" | docker run --rm -i ' .
        '-v %s:/workspace:ro ' .
        '-w /workspace ' .
        '--network none ' .
        '--memory="256m" ' .
        '--cpus="0.5" ' .
        '--pids-limit 50 ' .
        'braintrust-python ' .
        'timeout 30 python %s) 2>&1',
        escapeshellarg($projectDir),
        escapeshellarg($filename)
    );

    exec($dockerCmd, $output, $exitCode);

    // Cap output to 300 lines so json_encode never produces a massive response
    if (count($output) > 300) {
        $output = array_slice($output, 0, 300);
        $output[] = '... (output truncated at 300 lines)';
    }

    return [
        'success' => $exitCode === 0,
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
        'executed_in' => 'docker',
        'container' => 'braintrust-python'
    ];
}

function runPhp($projectDir, $filename) {
    $dockerCmd = sprintf(
        'docker run --rm ' .
        '-v %s:/workspace:ro ' .
        '-w /workspace ' .
        '--network none ' .
        '--memory="256m" ' .
        '--cpus="0.5" ' .
        '--pids-limit 50 ' .
        'php:8.2-cli ' .
        'timeout 30 php %s 2>&1',
        escapeshellarg($projectDir),
        escapeshellarg($filename)
    );
    
    exec($dockerCmd, $output, $exitCode);
    
    return [
        'success' => $exitCode === 0,
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
        'executed_in' => 'docker',
        'container' => 'php:8.2-cli'
    ];
}

function runBrowser($projectDir, $filename) {
    $projectName = basename($projectDir);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    // PHP files served directly by Apache (full execution + DB access)
    // HTML files also served directly — Apache handles both fine
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = getenv('APP_BASE_PATH') ?: '/collabchat/projects';
    $previewUrl = "{$scheme}://{$host}{$basePath}/{$projectName}/{$filename}";
    $type = ($ext === 'php') ? 'PHP web app' : 'HTML file';

    return [
        'success'     => true,
        'output'      => "✅ {$type} ready — opening in browser!\n\n📄 File: {$filename}\n🔗 {$previewUrl}",
        'exit_code'   => 0,
        'executed_in' => 'preview',
        'preview_url' => $previewUrl,
        'container'   => 'none (Apache)'
    ];
}
?>