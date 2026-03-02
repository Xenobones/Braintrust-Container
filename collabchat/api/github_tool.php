<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

require_once '/var/www/secure_config/braintrust_config.php';

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$project = trim($_POST['project'] ?? $_GET['project'] ?? '');

// Sanitize project name and build path
$project = basename($project);
if (!$project) {
    echo json_encode(['success' => false, 'error' => 'No project specified']);
    exit();
}
$project_path = rtrim(PROJECTS_ROOT, '/') . '/' . $project;
if (!is_dir($project_path)) {
    echo json_encode(['success' => false, 'error' => 'Project directory not found']);
    exit();
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function run_git($args, $cwd) {
    $username = defined('GITHUB_USERNAME') ? GITHUB_USERNAME : 'BrainTrust';
    $email    = $username . '@users.noreply.github.com';
    $cmd = 'git -C ' . escapeshellarg($cwd)
         . ' -c user.name='  . escapeshellarg($username)
         . ' -c user.email=' . escapeshellarg($email)
         . ' ' . $args . ' 2>&1';
    exec($cmd, $output, $exit_code);
    return ['output' => implode("\n", $output), 'code' => $exit_code];
}

function github_api($method, $endpoint, $data = null) {
    $token = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';
    $ch = curl_init('https://api.github.com' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: token ' . $token,
            'User-Agent: BrainTrust-IDE',
            'Accept: application/vnd.github.v3+json',
            'Content-Type: application/json',
        ],
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => json_decode($response, true), 'status' => $http_code];
}

