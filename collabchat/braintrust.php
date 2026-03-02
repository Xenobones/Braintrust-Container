<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '/var/www/secure_config/braintrust_config.php';

$user_id = $_SESSION['user_id'];
$session_id = $_GET['session_id'] ?? null;

// Must have a session ID - redirect to projects page if not
if (!$session_id) {
    header("Location: braintrust_projects.php");
    exit();
}

// Fetch session AND linked project info
// Validate access via MEMBERS table, not just OWNER column
$stmt = $conn->prepare("
    SELECT 
        s.id as session_id,
        s.session_name,
        s.project_id,
        p.project_name,
        p.project_path
    FROM braintrust_sessions s
    JOIN braintrust_members m ON m.session_id = s.id
    LEFT JOIN braintrust_projects p ON p.id = s.project_id
    WHERE s.id = ? AND m.user_id = ?
");
$stmt->bind_param("ii", $session_id, $user_id);
$stmt->execute();
$session_data = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

// Invalid session - redirect to projects
if (!$session_data) {
    header("Location: braintrust_projects.php");
    exit();
}

$project_name = $session_data['project_name'] ?? 'Unknown Project';
$project_path = $session_data['project_path'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> - BrainTrust IDE</title>
    <link rel="stylesheet" href="braintrust_style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Outfit:wght@300;400;600;700&family=VT323&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/mermaid/dist/mermaid.min.js"></script>
    <!-- xterm.js - real terminal emulator -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>
    <!-- Monaco Editor CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs/loader.min.js"></script>
    <!-- Prism.js for Chat Highlighting -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup-templating.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>

</head>
<body>

<div class="app-container">
    <!-- Top Bar -->
    <div class="top-bar">
        <!-- Left group: logo + project + panel layout -->
        <div style="display:flex;align-items:center;gap:40px;">
            <div class="logo">
                <div class="logo-icon">🧠</div>
                <h1>BrainTrust IDE</h1>
            </div>

            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:15px;font-weight:600;font-family:'Outfit',sans-serif;color:var(--accent-gemini);">📁 Project:</span>
                <span style="font-size:15px;font-weight:600;font-family:'Outfit',sans-serif;color:var(--accent-gemini);"><?php echo htmlspecialchars($project_name); ?></span>
            </div>

            <div style="display:flex;align-items:center;gap:6px;">
                <button class="btn btn-secondary btn-small" onclick="cycleLayout()" id="layoutBtn" title="Cycle panel layout">📁💬📝</button>
                <span style="font-size:15px;font-weight:600;font-family:'Outfit',sans-serif;color:var(--accent-gemini);">Panel Layout</span>
            </div>
        </div>

        <div class="top-bar-actions">
            <button class="btn btn-secondary btn-small" id="terminalToggleBtn" onclick="toggleTerminalPanel()">🖥️ Terminal</button>
            <button class="btn btn-secondary btn-small" id="dbBtn" onclick="showDatabaseModal()">🗄️ Database</button>
            <button class="btn btn-secondary btn-small" id="whiteboardBtn">📊 Whiteboard</button>
            <button class="btn btn-secondary btn-small" id="logsBtn" onclick="showLogsModal()">📋 Logs</button>
            <button class="btn btn-secondary btn-small" id="githubBtn" onclick="openGitHubModal()">🐙 GitHub</button>
            <button class="btn btn-secondary btn-small" id="bookmarksBtn" onclick="openBookmarksModal()">🔖 Bookmarks</button>
            <button class="btn btn-secondary btn-small" id="exportBtn" onclick="exportChat()">📤 Export</button>
            <a href="braintrust_projects.php" class="btn btn-secondary btn-small" id="projectsBtn">📋 Projects</a>
            <a href="../logout.php" class="btn btn-secondary btn-small" id="logoutBtn">🚪</a>
        </div>
    </div>

    <!-- Main Content - 3 Resizable Panels -->
    <div class="main-content">
        <!-- Left Panel - Files -->
        <div class="left-panel" id="leftPanel">
        <div class="panel-header">
            <div class="panel-header-actions">
                <button class="btn btn-secondary btn-small" onclick="showServerBrowser()">📁 Explorer</button>
                <button onclick="createNewFile()" title="New File">📄+</button>
                <button onclick="createNewFolder()" title="New Folder">📁+</button>
                <button onclick="refreshFileTree()" title="Refresh">🔄</button>
            </div>
        </div>
        <div class="file-tree" id="fileTree">
            <div class="tree-empty">Loading files...</div>
        </div>
    </div>

    <!-- Resize Handle - Left/Center -->
    <div class="resize-handle" id="resizeLeft" data-resize="left"></div>

    <!-- Center Panel - Chat -->
    <div class="chat-panel" id="chatPanel">
        <div class="chat-messages" id="chatMessages">
            <div class="message">
                <div class="message-avatar system">SYS</div>
                <div class="message-content">
                    <div class="message-header">
                        <span class="message-sender system">System</span>
                    </div>
                    <div class="message-text">
                        Welcome to <strong><?php echo htmlspecialchars($project_name); ?></strong>! 🧠💻<br><br>
                        📁 Your project files are loaded in the Explorer<br>
                        💬 Chat with Claude & Gemini about your code<br>
                        📝 Click files to edit them<br><br>
                        <em>Turn order: You → Claude → Gemini → repeat</em>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-input-area">
            <div class="turn-indicator">
                <div class="turn-dot human" id="turnDot"></div>
                <span id="turnText">Your turn to speak</span>
                <span style="margin-left: auto; font-size: 10px; color: var(--text-muted);" id="currentFileIndicator"></span>
            </div>
            <div class="chat-input-row">
                <input type="text" class="chat-input" id="messageInput" placeholder="Ask about your code or collaborate with the AIs..." />
                <button class="btn btn-primary" id="sendBtn" onclick="sendMessage()">Send ➤</button>
            </div>
            <div class="chat-actions">
                <button class="btn btn-secondary btn-small" id="collabBtn" onclick="startAICollab()">🤝 AI Collab</button>
                <button class="btn btn-danger btn-small" onclick="shutUp()" id="shutupBtn" disabled>🛑 SHUT UP</button>
                <button class="btn btn-secondary btn-small" id="uploadBtn" onclick="uploadFileToProject()">📁 Upload File</button>
                <button class="btn btn-secondary btn-small" id="canvasBtn" onclick="openCanvasModal()" title="Paste a screenshot and send it to the AIs">🖼️ Canvas</button>
                <input type="file" id="fileUploadInput" multiple style="display:none">
<button class="btn btn-secondary btn-small ai-toggle-btn" style="margin-left: auto;" id="claudeToggle" onclick="toggleAI('claude')" oncontextmenu="showModelPicker(event, 'claude')" title="Left-click: toggle | Right-click: change model">Claude<span class="model-label" id="claudeModelLabel">Sonnet 4</span></button>
                <button class="btn btn-secondary btn-small ai-toggle-btn" id="geminiToggle" onclick="toggleAI('gemini')" oncontextmenu="showModelPicker(event, 'gemini')" title="Left-click: toggle | Right-click: change model">Gemini<span class="model-label" id="geminiModelLabel">2.5 Flash</span></button>
                <select id="floorDropdown" onchange="setFloor(this.value)" 
    style="background:#2d2d2d; color:#fff; border:1px solid #555; border-radius:4px; padding:3px 6px; font-size:12px; cursor:pointer;">
    <option value="none">👑 Floor: Nobody</option>
    <option value="claude">👑 Claude</option>
    <option value="gemini">👑 Gemini</option>
</select>
            </div>
        </div>
    </div>

    <!-- Resize Handle - Center/Right -->
    <div class="resize-handle" id="resizeRight" data-resize="right"></div>

    <!-- Right Panel - Editor / Terminal (swappable) -->
    <div class="right-panel" id="rightPanel">

        <!-- Editor View (default) -->
        <div id="editorView" style="display:flex;flex-direction:column;flex:1;overflow:hidden;min-height:0;">
            <div class="editor-header">
                <div class="editor-file-name" id="editorFileName">
                    <span style="color: var(--text-muted);">No file open</span>
                </div>
                <div class="editor-actions">
                    <button class="btn btn-secondary btn-small" onclick="saveFile()" id="saveBtn" disabled>💾 Save</button>
                    <button class="btn btn-secondary btn-small" onclick="closeFile()" id="closeFileBtn" style="display:none;">✕ Close</button>
                    <button class="btn btn-secondary btn-small" onclick="showSnapshotModal()" id="snapshotBtn" style="display:none;">🕐 History</button>
                    <button class="btn btn-secondary btn-small" onclick="expandEditor()">⛶ Expand</button>
                </div>
            </div>
            <div class="editor-container" id="editorContainer">
                <div class="editor-empty" id="editorEmpty">
                    <div class="editor-empty-icon">📝</div>
                    <span>Select a file to edit</span>
                </div>
                <div id="monacoEditor" style="display: none;"></div>
            </div>
            <div class="editor-status">
                <span id="editorLanguage">-</span>
                <span id="editorPosition">Ln 1, Col 1</span>
            </div>
        </div>

        <!-- Terminal View (swapped in via toggleTerminalPanel) -->
        <div id="terminalPanel" style="display:none;flex-direction:column;flex:1;overflow:hidden;min-height:0;">
            <div style="background:#1a1a1a;border-bottom:1px solid #333;padding:8px 12px;flex-shrink:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-family:'JetBrains Mono',monospace;font-size:13px;color:#00ff00;">🖥️ Terminal</span>
                <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#666;">— <?php echo htmlspecialchars($project_name); ?></span>
                <select id="quickCommands" style="margin-left:8px;padding:4px 8px;background:#2a2a2a;color:#0f0;border:1px solid #444;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:11px;flex:1;max-width:320px;">
                    <option value="">⚡ Quick Commands...</option>
                    <option value="sudo systemctl restart apache2">🔄 Restart Apache</option>
                    <option value="tail -50 /var/log/apache2/error.log">📋 Apache Errors (50)</option>
                    <option value="tail -100 /var/log/apache2/error.log">📋 Apache Errors (100)</option>
                    <option value="sudo systemctl status apache2">ℹ️ Apache Status</option>
                    <option value="df -h">💾 Disk Usage</option>
                    <option value="free -h">🧠 Memory Usage</option>
                    <option value="docker ps">🐳 Docker Containers</option>
                    <option value="docker images">🐳 Docker Images</option>
                    <option value="sudo netstat -tulpn | grep LISTEN">🌐 Listening Ports</option>
                    <option value="php -v">🐘 PHP Version</option>
                    <option value="mysql --version">🗄️ MySQL Version</option>
                    <option value="tail -20 /var/log/syslog">📋 System Log (20)</option>
                    <option value="sudo service --status-all 2>&1 | head -40">📊 Services Status</option>
                    <option value="uptime">⏱️ Uptime</option>
                    <option value="whoami">👤 Current User</option>
                    <option value="pwd">📍 Working Directory</option>
                    <option value="sudo apt update">📦 Update Packages</option>
                    <option value="sudo apt upgrade -y">⬆️ Upgrade Packages</option>
                    <option value="sudo apt autoremove -y">🧹 Autoremove</option>
                    <option value="sudo apt list --upgradable 2>/dev/null">📋 Upgradable Packages</option>
                    <option value="docker system prune -f">🐳 Docker Prune</option>
                    <option value="sudo journalctl -xe --no-pager | tail -50">📋 System Journal</option>
                    <option value="ls -lah">📁 List Files</option>
                    <option value="du -sh *">📊 Folder Sizes</option>
                    <option value="ps aux | grep apache2">🔍 Apache Processes</option>
                    <option value="ps aux | grep mysql">🔍 MySQL Processes</option>
                    <option value="sudo systemctl restart mysql">🔄 Restart MySQL</option>
                    <option value="git status">📦 Git Status</option>
                    <option value="git log --oneline -10">📦 Git Log (10)</option>
                    <option value="git pull">📥 Git Pull</option>
                </select>
                <button class="btn btn-primary btn-small" onclick="runQuickCommand()" style="background:#0f0;color:#000;font-weight:600;font-size:11px;">▶ Run</button>
                <button class="btn btn-secondary btn-small" onclick="clearTerminal()" style="font-size:11px;">Clear</button>
            </div>
            <div id="xtermContainer" style="flex:1;background:#0d0d0d;padding:4px;overflow:hidden;"></div>
        </div>

    </div>
    </div><!-- /main-content -->
</div><!-- /app-container -->

<!-- New File Modal -->
<div class="modal" id="newFileModal">
    <div class="modal-content small">
        <div class="modal-header">
            <h2>📄 Create New File</h2>
            <button class="btn btn-secondary btn-small" onclick="closeModal('newFileModal')">✕</button>
        </div>
        <div class="modal-body padded">
            <div class="form-group">
                <label>File Name (include extension)</label>
                <input type="text" id="newFileName" placeholder="example.php" />
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('newFileModal')">Cancel</button>
                <button class="btn btn-primary" onclick="createFile()">Create File</button>
            </div>
        </div>
    </div>
</div>

<!-- New Folder Modal -->
<div class="modal" id="newFolderModal">
    <div class="modal-content small">
        <div class="modal-header">
            <h2>📁 Create New Folder</h2>
            <button class="btn btn-secondary btn-small" onclick="closeModal('newFolderModal')">✕</button>
        </div>
        <div class="modal-body padded">
            <div class="form-group">
                <label>Folder Name</label>
                <input type="text" id="newFolderName" placeholder="components" />
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('newFolderModal')">Cancel</button>
                <button class="btn btn-primary" onclick="createFolder()">Create Folder</button>
            </div>
        </div>
    </div>
</div>

<!-- Expanded Editor Modal -->
<div class="modal" id="editorModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalEditorFileName">📝 Editor</h2>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-primary btn-small" onclick="saveFileFromModal()">💾 Save</button>
                <button class="btn btn-secondary btn-small" onclick="closeEditorModal()">✕ Close</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="modalMonacoEditor"></div>
        </div>
    </div>
</div>

<!-- Whiteboard Modal -->
<div class="modal" id="whiteboardModal">
    <div class="modal-content">
        <div class="modal-header">
    <h2>📊 Whiteboard</h2>
    <div style="display: flex; gap: 8px;">
        <button class="btn btn-secondary btn-small" onclick="generateProjectMap()">🗺️ Generate Map</button>
        <button class="btn btn-danger btn-small" id="clearWhiteboardBtn">🗑️ Clear</button>
        <button class="btn btn-primary btn-small" id="saveWhiteboardBtn">💾 Save</button>
        <button class="btn btn-secondary btn-small" id="printWhiteboardBtn">🖨️ Print</button>
        <button class="btn btn-secondary btn-small" onclick="closeModal('whiteboardModal')">✕ Close</button>
    </div>
</div>
        <div class="modal-body">
            <div id="whiteboardContent" class="whiteboard-display">
                <div class="whiteboard-empty">No diagrams generated yet.</div>
            </div>
        </div>
    </div>
</div>


<!-- Diff Modal -->
<div class="modal" id="diffModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="diffModalTitle">⚖️ Review Changes</h2>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-primary btn-small" id="applyDiffBtn">Apply Changes</button>
                <button class="btn btn-secondary btn-small" onclick="closeModal('diffModal')">✕ Close</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="monacoDiffEditor" style="height: 100%; width: 100%;"></div>
        </div>
    </div>
</div>
<!-- Database Modal -->
<div class="modal" id="databaseModal">
    <div class="modal-content" style="width: 90%; max-width: 1400px; height: 85%;">
        <div class="modal-header">
            <h2>🗄️ Database Query Runner</h2>
            <div style="display: flex; gap: 8px; align-items: center;">
                <select id="dbSelector" style="padding: 8px; background: var(--bg-elevated); color: var(--text-primary); border: 1px solid var(--border-subtle); border-radius: 4px;">
                    <option value="braintrust">BrainTrust DB</option>
                </select>
                <button class="btn btn-primary btn-small" onclick="executeQuery()">▶️ Execute</button>
                <button class="btn btn-secondary btn-small" onclick="clearQueryResults()">🗑️ Clear</button>
                <button class="btn btn-secondary btn-small" onclick="closeModal('databaseModal')">✕ Close</button>
            </div>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 10px; padding: 1rem;">
            <!-- Query Input -->
            <div style="flex: 0 0 180px; display: flex; flex-direction: column;">
                <label style="margin-bottom: 6px; color: var(--text-secondary); font-size: 12px;">SQL Query:</label>
                <textarea id="sqlQuery" 
            style="flex: 1; font-family: 'JetBrains Mono', monospace; padding: 12px; 
                background: #0d0d0d; color: #00ff00; border: 1px solid var(--border-subtle);
                border-radius: 4px; resize: vertical; font-size: 14px;"
            placeholder="SELECT * FROM braintrust_sessions LIMIT 10;&#10;&#10;-- ⚠️ Dangerous queries (CREATE, DROP, DELETE, etc.) will ask for confirmation"></textarea>
            </div>
            
            <!-- Results Area -->
            <div style="flex: 1; display: flex; flex-direction: column; min-height: 0;">
                <label style="margin-bottom: 6px; color: var(--text-secondary); font-size: 12px;">Results:</label>
                <div style="flex: 1; overflow: auto; background: #0d0d0d; padding: 12px; 
                            border: 1px solid var(--border-subtle); border-radius: 4px;">
                    <div id="queryResults" style="color: #00ff00; font-family: 'JetBrains Mono', monospace; font-size: 13px; line-height: 1.6;">
                        <div style="color: #666;">⚓ Enter a query and click Execute to see results...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Context Menu -->
<div class="context-menu" id="contextMenu">
    <div class="context-menu-item" onclick="renameItem()">✏️ Rename</div>
    <div class="context-menu-item" onclick="deleteItem()">🗑️ Delete</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="copyPath()">📋 Copy Path</div>
    <div class="context-menu-divider"></div>
    <div class="context-menu-item" onclick="selectAllFiles()">☑️ Select All Files</div>
    <div class="context-menu-item" id="ctxDeleteSelected" style="display:none;" onclick="deleteSelected()">🗑️ Delete Selected (0)</div>
</div>

<!-- Model Picker Context Menu (populated dynamically by JS) -->
<div class="context-menu" id="modelPickerMenu"></div>

<!-- Pass PHP variables to JavaScript BEFORE loading the logic file -->
<script>
    const sessionId = <?php echo $session_id; ?>;
    const userId = <?php echo $user_id; ?>;
    // Project is now pre-loaded from session - no more dropdown needed!
    const currentProject = '<?php echo addslashes($project_path); ?>';
    const projectName = '<?php echo addslashes($project_name); ?>';
</script>
<script src="braintrust_logic.js"></script>

<!-- Server File Browser Modal -->
<div class="modal" id="serverBrowserModal">
    <div class="modal-content" style="width: 90%; height: 85%;">
        <div class="modal-header">
    <h2>🗂️ Server File Browser</h2>
    <div style="display: flex; gap: 8px; align-items: center;">
        <button class="btn btn-secondary btn-small" onclick="navigateServerToParent()">⬆️ Parent</button>
        <button class="btn btn-secondary btn-small" onclick="navigateServerToHome()">🏠 Home</button>
        <button class="btn btn-primary btn-small" id="saveServerFileBtn" disabled>💾 Save</button>
        <button class="btn btn-secondary btn-small" onclick="closeModal('serverBrowserModal')">✕ Close</button>
    </div>
</div>
<div class="modal-body" style="display: flex; flex-direction: column; padding: 0; height: calc(100% - 60px);">
    <!-- Current Path Display -->
    <div style="padding: 12px; background: var(--bg-elevated); border-bottom: 1px solid var(--border-subtle); font-family: 'JetBrains Mono', monospace; font-size: 13px;">
        📍 <span id="serverCurrentPath">/var/www/html/braintrust-IDE-3/collabchat</span>
    </div>
    
    <!-- Main Content: Tree + Editor -->
    <div style="display: flex; flex: 1; min-height: 0;">
        <!-- Left Panel - Directory Tree -->
        <div style="width: 300px; border-right: 1px solid var(--border-subtle); overflow-y: auto; padding: 1rem; background: var(--bg-primary);">
            <div id="serverFileTree">
                <div style="color: var(--text-muted);">Loading...</div>
            </div>
        </div>
        
        <!-- Right Panel - Monaco Editor -->
        <div style="flex: 1; display: flex; flex-direction: column;">
            <div style="padding: 8px 16px; background: var(--bg-elevated); border-bottom: 1px solid var(--border-subtle); font-family: 'JetBrains Mono', monospace; font-size: 13px;">
                <span id="serverFileName" style="color: var(--text-muted);">No file selected</span>
            </div>
            <div id="serverMonacoEditor" style="flex: 1;"></div>
        </div>
    </div>
</div>
</div>
</div> <!-- closes serverBrowserModal -->

<!-- Execution Logs Modal -->
<div class="modal" id="logsModal">
    <div class="modal-content" style="height: 60%; width: 70%; max-width: 800px;">
        <div class="modal-header">
            <h2>📋 Execution Logs</h2>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-danger btn-small" onclick="clearLogs()">🗑️ Clear</button>
                <button class="btn btn-secondary btn-small" onclick="closeModal('logsModal'); document.getElementById('logsBtn').classList.remove('has-logs');">✕ Close</button>
            </div>
        </div>
        <div class="modal-body" style="padding: 0; background: #0d0d0d;">
            <div id="executionLog" class="execution-log"></div>
        </div>
    </div>
</div>

<!-- CLI Monitor Modal -->
<div class="modal" id="cliMonitorModal">
    <div class="modal-content" style="height: 65%; width: 75%; max-width: 900px;">
        <div class="modal-header">
            <h2 id="cliMonitorTitle">CLI Monitor</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <span id="cliMonitorStatus" class="cli-monitor-status idle">
                    <span class="cli-monitor-dot"></span> Idle
                </span>
                <button class="btn btn-secondary btn-small" onclick="clearCliMonitor()">Clear</button>
                <button class="btn btn-secondary btn-small" onclick="closeCliMonitor()">Close</button>
            </div>
        </div>
        <div class="modal-body" style="padding: 0; background: #0d0d0d;">
            <div id="cliMonitorLog" class="cli-monitor-log"></div>
        </div>
    </div>
</div>

<!-- AI Options Modal -->
<div class="modal" id="aiOptionsModal">
    <div class="modal-content" style="width: 520px; max-width: 95vw;">
        <div class="modal-header">
            <h2 id="aiOptionsTitle">⚙️ AI Options</h2>
            <button class="btn btn-secondary btn-small" onclick="closeModal('aiOptionsModal')">Close</button>
        </div>
        <div class="modal-body" style="padding: 20px;">
            <p style="color: var(--text-muted); margin: 0 0 18px 0; font-size: 13px;">Control what each AI is allowed to do in this session. Permissions are enforced server-side and persist per project.</p>
            <div id="aiOptionsContent"></div>
        </div>
    </div>
</div>

<!-- Snapshot History Modal -->
<div class="modal" id="snapshotModal">
    <div class="modal-content" style="width: 70%; max-width: 900px; height: 70%;">
        <div class="modal-header">
            <h2 id="snapshotModalTitle">File History</h2>
            <button class="btn btn-secondary btn-small" onclick="closeModal('snapshotModal')">Close</button>
        </div>
        <div class="modal-body" style="display: flex; gap: 0; padding: 0; overflow: hidden;">
            <div id="snapshotList" style="width: 240px; overflow-y: auto; border-right: 1px solid var(--border-subtle); padding: 12px; background: var(--bg-panel);">
                <div style="color: var(--text-muted);">No snapshots found.</div>
            </div>
            <div style="flex: 1; display: flex; flex-direction: column;">
                <div style="padding: 8px 16px; background: var(--bg-elevated); border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
                    <span id="snapshotPreviewLabel" style="font-size: 12px; color: var(--text-muted);">Select a snapshot to preview</span>
                    <button class="btn btn-primary btn-small" id="restoreSnapshotBtn" style="display:none;" onclick="restoreSelectedSnapshot()">Restore This Version</button>
                </div>
                <div id="snapshotPreviewEditor" style="flex: 1;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal" id="exportModal">
    <div class="modal-content small">
        <div class="modal-header">
            <h2>Export Chat</h2>
            <button class="btn btn-secondary btn-small" onclick="closeModal('exportModal')">Close</button>
        </div>
        <div class="modal-body padded">
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-secondary);">Filter by Sender</label>
                <select id="exportSender" style="width: 100%; padding: 8px; background: var(--bg-panel); border: 1px solid var(--border-subtle); color: white; border-radius: 6px;">
                    <option value="all">All Messages</option>
                    <option value="human">Human Only</option>
                    <option value="claude">Claude Only</option>
                    <option value="gemini">Gemini Only</option>
                </select>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-secondary);">Date Range (optional)</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="date" id="exportDateFrom" style="flex:1; padding: 8px; background: var(--bg-panel); border: 1px solid var(--border-subtle); color: white; border-radius: 6px;" />
                    <span style="color: var(--text-muted);">to</span>
                    <input type="date" id="exportDateTo" style="flex:1; padding: 8px; background: var(--bg-panel); border: 1px solid var(--border-subtle); color: white; border-radius: 6px;" />
                </div>
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--text-secondary);">
                    <input type="checkbox" id="exportExcludeSummaries" style="width: auto;" />
                    Exclude auto-summaries
                </label>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="closeModal('exportModal')">Cancel</button>
                <button class="btn btn-primary" onclick="doExport()">Download Markdown</button>
            </div>
        </div>
    </div>
