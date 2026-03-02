<?php
/**
 * BrainTrust IDE - Projects Dashboard
 * The new entry point: Projects ARE workspaces
 * One project = One chat session = One workspace
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '/var/www/secure_config/braintrust_config.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_project') {
        $project_name = trim($_POST['project_name'] ?? '');
        
        if (empty($project_name)) {
            $error = "Project name is required.";
        } else {
            // Sanitize folder name
            $folder_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $project_name);
            $folder_name = strtolower($folder_name);
            
            // Check if already exists
            $check = $conn->prepare("SELECT id FROM braintrust_projects WHERE project_path = ? AND user_id = ?");
            $check->bind_param("si", $folder_name, $user_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "A project with that name already exists.";
            } else {
                // Create the project folder
                $projects_root = '/var/www/html/collabchat/projects/';
                $project_path = $projects_root . $folder_name;
                
                if (!is_dir($project_path)) {
                    mkdir($project_path, 0775, true);
                    // Create a starter README
                    $readmeContent = "# {$project_name}\n\n" .
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
                    file_put_contents($project_path . '/README.md', $readmeContent);
                }
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Insert project
                    $stmt = $conn->prepare("INSERT INTO braintrust_projects (project_name, project_path, user_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("ssi", $project_name, $folder_name, $user_id);
                    $stmt->execute();
                    $project_id = $conn->insert_id;
                    $stmt->close();
                    
                // Create linked session automatically
                    $session_name = $project_name . " Workspace";
                    $stmt = $conn->prepare("INSERT INTO braintrust_sessions (owner_id, session_name, project_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("isi", $user_id, $session_name, $project_id);
                    $stmt->execute();
                    $session_id = $conn->insert_id;
                    $stmt->close();
                    
                    // Add user as member/owner of the session
                    $stmt = $conn->prepare("INSERT INTO braintrust_members (session_id, user_id, role, turn_order) VALUES (?, ?, 'owner', 1)");
                    $stmt->bind_param("ii", $session_id, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    $conn->commit();    
                    
                    // Redirect straight to the IDE!
                    header("Location: braintrust.php?session_id=" . $session_id);
                    exit();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Failed to create project: " . $e->getMessage();
                }
            }
            $check->close();
        }
    }
    
    if ($action === 'delete_project') {
        $project_id = intval($_POST['project_id'] ?? 0);
        
        if ($project_id > 0) {
            // Get project path first
            $stmt = $conn->prepare("SELECT project_path FROM braintrust_projects WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $project_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($result) {
                // Delete project folder
                $projects_root = '/var/www/html/collabchat/projects/';
                $folder = $projects_root . $result['project_path'];
                if (is_dir($folder)) {
                    // Recursive delete
                    $it = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS);
                    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                    foreach($files as $file) {
                        if ($file->isDir()) {
                            rmdir($file->getRealPath());
                        } else {
                            unlink($file->getRealPath());
                        }
                    }
                    rmdir($folder);
                }
                
                // Delete from database (session will cascade delete due to FK)
                $stmt = $conn->prepare("DELETE FROM braintrust_projects WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $project_id, $user_id);
                $stmt->execute();
                $stmt->close();
                
                $success = "Project deleted successfully.";
            }
        }
    }

    if ($action === 'invite_user') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $invite_username = trim($_POST['invite_username'] ?? '');

        if ($project_id > 0 && !empty($invite_username)) {
            // 1. Verify owner
            $stmt = $conn->prepare("SELECT id FROM braintrust_projects WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $project_id, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $error = "Permission denied.";
            } else {
                $stmt->close();
                
                // 2. Find user to invite
                $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->bind_param("s", $invite_username);
                $stmt->execute();
                $res = $stmt->get_result();
                
                if ($res->num_rows === 0) {
                    $error = "User '$invite_username' not found.";
                } else {
                    $invite_user_id = $res->fetch_assoc()['id'];
                    $stmt->close();

                    // 3. Get session ID
                    $stmt = $conn->prepare("SELECT id FROM braintrust_sessions WHERE project_id = ?");
                    $stmt->bind_param("i", $project_id);
                    $stmt->execute();
                    $session_id = $stmt->get_result()->fetch_assoc()['id'];
                    $stmt->close();

                    // 4. Add to members if not exists
                    // Get next turn order
                    $stmt = $conn->prepare("SELECT MAX(turn_order) as max_o FROM braintrust_members WHERE session_id = ?");
                    $stmt->bind_param("i", $session_id);
                    $stmt->execute();
                    $next_order = ($stmt->get_result()->fetch_assoc()['max_o'] ?? 0) + 1;
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT IGNORE INTO braintrust_members (session_id, user_id, role, turn_order) VALUES (?, ?, 'collaborator', ?)");
                    $stmt->bind_param("iii", $session_id, $invite_user_id, $next_order);
                    if ($stmt->execute()) {
                         if ($stmt->affected_rows > 0) {
                             $success = "User '$invite_username' invited successfully!";
                         } else {
                             $error = "User is already a member.";
                         }
                    } else {
                        $error = "Failed to invite user.";
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch all projects for this user (Owner OR Member)
// Use GROUP BY p.id to ensure absolutely no duplicates
$stmt = $conn->prepare("
    SELECT 
        p.id,
        p.project_name,
        p.project_path,
        p.created_at,
        p.last_opened,
        p.user_id as owner_id,
        MAX(s.id) as session_id,
        (SELECT COUNT(*) FROM braintrust_messages WHERE session_id = MAX(s.id)) as message_count
    FROM braintrust_projects p
    JOIN braintrust_sessions s ON s.project_id = p.id
    LEFT JOIN braintrust_members m ON m.session_id = s.id
    WHERE p.user_id = ? OR m.user_id = ?
    GROUP BY p.id
    ORDER BY p.last_opened DESC
");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BrainTrust IDE - Projects</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-darkest: #0a0a0f;
            --bg-dark: #12121a;
            --bg-panel: #1a1a25;
            --bg-elevated: #242435;
            --border-subtle: #2a2a3d;
            --border-glow: #4a4a6a;
            --text-primary: #e8e8f0;
            --text-secondary: #9090a8;
            --text-muted: #606078;
            --accent-claude: #d4a574;
            --accent-gemini: #7aa2f7;
            --accent-human: #9ece6a;
            --danger: #f7768e;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-darkest);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 40px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-subtle);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent-claude), var(--accent-gemini));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .logo h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent-claude), var(--accent-gemini));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo p {
            font-size: 14px;
            color: var(--text-muted);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info span {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-claude), var(--accent-gemini));
            color: var(--bg-darkest);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(212, 165, 116, 0.3);
        }

        .btn-secondary {
            background: var(--bg-elevated);
            color: var(--text-primary);
            border: 1px solid var(--border-subtle);
        }

        .btn-secondary:hover {
            border-color: var(--border-glow);
        }

        .btn-danger {
            background: transparent;
            color: var(--danger);
            border: 1px solid var(--danger);
            padding: 8px 16px;
            font-size: 12px;
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 12px;
        }

        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .message.error {
            background: rgba(247, 118, 142, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .message.success {
            background: rgba(158, 206, 106, 0.1);
            border: 1px solid var(--accent-human);
            color: var(--accent-human);
        }

        /* New Project Form */
        .new-project-form {
            background: var(--bg-dark);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .new-project-form h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row input {
            flex: 1;
            padding: 12px 16px;
            background: var(--bg-panel);
            border: 1px solid var(--border-subtle);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
        }

        .form-row input:focus {
            outline: none;
            border-color: var(--accent-gemini);
        }

        .form-row input::placeholder {
            color: var(--text-muted);
        }

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .project-card {
            background: var(--bg-dark);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 25px;
            transition: all 0.2s ease;
            position: relative;
        }

        .project-card:hover {
            border-color: var(--accent-gemini);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .project-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--bg-elevated), var(--bg-panel));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .project-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .project-path {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 15px;
            padding: 6px 10px;
            background: var(--bg-elevated);
            border-radius: 4px;
            display: inline-block;
        }

        .project-meta {
            display: flex;
            gap: 20px;
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .project-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .project-actions {
            display: flex;
            gap: 10px;
            justify-content: space-between;
            align-items: center;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 20px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* Confirmation Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: var(--bg-dark);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 30px;
            max-width: 400px;
            text-align: center;
        }

        .modal-box h3 {
            margin-bottom: 15px;
            color: var(--danger);
        }
        
        .modal-box h3.invite-title {
            color: var(--accent-gemini);
        }

        .modal-box p {
            color: var(--text-secondary);
            margin-bottom: 25px;
            font-size: 14px;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <div class="logo-icon">🧠</div>
            <div>
                <h1>BrainTrust IDE</h1>
                <p>Multi-AI Collaboration IDE</p>
            </div>
        </div>
        <div class="user-info">
            <span>👤 <?php echo htmlspecialchars($username); ?></span>
            <a href="../USERGUIDE.html" class="btn btn-secondary btn-small" target="_blank">📖 User Guide</a>
            <a href="../logout.php" class="btn btn-secondary btn-small">🚪 Logout</a>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($error): ?>
        <div class="message error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="message success">✅ <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- New Project Form -->
    <div class="new-project-form">
        <h2>➕ Create New Project</h2>
        <form method="POST" class="form-row">
            <input type="hidden" name="action" value="create_project">
            <input type="text" name="project_name" placeholder="Enter project name (e.g., my-awesome-app)" required>
            <button type="submit" class="btn btn-primary">🚀 Create & Open</button>
        </form>
    </div>

    <!-- Projects Grid -->
    <?php if (empty($projects)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📁</div>
            <h3>No Projects Yet</h3>
            <p>Create your first project above to get started!</p>
        </div>
    <?php else: ?>
        <div class="projects-grid">
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-icon">📁</div>
                    <div class="project-name"><?php echo htmlspecialchars($project['project_name']); ?></div>
                    <div class="project-path">~/projects/<?php echo htmlspecialchars($project['project_path']); ?></div>
                    <div class="project-meta">
                        <span>💬 <?php echo $project['message_count'] ?? 0; ?> messages</span>
                        <span>📅 <?php echo date('M j, Y', strtotime($project['created_at'])); ?></span>
                        <?php if($project['owner_id'] != $user_id): ?>
                            <span style="color: var(--accent-gemini);">👤 Shared with you</span>
                        <?php endif; ?>
                    </div>
                    <div class="project-actions">
                        <?php if ($project['session_id']): ?>
                            <a href="braintrust.php?session_id=<?php echo $project['session_id']; ?>" class="btn btn-primary">
                                🚀 Open
                            </a>
                        <?php else: ?>
                            <!-- Create session on the fly if somehow missing -->
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="create_session">
                                <input type="hidden" name="project_id" value="<?php echo $project['id']; ?>">
                                <button type="submit" class="btn btn-primary">🔗 Initialize</button>
                            </form>
                        <?php endif; ?>
                        
                        <div style="margin-left: auto; display: flex; gap: 8px;">
                            <?php if ($project['owner_id'] == $user_id): ?>
                                <button class="btn btn-secondary btn-small" onclick="showInviteModal(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>')">
                                    👋 Invite
                                </button>
                                <button class="btn btn-danger" onclick="confirmDelete(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>')">
                                    🗑️
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <h3>⚠️ Delete Project?</h3>
        <p>This will permanently delete "<span id="deleteProjectName"></span>" including all files and chat history. This cannot be undone!</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete_project">
            <input type="hidden" name="project_id" id="deleteProjectId">
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">🗑️ Delete Forever</button>
            </div>
        </form>
    </div>
</div>

<!-- Invite Modal -->
<div class="modal-overlay" id="inviteModal">
    <div class="modal-box">
        <h3 class="invite-title">👋 Invite Collaborator</h3>
        <p>Invite a user to join "<span id="inviteProjectName"></span>".</p>
        <form method="POST" id="inviteForm">
            <input type="hidden" name="action" value="invite_user">
            <input type="hidden" name="project_id" id="inviteProjectId">
            
            <div style="margin-bottom: 20px; text-align: left;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">Username to invite:</label>
                <input type="text" name="invite_username" required style="width: 100%; padding: 10px; background: var(--bg-panel); border: 1px solid var(--border-subtle); color: white; border-radius: 6px;">
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeInviteModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">✉️ Send Invite</button>
            </div>
        </form>
    </div>
</div>

<script>
    function confirmDelete(projectId, projectName) {
        document.getElementById('deleteProjectId').value = projectId;
        document.getElementById('deleteProjectName').textContent = projectName;
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    // Invite Functions
    function showInviteModal(projectId, projectName) {
        document.getElementById('inviteProjectId').value = projectId;
        document.getElementById('inviteProjectName').textContent = projectName;
        document.getElementById('inviteModal').classList.add('active');
    }
    
    function closeInviteModal() {
        document.getElementById('inviteModal').classList.remove('active');
    }

    // Close modal on outside click
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeDeleteModal();
        }
    });
    
    document.getElementById('inviteModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeInviteModal();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDeleteModal();
            closeInviteModal();
        }
    });
</script>

</body>
</html>