// ── Actions ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── status ────────────────────────────────────────────────────────────────
    case 'status':
        $configured = defined('GITHUB_TOKEN') && GITHUB_TOKEN !== '';

        $has_git    = is_dir($project_path . '/.git');
        $remote_url = '';
        $branch     = 'main';
        $changed    = [];

        if ($has_git) {
            $r = run_git('remote get-url origin', $project_path);
            if ($r['code'] === 0) $remote_url = trim($r['output']);

            $r = run_git('branch --show-current', $project_path);
            if ($r['code'] === 0 && trim($r['output'])) $branch = trim($r['output']);

            $r = run_git('status --porcelain', $project_path);
            foreach (explode("\n", $r['output']) as $line) {
                $line = trim($line);
                if (!$line) continue;
                $status = substr($line, 0, 2);
                $file   = trim(substr($line, 3));
                $label  = match(true) {
                    str_contains($status, 'M') => 'Modified',
                    str_contains($status, 'A') => 'Added',
                    str_contains($status, 'D') => 'Deleted',
                    str_contains($status, 'R') => 'Renamed',
                    str_contains($status, '?') => 'Untracked',
                    default                    => trim($status),
                };
                $changed[] = ['file' => $file, 'status' => $label, 'raw' => $status];
            }
        }

        echo json_encode([
            'success'     => true,
            'configured'  => $configured,
            'has_git'     => $has_git,
            'remote_url'  => $remote_url,
            'branch'      => $branch,
            'changed'     => $changed,
            'username'    => defined('GITHUB_USERNAME') ? GITHUB_USERNAME : '',
        ]);
        break;

    // ── get_files (for .gitignore wizard) ────────────────────────────────────
    case 'get_files':
        $items = [];
        $common_ignores = ['node_modules', '__pycache__', 'venv', '.venv', 'dist',
                           'build', '.env', '*.log', '*.pyc', '.DS_Store'];

        if ($handle = opendir($project_path)) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..' || $entry === '.git') continue;
                $full    = $project_path . '/' . $entry;
                $is_dir  = is_dir($full);
                $label   = $entry . ($is_dir ? '/' : '');
                // Pre-check if it matches a common ignore pattern
                $checked = in_array($entry, $common_ignores)
                        || in_array($entry . '/', $common_ignores)
                        || in_array($label, $common_ignores);
                $items[] = ['name' => $label, 'checked' => $checked, 'is_dir' => $is_dir];
            }
            closedir($handle);
        }
        // Dirs first, then files, alphabetical
        usort($items, fn($a, $b) => ($b['is_dir'] <=> $a['is_dir']) ?: strcmp($a['name'], $b['name']));

        echo json_encode(['success' => true, 'files' => $items]);
        break;

    // ── init_push (new repo wizard) ───────────────────────────────────────────
    case 'init_push':
        $repo_name      = preg_replace('/[^a-zA-Z0-9_\-.]/', '-', trim($_POST['repo_name'] ?? $project));
        $private        = ($_POST['private'] ?? 'true') === 'true';
        $gitignore_list = json_decode($_POST['gitignore'] ?? '[]', true) ?: [];
        $commit_msg     = trim($_POST['commit_message'] ?? 'Initial commit');
        if (!$commit_msg) $commit_msg = 'Initial commit';

        $token    = defined('GITHUB_TOKEN')    ? GITHUB_TOKEN    : '';
        $username = defined('GITHUB_USERNAME') ? GITHUB_USERNAME : '';

        if (!$token || !$username) {
            echo json_encode(['success' => false, 'error' => 'GitHub token/username not configured in braintrust_config.php']);
            break;
        }

        // Write .gitignore
        if (!empty($gitignore_list)) {
            file_put_contents($project_path . '/.gitignore', implode("\n", $gitignore_list) . "\n.terminal_history\n");
        } else {
            file_put_contents($project_path . '/.gitignore', ".terminal_history\n");
        }

        // git init (safe to run even if already inited)
        $r = run_git('init -b main', $project_path);
        if ($r['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'git init failed: ' . $r['output']]); break;
        }

        // Create GitHub repo
        $resp = github_api('POST', '/user/repos', [
            'name'    => $repo_name,
            'private' => $private,
            'auto_init' => false,
        ]);
        if ($resp['status'] !== 201) {
            $msg = $resp['body']['message'] ?? 'Unknown error';
            echo json_encode(['success' => false, 'error' => 'GitHub API error: ' . $msg]); break;
        }
        $clone_url = $resp['body']['clone_url'] ?? '';
        $html_url  = $resp['body']['html_url']  ?? '';

        // Set remote with token embedded
        $auth_url = 'https://' . $username . ':' . $token . '@github.com/' . $username . '/' . $repo_name . '.git';
        run_git('remote remove origin', $project_path); // remove if exists
        $r = run_git('remote add origin ' . escapeshellarg($auth_url), $project_path);

        // Stage, commit, push
        $r = run_git('add -A', $project_path);
        $r = run_git('commit -m ' . escapeshellarg($commit_msg), $project_path);
        if ($r['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'git commit failed: ' . $r['output']]); break;
        }
        $r = run_git('push -u origin main', $project_path);
        if ($r['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'git push failed: ' . $r['output']]); break;
        }

        echo json_encode(['success' => true, 'repo_url' => $html_url, 'message' => 'Repo created and pushed!']);
        break;

    // ── commit_push (existing repo) ───────────────────────────────────────────
    case 'commit_push':
        $files      = json_decode($_POST['files'] ?? '[]', true) ?: [];
        $commit_msg = trim($_POST['commit_message'] ?? '');
        $token      = defined('GITHUB_TOKEN')    ? GITHUB_TOKEN    : '';
        $username   = defined('GITHUB_USERNAME') ? GITHUB_USERNAME : '';

        if (!$commit_msg) {
            echo json_encode(['success' => false, 'error' => 'Commit message required']); break;
        }
        if (empty($files)) {
            echo json_encode(['success' => false, 'error' => 'No files selected']); break;
        }
        if (!$token || !$username) {
            echo json_encode(['success' => false, 'error' => 'GitHub token/username not configured']); break;
        }

        // Update remote URL with token (in case it was set without auth)
        $r = run_git('remote get-url origin', $project_path);
        if ($r['code'] === 0) {
            $existing_url = trim($r['output']);
            // If URL doesn't have token, update it
            if (!str_contains($existing_url, $token)) {
                preg_match('/github\.com[\/:](.+?)\/(.+?)(?:\.git)?$/', $existing_url, $m);
                if (isset($m[2])) {
                    $repo_name = $m[2];
                    $auth_url  = 'https://' . $username . ':' . $token . '@github.com/' . $username . '/' . $repo_name . '.git';
                    run_git('remote set-url origin ' . escapeshellarg($auth_url), $project_path);
                }
            }
        }

        // Stage selected files
        foreach ($files as $file) {
            $safe = escapeshellarg(trim($file));
            run_git('add ' . $safe, $project_path);
        }

        // Commit
        $r = run_git('commit -m ' . escapeshellarg($commit_msg), $project_path);
        if ($r['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'git commit failed: ' . $r['output']]); break;
        }

        // Push
        $r = run_git('push', $project_path);
        if ($r['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'git push failed: ' . $r['output']]); break;
        }

        echo json_encode(['success' => true, 'message' => 'Committed and pushed!', 'output' => $r['output']]);
        break;

    // ── pull (existing repo) ─────────────────────────────────────────────────
    case 'pull':
        $token    = defined('GITHUB_TOKEN')    ? GITHUB_TOKEN    : '';
        $username = defined('GITHUB_USERNAME') ? GITHUB_USERNAME : '';

        if (!$token || !$username) {
            echo json_encode(['success' => false, 'error' => 'GitHub token/username not configured']); break;
        }

        // Update remote URL with token (in case it was set without auth)
        $r = run_git('remote get-url origin', $project_path);
        if ($r['code'] === 0) {
            $existing_url = trim($r['output']);
            if (!str_contains($existing_url, $token)) {
                preg_match('/github\.com[\/:](.+?)\/(.+?)(?:\.git)?$/', $existing_url, $m);
                if (isset($m[2])) {
                    $repo_name = $m[2];
                    $auth_url  = 'https://' . $username . ':' . $token . '@github.com/' . $username . '/' . $repo_name . '.git';
                    run_git('remote set-url origin ' . escapeshellarg($auth_url), $project_path);
                }
            }
        }

        $r = run_git('pull', $project_path);
        if ($r['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'git pull failed: ' . $r['output']]); break;
        }

        echo json_encode(['success' => true, 'message' => 'Pulled from origin!', 'output' => $r['output']]);
        break;

    // ── git_reset (remove .git dir, return project to pre-git state) ─────────
    case 'git_reset':
        $git_dir = $project_path . '/.git';
        if (!is_dir($git_dir)) {
            echo json_encode(['success' => true, 'message' => 'No .git directory found — already clean.']); break;
        }
        exec('rm -rf ' . escapeshellarg($git_dir) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            echo json_encode(['success' => false, 'error' => 'Failed to remove .git: ' . implode("\n", $out)]); break;
        }
        echo json_encode(['success' => true, 'message' => 'Git disconnected. Project reset to pre-git state.']);
        break;

    // ── clone (pull existing GitHub repo into project) ────────────────────────
    case 'clone':
        $clone_url = trim($_POST['clone_url'] ?? '');
        $token     = defined('GITHUB_TOKEN')    ? GITHUB_TOKEN    : '';
        $username  = defined('GITHUB_USERNAME') ? GITHUB_USERNAME : '';

        if (!$clone_url) {
            echo json_encode(['success' => false, 'error' => 'No URL provided']); break;
        }
        if (!preg_match('/^https?:\/\/(www\.)?github\.com\//i', $clone_url)) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid https://github.com/... URL']); break;
        }

        // Normalise: strip trailing slash, ensure .git suffix
        $clean_url = rtrim($clone_url, '/');
        if (!str_ends_with($clean_url, '.git')) $clean_url .= '.git';

        // Embed credentials for auth
        $auth_url = $clean_url;
        if ($token && $username) {
            $auth_url = preg_replace('/^https:\/\//', 'https://' . rawurlencode($username) . ':' . rawurlencode($token) . '@', $clean_url);
        }

        // Clone into a temp directory first (avoids conflicts with existing project files)
        $tmp_dir = sys_get_temp_dir() . '/bt_clone_' . uniqid('', true);
        $cmd = 'git clone ' . escapeshellarg($auth_url) . ' ' . escapeshellarg($tmp_dir) . ' 2>&1';
        exec($cmd, $clone_out, $clone_code);

        if ($clone_code !== 0) {
            $err = implode("\n", $clone_out);
            if ($token) $err = str_replace($token, '***', $err);
            echo json_encode(['success' => false, 'error' => 'Clone failed: ' . $err]); break;
        }

        // Copy everything (including .git) into the project dir, overwriting existing files
        exec('cp -a ' . escapeshellarg($tmp_dir) . '/. ' . escapeshellarg($project_path) . '/ 2>&1', $cp_out, $cp_code);
        exec('rm -rf ' . escapeshellarg($tmp_dir));

        if ($cp_code !== 0) {
            echo json_encode(['success' => false, 'error' => 'Failed to copy files: ' . implode("\n", $cp_out)]); break;
        }

        $r           = run_git('branch --show-current', $project_path);
        $branch      = trim($r['output']) ?: 'main';
        $display_url = str_replace('.git', '', preg_replace('/https:\/\/.+@/', 'https://', $clean_url));

        echo json_encode(['success' => true, 'message' => 'Repository cloned successfully!', 'branch' => $branch, 'repo_url' => $display_url]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