</div>

<!-- GitHub Modal -->
<div class="modal" id="githubModal">
    <div class="modal-content" style="width:65%;max-width:860px;max-height:88vh;margin:4% auto;display:flex;flex-direction:column;">
        <div class="modal-header">
            <h2>🐙 GitHub</h2>
            <div style="display:flex;gap:8px;align-items:center;">
                <span id="githubStatusBadge" style="font-size:12px;color:var(--text-muted);"></span>
                <button class="btn btn-secondary btn-small" onclick="closeModal('githubModal')">✕ Close</button>
            </div>
        </div>
        <div class="modal-body padded" id="githubModalBody" style="overflow-y:auto;flex:1;">
            <p style="color:var(--text-muted);text-align:center;padding:32px;">Loading…</p>
        </div>
    </div>
</div>

<!-- Bookmarks Modal -->
<div class="modal" id="bookmarksModal">
    <div class="modal-content" style="width: 60%; max-width: 800px; max-height: 80vh; margin: 5% auto; display: flex; flex-direction: column;">
        <div class="modal-header">
            <h2>🔖 Bookmarks</h2>
            <div style="display:flex; gap:8px; align-items:center;">
                <span id="bookmarkCount" style="font-size:12px; color:var(--text-muted);"></span>
                <button class="btn btn-secondary btn-small" onclick="closeModal('bookmarksModal')">✕ Close</button>
            </div>
        </div>
        <div class="modal-body padded" id="bookmarksList" style="overflow-y:auto; flex:1;"></div>
    </div>
