<?php
/**
 * BrainTrust IDE - Files API
 * The "Hands" of the IDE - Read, Write, Create, Delete files
 * All operations sandboxed to BT3_PROJECTS_ROOT for security
 */

session_start();
require_once '/var/www/secure_config/braintrust_config.php';

header('Content-Type: application/json');

// Auth check - no sneaky business!
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// BT3_PROJECTS_ROOT correctly points to /collabchat/ per your server layout
if (!defined('BT3_PROJECTS_ROOT')) {
    define('BT3_PROJECTS_ROOT', '/var/www/html/braintrust-IDE-3/collabchat/projects/');
}

// Ensure base folder exists
if (!is_dir(BT3_PROJECTS_ROOT)) {
    mkdir(BT3_PROJECTS_ROOT, 0775, true);
}

// Use realpath once to lock the root for comparison (handles symlinks)
$realProjectsRoot = realpath(BT3_PROJECTS_ROOT);

switch ($action) {
    case 'list_projects':
        bt_listProjects($conn, $user_id);
        break;
    case 'create_project':
        bt_createProject($conn, $user_id);
        break;
    case 'get_tree':
        bt_getFileTree();
        break;
    case 'read_file':
        bt_readFile();
        break;
    case 'write_file':
        bt_writeFile();
        break;
    case 'create_file':
        bt_createFile();
        break;
    case 'create_folder':
        bt_createFolder();
        break;
    case 'upload_file':
        bt_uploadFile();
        break;
    case 'delete_item':
        bt_deleteItem();
        break;
    case 'rename_item':
        bt_renameItem();
        break;
    case 'list_snapshots':
        bt_listSnapshots();
        break;
    case 'restore_snapshot':
        bt_restoreSnapshot();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

$conn->close();

/**
 * SECURITY: Validate path is within BT3_PROJECTS_ROOT
 */
function validatePath($path) {
    global $realProjectsRoot;
    if (!$realProjectsRoot) return false;
    
    // Resolve absolute path
    $realPath = realpath($path);
    
    // For new files/dirs that don't exist yet, walk up to find an existing ancestor
    if ($realPath === false) {
        $check = $path;
        while ($check !== dirname($check)) {
            $check = dirname($check);
            $resolved = realpath($check);
            if ($resolved !== false) {
                return (strpos($resolved, $realProjectsRoot) === 0);
            }
        }
        return false;
    }
    
    // Ensure the resolved path starts with the resolved root
    return (strpos($realPath, $realProjectsRoot) === 0);
}

/**
 * Build full path from project and relative path
 */
function buildPath($project, $relativePath = '') {
    if (empty($project)) return ''; 
    
    $path = rtrim(BT3_PROJECTS_ROOT, '/') . '/' . basename($project);
    if (!empty($relativePath)) {
        $path .= '/' . ltrim($relativePath, '/');
    }
    return $path;
}

/**
 * List all projects for the user
 */
function bt_listProjects($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT id, project_name, project_path, git_repo, created_at, last_opened 
        FROM braintrust_projects 
        WHERE user_id = ? 
        ORDER BY last_opened DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $projects = array_map(function($row) {
        return [
            'id' => $row['id'],
            'name' => $row['project_name'],
            'path' => $row['project_path'],
            'git_repo' => $row['git_repo'],
            'created_at' => $row['created_at'],
            'last_opened' => $row['last_opened']
        ];
    }, $results);
    
    echo json_encode(['success' => true, 'projects' => $projects]);
}

/**
 * Create a new project
 */
function bt_createProject($conn, $user_id) {
    $name = trim($_POST['name'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Project name required']);
        return;
    }
    
    $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $path = rtrim(BT3_PROJECTS_ROOT, '/') . '/' . $folderName;
    
    if (is_dir($path)) {
        echo json_encode(['success' => false, 'error' => 'Project folder already exists']);
        return;
    }
    
    if (!mkdir($path, 0775, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create project folder']);
        return;
    }
    
    $readmeContent = "# {$name}\n\n" .
        "Welcome, Claude and Gemini! You are collaborating with a human developer in BrainTrust IDE — " .
        "a shared workspace where all three of you can work together on this project.\n\n" .
        "## Ground Rules\n" .
        "- The human directs the work. Follow their instructions and ask before acting on anything not explicitly requested.\n" .
        "- Only read files you are asked to read. Only make changes you are asked to make.\n" .
        "- If you notice something that looks like it needs attention, mention it — don't fix it without asking.\n" .
        "- You can see each other's messages and build on each other's work.\n\n" .
        "## File Protocols\n" .
        "These protocols are how the IDE tracks file operations — use them instead of any built-in file tools you may have. " .
        "Native tools (Edit, Write, Bash, etc.) bypass the IDE's snapshot system, version history, and file visibility. " .
        "Always use these protocols for anything in the project folder:\n" .
        "To read a file, put this on its own line in your response: READ_FILE: filename\n" .
        "To create or update a file, put this on its own line followed by a fenced code block: CREATE_FILE: filename\n" .
        "To run a script in the sandbox: RUN_FILE: filename\n" .
        "Important: the sandbox is non-interactive. Scripts that use input() or any stdin prompt will fail with EOFError. Write scripts that run start-to-finish without user input.\n\n" .
        "## Database\n" .
        "A shared DB config is available for all projects. To connect to MySQL, add this at the top of your PHP file:\n" .
        "require_once '/var/www/secure_config/dev_db_config.php';\n" .
        "Then connect with: new mysqli(DEV_DB_HOST, DEV_DB_USER, DEV_DB_PASS, DEV_DB_NAME)\n\n" .
        "## Whiteboard\n" .
        "The whiteboard is NOT a tool you call. It works like this: whenever your response contains a mermaid " .
        "code block (triple backticks with 'mermaid'), the IDE automatically detects it and renders the diagram " .
        "on the whiteboard panel. There is no command, no function, no tool to invoke. Just write the mermaid " .
        "block in your response and it appears on the whiteboard. That's it.\n" .
        "Readability tip: use white or light text on dark node backgrounds (e.g. fill:#336,color:#fff) so the diagram is legible on screen.\n";
    file_put_contents($path . '/README.md', $readmeContent);
    
    $stmt = $conn->prepare("INSERT INTO braintrust_projects (project_name, project_path, user_id) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $name, $folderName, $user_id);
    $stmt->execute();
    $project_id = $conn->insert_id;
    $stmt->close();
    
    echo json_encode([
        'success' => true, 
        'project' => [
            'id' => $project_id,
            'name' => $name,
            'path' => $folderName
        ]
    ]);
}

/**
 * Get file tree for a project
 */
function bt_getFileTree() {
    $project = $_GET['project'] ?? '';
    if (empty($project)) {
        echo json_encode(['success' => false, 'error' => 'Project required']);
        return;
    }
    
    $path = buildPath($project);
    if (!validatePath($path)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path - sandbox restriction']);
        return;
    }
    
    if (!is_dir($path)) {
        echo json_encode(['success' => false, 'error' => 'Project not found']);
        return;
    }
    
    $tree = scanDirectory($path, $project);
    echo json_encode(['success' => true, 'tree' => $tree]);
}

/**
 * Recursively scan directory
 */
function scanDirectory($dir, $project, $relativePath = '') {
    $entries = scandir($dir);
    $folders = [];
    $files = [];
    
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === '.snapshots') continue;
        
        $fullPath = $dir . '/' . $entry;
        $itemRelativePath = $relativePath ? $relativePath . '/' . $entry : $entry;
        
        if (is_dir($fullPath)) {
            $folders[] = [
                'name' => $entry,
                'type' => 'folder',
                'path' => $itemRelativePath,
                'children' => scanDirectory($fullPath, $project, $itemRelativePath)
            ];
        } else {
            $files[] = [
                'name' => $entry,
                'type' => 'file',
                'path' => $itemRelativePath,
                'size' => filesize($fullPath),
                'modified' => filemtime($fullPath),
                'extension' => pathinfo($entry, PATHINFO_EXTENSION)
            ];
        }
    }
    
    usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return array_merge($folders, $files);
}

/**
 * Read file contents
 */
function bt_readFile() {
    $project = $_GET['project'] ?? '';
    $filePath = $_GET['path'] ?? '';
    
    if (empty($project) || empty($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Project and path required']);
        return;
    }
    
    $fullPath = buildPath($project, $filePath);
    if (!validatePath($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path - nice try! 😉']);
        return;
    }
    
    if (!file_exists($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'File not found: ' . $filePath]);
        return;
    }
    
    $content = file_get_contents($fullPath);
    $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
    
    echo json_encode([
        'success' => true,
        'content' => $content,
        'language' => getLanguageFromExtension($extension),
        'path' => $filePath
    ]);
}

/**
 * Write file contents
 */
function bt_writeFile() {
    $project = $_POST['project'] ?? '';
    $filePath = $_POST['path'] ?? '';
    $content = $_POST['content'] ?? '';
    
    if (empty($project) || empty($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Project and path required']);
        return;
    }
    
    $fullPath = buildPath($project, $filePath);
    if (!validatePath($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path - sandbox protection! 🏖️']);
        return;
    }
    
    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    // Snapshot existing file before overwriting (only if content actually changed)
    if (file_exists($fullPath)) {
        $existingContent = file_get_contents($fullPath);
        if ($existingContent !== $content) {
            btSnapshotFile(buildPath($project), $filePath);
        }
    }

    if (file_put_contents($fullPath, $content) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to write file']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'File saved successfully']);
}

/**
 * Upload file from user's computer
 */
function bt_uploadFile() {
    $project = $_POST['project'] ?? '';
    $directory = $_POST['directory'] ?? '';

    if (empty($project)) {
        echo json_encode(['success' => false, 'error' => 'Project required']);
        return;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
        return;
    }

    $fileName = basename($_FILES['file']['name']);
    $relativePath = $directory ? trim($directory, '/') . '/' . $fileName : $fileName;
    $fullPath = buildPath($project, $relativePath);

    if (!validatePath($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path - sandbox protection!']);
        return;
    }

    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'File uploaded', 'path' => $relativePath]);
}

/**
 * Create new file
 */
function bt_createFile() {
    $project = $_POST['project'] ?? '';
    $filePath = $_POST['path'] ?? '';
    $content = $_POST['content'] ?? '';
    
    if (empty($project) || empty($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Project and path required']);
        return;
    }
    
    $fullPath = buildPath($project, $filePath);
    if (!validatePath($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path']);
        return;
    }
    
    if (file_exists($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'File already exists']);
        return;
    }
    
    $dir = dirname($fullPath);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    
    if (file_put_contents($fullPath, $content) === false) {
        echo json_encode(['success' => false, 'error' => 'Failed to create file']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'File created successfully']);
}

/**
 * Create folder
 */
function bt_createFolder() {
    $project = $_POST['project'] ?? '';
    $folderPath = $_POST['path'] ?? '';
    
    if (empty($project) || empty($folderPath)) {
        echo json_encode(['success' => false, 'error' => 'Project and path required']);
        return;
    }
    
    $fullPath = buildPath($project, $folderPath);
    if (!validatePath($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path']);
        return;
    }
    
    if (is_dir($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Folder already exists']);
        return;
    }
    
    if (!mkdir($fullPath, 0775, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create folder']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'Folder created successfully']);
}

/**
 * Delete item
 */
function bt_deleteItem() {
    $project = $_POST['project'] ?? '';
    $itemPath = $_POST['path'] ?? '';
    
    if (empty($project) || empty($itemPath)) {
        echo json_encode(['success' => false, 'error' => 'Project and path required']);
        return;
    }
    
    $fullPath = buildPath($project, $itemPath);
    if (!validatePath($fullPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path']);
        return;
    }
    
    if (is_dir($fullPath)) {
        if (!deleteDirectory($fullPath)) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete folder']);
            return;
        }
    } else {
        unlink($fullPath);
    }
    
    echo json_encode(['success' => true, 'message' => 'Deleted successfully']);
}

/**
 * Recursive delete
 */
function deleteDirectory($dir) {
    if (!is_dir($dir)) return false;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) deleteDirectory($path);
        else unlink($path);
    }
    return rmdir($dir);
}

/**
 * Rename item
 */
function bt_renameItem() {
    $project = $_POST['project'] ?? '';
    $oldPath = $_POST['old_path'] ?? '';
    $newPath = $_POST['new_path'] ?? '';
    
    $fullOldPath = buildPath($project, $oldPath);
    $fullNewPath = buildPath($project, $newPath);
    
    if (!validatePath($fullOldPath) || !validatePath($fullNewPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path']);
        return;
    }
    
    if (rename($fullOldPath, $fullNewPath)) {
        echo json_encode(['success' => true, 'message' => 'Renamed successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Rename failed']);
    }
}

/**
 * Monaco language map
 */
function getLanguageFromExtension($ext) {
    $map = [
        'php' => 'php', 'js' => 'javascript', 'jsx' => 'javascript', 'ts' => 'typescript', 'tsx' => 'typescript',
        'html' => 'html', 'htm' => 'html', 'css' => 'css', 'scss' => 'scss', 'less' => 'less',
        'json' => 'json', 'xml' => 'xml', 'sql' => 'sql', 'md' => 'markdown', 'markdown' => 'markdown',
        'py' => 'python', 'rb' => 'ruby', 'java' => 'java', 'c' => 'c', 'cpp' => 'cpp',
        'sh' => 'shell', 'bash' => 'shell', 'yml' => 'yaml', 'yaml' => 'yaml',
        'txt' => 'plaintext', 'log' => 'plaintext'
    ];
    return $map[strtolower($ext)] ?? 'plaintext';
}

/**
 * Create a snapshot of a file before overwriting it.
 * Shared helper used by bt_writeFile and bt_restoreSnapshot.
 * Stores in .snapshots/ directory; prunes to 20 snapshots per file.
 */
function btSnapshotFile($projectDir, $filePath) {
    $fullPath = rtrim($projectDir, '/') . '/' . ltrim($filePath, '/');
    if (!file_exists($fullPath)) return;

    $content = file_get_contents($fullPath);
    if ($content === false) return;

    $snapDir = rtrim($projectDir, '/') . '/.snapshots';
    if (!is_dir($snapDir)) mkdir($snapDir, 0775, true);

    $safeName = str_replace(['/', '\\'], '--', ltrim($filePath, '/'));
    file_put_contents($snapDir . '/' . $safeName . '_' . time(), $content);

    // Prune: keep max 20 snapshots per file
    $files = glob($snapDir . '/' . $safeName . '_*');
    if (count($files) > 20) {
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        $toDelete = count($files) - 20;
        for ($i = 0; $i < $toDelete; $i++) unlink($files[$i]);
    }
}

/**
 * List snapshots for a specific file
 */
function bt_listSnapshots() {
    $project = $_GET['project'] ?? '';
    $filePath = $_GET['path'] ?? '';

    if (empty($project) || empty($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Project and path required']);
        return;
    }

    $projectDir = buildPath($project);
    $snapshotDir = $projectDir . '/.snapshots';

    if (!is_dir($snapshotDir)) {
        echo json_encode(['success' => true, 'snapshots' => []]);
        return;
    }

    $safeName = str_replace(['/', '\\'], '--', ltrim($filePath, '/'));
    $files = glob($snapshotDir . '/' . $safeName . '_*');

    $snapshots = [];
    foreach ($files as $file) {
        $basename = basename($file);
        $parts = explode('_', $basename);
        $timestamp = intval(end($parts));
        $snapshots[] = [
            'filename' => $basename,
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s', $timestamp),
            'size' => filesize($file)
        ];
    }

    usort($snapshots, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    echo json_encode(['success' => true, 'snapshots' => $snapshots]);
}

/**
 * Restore a snapshot to the original file
 */
function bt_restoreSnapshot() {
    $project = $_POST['project'] ?? '';
    $filePath = $_POST['path'] ?? '';
    $snapshotName = $_POST['snapshot'] ?? '';

    if (empty($project) || empty($filePath) || empty($snapshotName)) {
        echo json_encode(['success' => false, 'error' => 'Project, path, and snapshot required']);
        return;
    }

    $projectDir = buildPath($project);
    $snapshotPath = $projectDir . '/.snapshots/' . basename($snapshotName);
    $targetPath = buildPath($project, $filePath);

    if (!validatePath($targetPath)) {
        echo json_encode(['success' => false, 'error' => 'Invalid path']);
        return;
    }

    if (!file_exists($snapshotPath)) {
        echo json_encode(['success' => false, 'error' => 'Snapshot not found']);
        return;
    }

    // Snapshot current version before restoring (only if content differs - prevents duplicate snapshots)
    if (file_exists($targetPath)) {
        $currentContent = file_get_contents($targetPath);
        $snapshotContent = file_get_contents($snapshotPath);
        if ($currentContent !== $snapshotContent) {
            btSnapshotFile($projectDir, $filePath);
        }
    }

    $content = file_get_contents($snapshotPath);
    file_put_contents($targetPath, $content);

    echo json_encode(['success' => true, 'message' => 'Snapshot restored', 'content' => $content]);
}

?>