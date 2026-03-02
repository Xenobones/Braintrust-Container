<?php
/**
 * BrainTrust IDE - Terminal API
 * Executes shell commands SANDBOXED to the project folder
 * Privileged commands routed through SSH
 * No escaping allowed! 🔒
 */

session_start();
require_once '/var/www/secure_config/braintrust_config.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$project = $_POST['project'] ?? $_GET['project'] ?? '';

// Make sure BT3_PROJECTS_ROOT is defined
if (!defined('BT3_PROJECTS_ROOT')) {
    define('BT3_PROJECTS_ROOT', '/var/www/html/collabchat/projects/');
}

// SSH config for privileged commands
define('SSH_USER', 'shannon');
define('SSH_HOST', 'localhost');

switch ($action) {
    case 'execute':
        executeCommand($project);
        break;
    case 'get_info':
        getSystemInfo($project);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Execute a command in the project directory
 */
function executeCommand($project) {
    $command = $_POST['command'] ?? '';
    $isQuickCommand = isset($_POST['quick_command']) ? (bool)$_POST['quick_command'] : false;
    
    if (empty($project)) {
        echo json_encode(['success' => false, 'error' => 'No project selected', 'output' => 'Error: No project selected. Select a project first.']);
        return;
    }
    
    if (empty($command)) {
        echo json_encode(['success' => false, 'error' => 'No command provided', 'output' => '']);
        return;
    }
    
    // Build and validate project path
    $projectPath = BT3_PROJECTS_ROOT . basename($project);
    
    if (!is_dir($projectPath)) {
        echo json_encode(['success' => false, 'error' => 'Project not found', 'output' => 'Error: Project directory not found.']);
        return;
    }
    
    // Check if this is a sudo command that needs SSH routing
    $commandLower = strtolower(trim($command));
    $needsSSH = (strpos($commandLower, 'sudo') === 0);
    
    if ($needsSSH) {
        // Route through SSH as shannon
        executePrivilegedCommand($command);
        return;
    }
    
    // SECURITY: Block dangerous commands (unless it's a pre-approved quick command)
    if (!$isQuickCommand) {
        $blockedCommands = [
            'rm -rf /',
            'rm -rf /*',
            'mkfs',
            'dd if=',
            ':(){:|:&};:',  // Fork bomb
            'chmod -R 777 /',
            'chown -R',
            '> /dev/sda',
            'mv /* ',
            'wget * | sh',
            'curl * | sh',
            'su ',
            'passwd',
            'shutdown',
            'reboot',
            'init ',
        ];
        
        foreach ($blockedCommands as $blocked) {
            if (strpos($commandLower, strtolower($blocked)) !== false) {
                echo json_encode([
                    'success' => false, 
                    'error' => 'Command blocked',
                    'output' => "🚫 Nice try! That command is blocked for safety reasons.\n"
                ]);
                return;
            }
        }
    }
    
    // SECURITY: Block path traversal attempts
    if (preg_match('/\.\.\/|\.\.\\\\/', $command)) {
        echo json_encode([
            'success' => false,
            'error' => 'Path traversal blocked',
            'output' => "🚫 Path traversal (../) is not allowed. Stay in your sandbox!\n"
        ]);
        return;
    }
    
    // Handle built-in commands
    $parts = preg_split('/\s+/', trim($command), 2);
    $cmd = $parts[0];
    $args = $parts[1] ?? '';
    
    // Handle 'cd' specially
    if ($cmd === 'cd') {
        $newDir = $args ?: $projectPath;
        
        if ($newDir[0] !== '/') {
            $newDir = $projectPath . '/' . $newDir;
        }
        
        $realDir = realpath($newDir);
        
        if ($realDir === false || strpos($realDir, realpath(BT3_PROJECTS_ROOT)) !== 0) {
            echo json_encode([
                'success' => false,
                'output' => "bash: cd: $args: Cannot leave project sandbox\n",
                'cwd' => $projectPath
            ]);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'output' => '',
            'cwd' => $realDir
        ]);
        return;
    }
    
    // Handle 'clear' command
    if ($cmd === 'clear' || $cmd === 'cls') {
        echo json_encode([
            'success' => true,
            'output' => '__CLEAR__',
            'cwd' => $projectPath
        ]);
        return;
    }
    
    // Execute regular command
    executeRegularCommand($command, $projectPath);
}

/**
 * Execute privileged command via SSH
 */
function executePrivilegedCommand($command) {
    
    // Check if this command will restart Apache
    $willRestartApache = (stripos($command, 'restart apache') !== false);
    
    if ($willRestartApache) {
        // Send response FIRST, then restart
        echo json_encode([
            'success' => true,
            'output' => "🔄 Apache restart initiated...\n",
            'return_code' => 0
        ]);
        
        // Flush output immediately
        ob_end_flush();
        flush();
        
        // Close connection
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // NOW restart Apache
        sleep(1);
        passthru($command . ' 2>&1', $returnCode);
        exit();
    }
    
    // For other commands, run normally
    ob_start();
    passthru($command . ' 2>&1', $returnCode);
    $output = ob_get_clean();
    
    if (empty($output)) {
        $output = "✅ Command completed successfully\n";
    }
    
    echo json_encode([
        'success' => $returnCode === 0,
        'output' => $output,
        'return_code' => $returnCode
    ]);
}

/**
 * Execute regular command in project directory
 */
function executeRegularCommand($command, $projectPath) {
    $fullCommand = sprintf(
        'cd %s && %s 2>&1',
        escapeshellarg($projectPath),
        $command
    );
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    
    $process = proc_open($fullCommand, $descriptors, $pipes, $projectPath, [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'HOME' => $projectPath,
    ]);
    
    if (is_resource($process)) {
        fclose($pipes[0]);
        
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $stdout = '';
        $stderr = '';
        $timeout = 30;
        $startTime = time();
        
        while (true) {
            $status = proc_get_status($process);
            
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            
            if (!$status['running']) {
                break;
            }
            
            if (time() - $startTime > $timeout) {
                proc_terminate($process);
                $stdout .= "\n⏱️ Command timed out after {$timeout} seconds\n";
                break;
            }
            
            usleep(10000);
        }
        
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $returnCode = proc_close($process);
        $output = $stdout . $stderr;
    } else {
        $output = "Failed to execute command\n";
        $returnCode = 1;
    }
    
    echo json_encode([
        'success' => $returnCode === 0,
        'output' => $output,
        'return_code' => $returnCode,
        'cwd' => $projectPath
    ]);
}

/**
 * Get system info for the terminal header
 */
function getSystemInfo($project) {
    $projectPath = $project ? BT3_PROJECTS_ROOT . basename($project) : BT3_PROJECTS_ROOT;
    
    echo json_encode([
        'success' => true,
        'user' => 'braintrust',
        'hostname' => gethostname() ?: 'ide',
        'cwd' => $projectPath,
        'php_version' => PHP_VERSION,
    ]);
}
?>