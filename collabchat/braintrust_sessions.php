<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
require_once '/var/www/secure_config/braintrust_config.php';

// Get user info
$stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get sessions
$stmt = $conn->prepare("
    SELECT s.*, 
           (SELECT COUNT(*) FROM braintrust_messages WHERE session_id = s.id) as message_count,
           (SELECT message_text FROM braintrust_messages WHERE session_id = s.id ORDER BY created_at DESC LIMIT 1) as last_message
    FROM braintrust_sessions s
    JOIN braintrust_members m ON s.id = m.session_id
    WHERE m.user_id = ? AND s.is_archived = 0
    ORDER BY s.updated_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BrainTrust - Sessions</title>
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
            --accent-system: #bb9af7;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-darkest);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border-subtle);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--accent-claude), var(--accent-gemini));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .logo-text h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent-claude), var(--accent-gemini));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-text p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: var(--accent-human);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--bg-darkest);
        }

        .user-name {
            font-weight: 600;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-claude), var(--accent-gemini));
            color: var(--bg-darkest);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(212, 165, 116, 0.3);
        }

        .btn-secondary {
            background: var(--bg-elevated);
            color: var(--text-primary);
            border: 1px solid var(--border-subtle);
        }

        .btn-secondary:hover {
            border-color: var(--border-glow);
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .section-title h2 {
            font-size: 20px;
            font-weight: 600;
        }

        .sessions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .session-card {
            background: var(--bg-dark);
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 24px;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .session-card:hover {
            border-color: var(--accent-claude);
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
        }

        .session-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .session-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .session-date {
            font-size: 12px;
            color: var(--text-muted);
        }

        .session-stats {
            display: flex;
            gap: 8px;
        }

        .stat-badge {
            background: var(--bg-elevated);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
        }

        .session-preview {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .session-participants {
            display: flex;
            gap: -8px;
            margin-top: 16px;
        }

        .participant-dot {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            border: 2px solid var(--bg-dark);
            margin-left: -8px;
        }

        .participant-dot:first-child {
            margin-left: 0;
        }

        .participant-dot.human { background: var(--accent-human); color: var(--bg-darkest); }
        .participant-dot.claude { background: var(--accent-claude); color: var(--bg-darkest); }
        .participant-dot.gemini { background: var(--accent-gemini); color: var(--bg-darkest); }

        .empty-state {
            text-align: center;
            padding: 80px 40px;
            background: var(--bg-dark);
            border-radius: 20px;
            border: 2px dashed var(--border-subtle);
        }
        .delete-btn {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s;
    font-size: 16px;
    }

    .delete-btn:hover {
    color: var(--danger);
    background: var(--danger-glow);
    transform: scale(1.1);
    }
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 12px;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }

        .new-session-form {
            background: var(--bg-dark);
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            display: none;
        }

        .new-session-form.active {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-row {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .form-input {
            flex: 1;
            background: var(--bg-panel);
            border: 1px solid var(--border-subtle);
            border-radius: 10px;
            padding: 14px 18px;
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-claude);
        }

        .form-label {
            display: block;
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .collaborator-input {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .collaborator-input .form-group {
            flex: 1;
        }

        .added-collaborators {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .collaborator-tag {
            background: var(--accent-human);
            color: var(--bg-darkest);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .collaborator-tag button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            opacity: 0.7;
        }

        .collaborator-tag button:hover {
            opacity: 1;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo">
            <div class="logo-icon">🧠</div>
            <div class="logo-text">
                <h1>BrainTrust</h1>
                <p>AI Collaboration Hub</p>
            </div>
        </div>
        <div class="user-info">
            <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 2)); ?></div>
            <span class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></span>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
        </div>
    </div>

    <div class="section-title">
        <h2>Your Sessions</h2>
        <button class="btn btn-primary" onclick="toggleNewSession()">➕ New Session</button>
    </div>

    <div class="new-session-form" id="newSessionForm">
        <form action="braintrust_api.php" method="GET">
            <input type="hidden" name="action" value="create_session">
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">Session Name</label>
                <input type="text" name="name" class="form-input" placeholder="e.g., Admissions Project Planning" required>
            </div>

            <div class="collaborator-input">
                <div class="form-group">
                    <label class="form-label">Add Collaborator (optional)</label>
                    <input type="text" id="collaboratorInput" class="form-input" placeholder="Enter username">
                </div>
                <button type="button" class="btn btn-secondary" onclick="addCollaborator()">Add</button>
            </div>

            <div class="added-collaborators" id="collaboratorsList"></div>

            <div style="margin-top: 20px; display: flex; gap: 12px;">
                <button type="submit" class="btn btn-primary">🚀 Create Session</button>
                <button type="button" class="btn btn-secondary" onclick="toggleNewSession()">Cancel</button>
            </div>
        </form>
    </div>

    <?php if (empty($sessions)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">🧠</div>
        <h3>No sessions yet!</h3>
        <p>Create your first collaboration session to start working with Claude and Gemini on your projects.</p>
        <button class="btn btn-primary" onclick="toggleNewSession()">➕ Create First Session</button>
    </div>
    <?php else: ?>
    <div class="sessions-grid">
        <?php foreach ($sessions as $session): ?>
        <a href="braintrust.php?session_id=<?php echo $session['id']; ?>" class="session-card">
            <div class="session-card-header">
                <div>
                    <div class="session-name"><?php echo htmlspecialchars($session['session_name']); ?></div>
                    <div class="session-date"><?php echo date('M j, Y g:ia', strtotime($session['updated_at'])); ?></div>
                    
                </div>
                <div class="session-stats">
                    <span class="stat-badge"><?php echo $session['message_count']; ?> msgs</span>
                </div>
            </div>
            <div class="session-preview">
                <?php echo htmlspecialchars($session['last_message'] ?? 'No messages yet...'); ?>
            </div>
            <div class="session-participants">
                <div class="participant-dot human">You</div>
                <div class="participant-dot claude">CL</div>
                <div class="participant-dot gemini">GE</div>
            </div>
            <button class="delete-btn" onclick="confirmDelete(event, <?php echo $session['id']; ?>, '<?php echo addslashes($session['session_name']); ?>')">🗑️</button>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
    let collaborators = [];

    function toggleNewSession() {
        const form = document.getElementById('newSessionForm');
        form.classList.toggle('active');
    }
    async function confirmDelete(event, sessionId, sessionName) {
    // Prevent the card click from triggering
    event.preventDefault();
    event.stopPropagation();

    const warning = `Are you sure you want to delete "${sessionName}"?\n\nThis will permanently remove all messages, diagrams, and history. This cannot be undone.`;
    
    if (confirm(warning)) {
        try {
            const formData = new FormData();
            formData.append('action', 'delete_session');
            formData.append('session_id', sessionId);

            const response = await fetch('braintrust_api.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (data.success) {
                // Refresh the page to show updated list
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to delete session.'));
            }
            } catch (error) {
                console.error('Delete failed:', error);
            }
        }
    }
    function addCollaborator() {
        const input = document.getElementById('collaboratorInput');
        const username = input.value.trim();
        
        if (username && !collaborators.includes(username)) {
            collaborators.push(username);
            renderCollaborators();
            input.value = '';
        }
    }

    function removeCollaborator(username) {
        collaborators = collaborators.filter(c => c !== username);
        renderCollaborators();
    }

    function renderCollaborators() {
        const container = document.getElementById('collaboratorsList');
        container.innerHTML = collaborators.map(c => `
            <div class="collaborator-tag">
                ${c}
                <button onclick="removeCollaborator('${c}')">&times;</button>
            </div>
        `).join('');
    }

    // Handle enter key in collaborator input
    document.getElementById('collaboratorInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addCollaborator();
        }
    });
</script>

</body>
</html>