</div>

<!-- Canvas Modal -->
<div class="modal" id="canvasModal">
    <div class="modal-content" style="width: 82%; max-width: 1200px; height: auto; max-height: 92vh; margin: 3% auto;">
        <div class="modal-header">
            <h2>🖼️ Image Canvas</h2>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span id="canvasStatusText" style="font-size: 12px; color: var(--accent-gemini);">Paste a screenshot · or click to draw on blank canvas</span>
                <button class="btn btn-danger btn-small" onclick="clearCanvas()" id="clearCanvasBtn" style="display:none;">🗑️ Clear</button>
                <button class="btn btn-primary btn-small" onclick="saveCanvasToProject()" id="attachImageBtn" disabled>💾 Save to Project</button>
                <button class="btn btn-secondary btn-small" onclick="closeModal('canvasModal')">✕ Close</button>
            </div>
        </div>
        <!-- Drawing toolbar -->
        <div id="canvasToolbar">
            <div class="canvas-tool-group">
                <button class="canvas-tool-btn active" id="tool-pen" onclick="setCanvasTool('pen')" title="Freehand Pen (P)">✏️</button>
                <button class="canvas-tool-btn" id="tool-rect" onclick="setCanvasTool('rect')" title="Rectangle (R)">⬜</button>
                <button class="canvas-tool-btn" id="tool-circle" onclick="setCanvasTool('circle')" title="Circle / Ellipse (C)">⭕</button>
                <button class="canvas-tool-btn" id="tool-text" onclick="setCanvasTool('text')" title="Text Overlay (T)" style="font-weight:700; font-size:15px;">T</button>
            </div>
            <div class="canvas-tool-divider"></div>
            <div class="canvas-tool-group">
                <label class="canvas-tool-label">Color</label>
                <input type="color" id="canvasColor" value="#ff3b3b" title="Stroke / Fill Color" style="width:36px; height:28px; padding:2px; border:1px solid var(--border-subtle); border-radius:4px; background:none; cursor:pointer;">
            </div>
            <div class="canvas-tool-divider"></div>
            <div class="canvas-tool-group">
                <label class="canvas-tool-label">Width</label>
                <select id="canvasLineWidth" style="background:var(--bg-elevated); color:var(--text-primary); border:1px solid var(--border-subtle); border-radius:4px; padding:4px 6px; font-size:12px; cursor:pointer;">
                    <option value="2">Thin</option>
                    <option value="4" selected>Medium</option>
                    <option value="8">Thick</option>
                </select>
            </div>
            <div class="canvas-tool-divider"></div>
            <div class="canvas-tool-group">
                <label class="canvas-tool-label">Font</label>
                <select id="canvasFontSize" style="background:var(--bg-elevated); color:var(--text-primary); border:1px solid var(--border-subtle); border-radius:4px; padding:4px 6px; font-size:12px; cursor:pointer;">
                    <option value="16">Small</option>
                    <option value="24" selected>Medium</option>
                    <option value="36">Large</option>
                    <option value="48">X-Large</option>
                </select>
            </div>
        </div>
        <div class="modal-body padded" style="overflow: auto;">
            <div id="canvasPasteArea" tabindex="0" style="position: relative;">
                <div id="canvasPlaceholder">
                    <div style="font-size: 52px; margin-bottom: 16px; line-height:1;">📋</div>
                    <div style="font-size: 17px; color: var(--text-primary); margin-bottom: 8px;">Paste a screenshot or start drawing</div>
                    <div style="font-size: 13px; color: var(--text-muted);">Ctrl+V to paste · Click anywhere to draw on blank canvas</div>
                </div>
                <canvas id="screenshotCanvas" style="display: none; max-width: 100%; border-radius: 6px; border: 1px solid var(--border-subtle); cursor: crosshair;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="modal" id="imageViewerModal" onclick="if(event.target===this)closeModal('imageViewerModal')">
    <div style="background:var(--bg-dark);border:1px solid var(--border-subtle);border-radius:12px;overflow:hidden;max-width:90vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.5);">
        <div class="modal-header">
            <h2 id="imageViewerTitle" style="font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px;">🖼️</h2>
            <button class="btn btn-secondary btn-small" onclick="closeModal('imageViewerModal')">✕</button>
        </div>
        <div style="overflow:auto;display:flex;align-items:center;justify-content:center;padding:16px;">
            <img id="imageViewerImg" src="" alt="" style="max-width:85vw;max-height:calc(90vh - 80px);object-fit:contain;border-radius:4px;display:block;">
        </div>
    </div>
</div>

</body>
</html>
