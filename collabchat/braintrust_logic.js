// ============== STATE ==============
// sessionId, userId, currentProject, and projectName are defined in braintrust.php before this script loads

let currentFile = null;
let currentFilePath = null;
let fileContent = '';
let hasUnsavedChanges = false;

let editor = null;
let modalEditor = null;

let currentTurn = 'human';
let previousTurn = null;
let isProcessing = false;
let activeAIs = {
    claude: true,
    gemini: true
};
let pollInterval = null;

let contextMenuTarget = null;
let selectedItems = new Set(); // multi-select paths
let pendingImageData = null;
let lastMessageId = 0;

// AI permissions state (loaded from DB on startup)
let aiPermissions = {
    claude: { can_write:1, can_delete:1, can_run:1, can_terminal:1, can_packages:1, lead:0 },
    gemini: { can_write:1, can_delete:1, can_run:1, can_terminal:1, can_packages:1, lead:0 }
};

// Model selection state
let selectedModels = {
    claude: 'claude-sonnet-4-20250514',
    gemini: 'gemini-2.5-flash'
};

const availableModels = {
    claude: [
        { id: 'claude-sonnet-4-20250514', label: 'Sonnet 4', desc: 'Balanced' },
        { id: 'claude-opus-4-20250514', label: 'Opus 4', desc: 'Most capable' },
        { id: 'claude-sonnet-4-6', label: 'Sonnet 4.6', desc: 'Latest' },
        { id: 'claude-opus-4-6', label: 'Opus 4.6', desc: 'Most capable' }
    ],
    gemini: [
        { id: 'gemini-2.5-flash', label: '2.5 Flash', desc: 'Fast' },
        { id: 'gemini-2.5-pro', label: '2.5 Pro', desc: 'Most capable' }
    ]
};

// Whiteboard state
let currentDiagram = null;
let currentDiagramSource = null;
// Execution logs
let executionLogs = [];
// CLI Monitor state
let cliMonitorInterval = null;
let cliMonitorProvider = null;
let cliMonitorOffset = 0;
// ============== INIT ==============
document.addEventListener('DOMContentLoaded', () => {
    initMonaco();
    
    // Project is pre-loaded from session - just load the files!
    if (currentProject) {
        refreshFileTree();
    }
    
    if (sessionId) {
        loadSession();
        startPolling();
        initAIManagerWS(); // v3: streaming AI output
        loadAIPermissions(); // load per-AI permission state
    }

    document.getElementById('messageInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Close context menus on click elsewhere
    document.addEventListener('click', () => {
        document.getElementById('contextMenu').style.display = 'none';
        document.getElementById('modelPickerMenu').style.display = 'none';
    });

    // Close modals on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (cliMonitorInterval) closeCliMonitor();
            document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
        }
    });
    // Whiteboard button click
const whiteboardBtn = document.getElementById('whiteboardBtn');
if (whiteboardBtn) {
    whiteboardBtn.addEventListener('click', showWhiteboardModal);
}

// Save whiteboard button
const saveWhiteboardBtn = document.getElementById('saveWhiteboardBtn');
if (saveWhiteboardBtn) {
    saveWhiteboardBtn.addEventListener('click', saveWhiteboard);
}

// Print whiteboard button
const printWhiteboardBtn = document.getElementById('printWhiteboardBtn');
if (printWhiteboardBtn) {
    printWhiteboardBtn.addEventListener('click', printWhiteboard);
}
const clearWhiteboardBtn = document.getElementById('clearWhiteboardBtn');
if (clearWhiteboardBtn) {
    clearWhiteboardBtn.addEventListener('click', clearWhiteboardFromModal);
}
// Function: Save Whiteboard
function saveWhiteboard() {
    if (!currentDiagram) {
        alert('No diagram to save');
        return;
    }
    
    fetch('api/save_whiteboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            project_id: sessionId,
            project_path: currentProject,
            diagram_html: currentDiagram,
            diagram_source: currentDiagramSource
        })
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert('✓ Whiteboard saved!');
        } else {
            alert('✗ Failed to save: ' + (result.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('✗ Error saving whiteboard');
        console.error(err);
    });
}

// Function: Print Whiteboard
function printWhiteboard() {
    if (!currentDiagram) {
        alert('No diagram to print');
        return;
    }
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>BrainTrust Whiteboard</title>
            <style>
                body { 
                    margin: 20px; 
                    background: white;
                }
                svg { 
                    max-width: 100%; 
                    height: auto; 
                }
            </style>
        </head>
        <body>
            ${currentDiagram}
            <script>
                window.onload = () => {
                    window.print();
                    setTimeout(() => window.close(), 500);
                };
            </script>
        </body>
        </html>
    `);
    printWindow.document.close();
}
});
// Function: Clear Whiteboard from Modal
function clearWhiteboardFromModal() {
    if (confirm('Clear the whiteboard? This will remove the current diagram.')) {
        clearWhiteboard();
        document.getElementById('whiteboardContent').innerHTML = '<div class="whiteboard-empty">No diagrams generated yet.</div>';
        // Optional: close the modal since there's nothing to see
        closeModal('whiteboardModal');
    }
}
// ============== MONACO EDITOR ==============
function initMonaco() {
    require.config({ paths: { vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs' } });
    
    require(['vs/editor/editor.main'], function () {
        // Define dark theme
        monaco.editor.defineTheme('braintrust-dark', {
            base: 'vs-dark',
            inherit: true,
            rules: [],
            colors: {
                'editor.background': '#12121a',
                'editor.lineHighlightBackground': '#1a1a25',
                'editorLineNumber.foreground': '#606078',
                'editorCursor.foreground': '#9ece6a'
            }
        });

        // Main editor
        editor = monaco.editor.create(document.getElementById('monacoEditor'), {
            value: '',
            language: 'plaintext',
            theme: 'braintrust-dark',
            fontSize: 13,
            fontFamily: "'JetBrains Mono', monospace",
            minimap: { enabled: false },
            scrollBeyondLastLine: false,
            automaticLayout: true,
            tabSize: 4,
            wordWrap: 'on'
        });

        editor.onDidChangeModelContent(() => {
            if (currentFile) {
                // Only mark as unsaved if content actually differs from original
                hasUnsavedChanges = (editor.getValue() !== fileContent);
                updateEditorHeader();
            }
        });

        editor.onDidChangeCursorPosition((e) => {
            document.getElementById('editorPosition').textContent = `Ln ${e.position.lineNumber}, Col ${e.position.column}`;
        });

        // Ctrl+S to save
        editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
            saveFile();
        });
    });
}

function initModalEditor() {
    if (modalEditor) return;
    
    require(['vs/editor/editor.main'], function () {
        modalEditor = monaco.editor.create(document.getElementById('modalMonacoEditor'), {
            value: '',
            language: 'plaintext',
            theme: 'braintrust-dark',
            fontSize: 14,
            fontFamily: "'JetBrains Mono', monospace",
            minimap: { enabled: true },
            scrollBeyondLastLine: false,
            automaticLayout: true,
            tabSize: 4,
            wordWrap: 'on'
        });

        modalEditor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => {
            saveFileFromModal();
        });
    });
}

// ============== PROJECTS ==============
// Project is now loaded from session - no dropdown needed!
// See braintrust_projects.php for project management

// ============== FILE TREE ==============
async function refreshFileTree() {
    if (!currentProject) return;

    try {
        const res = await fetch(`files_api.php?action=get_tree&project=${encodeURIComponent(currentProject)}`);
        const data = await res.json();

        if (data.success) {
            const container = document.getElementById('fileTree');
            container.innerHTML = renderTree(data.tree);
            selectedItems.clear();
        }
    } catch (err) {
        console.error('Failed to load file tree:', err);
    }
}

function renderTree(items, level = 0) {
    if (!items || items.length === 0) return '';
    
    let html = '';
    items.forEach(item => {
        const isFolder = item.type === 'folder';
        const icon = isFolder ? '📁' : getFileIcon(item.extension);

        html += `
            <div class="tree-item ${isFolder ? 'folder' : 'file'}"
                 data-path="${item.path}"
                 data-type="${item.type}"
                 onclick="handleTreeClick(event, this)"
                 oncontextmenu="showContextMenu(event, this)">
                <span class="tree-icon ${isFolder ? 'folder' : 'file'}">${icon}</span>
                <span class="tree-name">${item.name}</span>
            </div>
        `;
        
        if (isFolder && item.children) {
            html += `<div class="tree-children">${renderTree(item.children, level + 1)}</div>`;
        }
    });
    return html;
}

function getFileIcon(ext) {
    const icons = {
        php: '🐘', js: '📜', html: '🌐', css: '🎨', json: '📋',
        md: '📝', sql: '🗃️', py: '🐍', txt: '📄', xml: '📰'
    };
    return icons[ext] || '📄';
}

const IMAGE_EXTS = /\.(jpe?g|png|gif|webp|svg|bmp|ico|tiff?)$/i;

function handleTreeClick(event, element) {
    event.stopPropagation();

    const path = element.dataset.path;
    const type = element.dataset.type;

    if (event.ctrlKey || event.metaKey) {
        // Ctrl+click: toggle item in multi-selection
        if (selectedItems.has(path)) {
            selectedItems.delete(path);
            element.classList.remove('selected');
        } else {
            selectedItems.add(path);
            element.classList.add('selected');
        }
        return;
    }

    // Regular click: clear multi-select, normal single-select behavior
    selectedItems.clear();
    document.querySelectorAll('.tree-item.selected').forEach(el => el.classList.remove('selected'));
    element.classList.add('selected');

    if (type === 'folder') {
        element.classList.toggle('expanded');
    } else if (IMAGE_EXTS.test(path)) {
        openImageModal(path);
    } else {
        openFile(path);
    }
}

function openImageModal(path) {
    const filename = path.split('/').pop();
    const src = `serve_image.php?project=${encodeURIComponent(currentProject)}&path=${encodeURIComponent(path)}`;
    document.getElementById('imageViewerTitle').textContent = '🖼️ ' + filename;
    document.getElementById('imageViewerImg').src = src;
    const modal = document.getElementById('imageViewerModal');
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
}

// ============== FILE OPERATIONS ==============
async function openFile(path) {
    if (hasUnsavedChanges && !confirm('You have unsaved changes. Discard them?')) {
        return;
    }

    try {
        const res = await fetch(`files_api.php?action=read_file&project=${encodeURIComponent(currentProject)}&path=${encodeURIComponent(path)}`);
        const data = await res.json();

        if (data.success) {
            currentFile = path;
            currentFilePath = path;
            fileContent = data.content;

            document.getElementById('editorEmpty').style.display = 'none';
            document.getElementById('monacoEditor').style.display = 'block';

            editor.setValue(data.content);
            monaco.editor.setModelLanguage(editor.getModel(), data.language);

            // Set hasUnsavedChanges AFTER setValue to prevent false positive
            hasUnsavedChanges = false;

            document.getElementById('editorLanguage').textContent = data.language.toUpperCase();
            updateEditorHeader();
            updateFileIndicator();

            document.getElementById('saveBtn').disabled = false;
            document.getElementById('closeFileBtn').style.display = '';
            document.getElementById('snapshotBtn').style.display = '';
        } else {
            alert(data.error || 'Failed to open file');
        }
    } catch (err) {
        console.error(err);
        alert('Error opening file');
    }
}

function closeFile() {
    if (hasUnsavedChanges && !confirm('You have unsaved changes. Discard them?')) {
        return;
    }
    currentFile = null;
    currentFilePath = null;
    fileContent = '';
    hasUnsavedChanges = false;
    document.getElementById('monacoEditor').style.display = 'none';
    document.getElementById('editorEmpty').style.display = 'flex';
    document.getElementById('editorFileName').innerHTML = '<span style="color: var(--text-muted);">No file open</span>';
    document.getElementById('saveBtn').disabled = true;
    document.getElementById('closeFileBtn').style.display = 'none';
    document.getElementById('snapshotBtn').style.display = 'none';
}

async function saveFile() {
    if (!currentFile || !currentProject) return;

    try {
        const content = editor.getValue();
        const formData = new FormData();
        formData.append('action', 'write_file');
        formData.append('project', currentProject);
        formData.append('path', currentFile);
        formData.append('content', content);

        const res = await fetch('files_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            fileContent = content;  // Update tracked content first
            hasUnsavedChanges = false;
            updateEditorHeader();
        } else {
            alert(data.error || 'Failed to save');
        }
    } catch (err) {
        console.error(err);
        alert('Error saving file');
    }
}

function updateEditorHeader() {
    const el = document.getElementById('editorFileName');
    if (currentFile) {
        const unsaved = hasUnsavedChanges ? '<span class="unsaved">●</span>' : '';
        el.innerHTML = `📄 ${currentFile} ${unsaved}`;
    } else {
        el.innerHTML = '<span style="color: var(--text-muted);">No file open</span>';
    }
}

function updateFileIndicator() {
    const el = document.getElementById('currentFileIndicator');
    el.textContent = currentFile ? `📄 ${currentFile}` : '';
}

function createNewFile() {
    if (!currentProject) return alert('Select a project first');
    document.getElementById('newFileName').value = '';
    document.getElementById('newFileModal').style.display = 'block';
    document.getElementById('newFileName').focus();
}

async function createFile() {
    const name = document.getElementById('newFileName').value.trim();
    if (!name) return alert('Please enter a file name');

    try {
        const formData = new FormData();
        formData.append('action', 'create_file');
        formData.append('project', currentProject);
        formData.append('path', name);
        formData.append('content', '');

        const res = await fetch('files_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            closeModal('newFileModal');
            refreshFileTree();
            openFile(name);
        } else {
            alert(data.error || 'Failed to create file');
        }
    } catch (err) {
        alert('Error creating file');
    }
}
// ============== FILE EXECUTION ==============
function executeFile(filename) {
    if (!filename) {
        alert('No file specified to execute');
        return;
    }
    
    // Show executing indicator
    addSystemMessage(`⚙️ Executing ${filename} in Docker container...`);
    
    fetch('api/run_code.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: sessionId,
            project_path: currentProject,
            filename: filename
        })
    })
    .then(r => r.json())
    .then(result => {
        if (result.preview_url) {
            window.open(result.preview_url, '_blank');
            addSystemMessage(`✅ Preview opened in new tab: ${result.preview_url}`);
            appendLog('preview', `${filename} opened for preview: ${result.preview_url}`);
        } else if (result.success) {
            addSystemMessage(`✅ ${filename} executed successfully:\n\n${result.output}`);
            appendLog('docker', `${filename} executed successfully: ${result.output.substring(0, 200)}`);
        } else {
            addSystemMessage(`❌ ${filename} execution failed (exit code ${result.exit_code}):\n\n${result.output || result.error}`);
            appendLog('error', `${filename} failed (exit ${result.exit_code}): ${(result.output || result.error).substring(0, 200)}`);
        }
        loadSession();
    })
    .catch(err => {
        addSystemMessage(`❌ Error executing ${filename}: ${err.message}`);
        appendLog('error', `Error executing ${filename}: ${err.message}`);
        console.error(err);
    });
}

// Helper function to add system messages to chat
function addSystemMessage(text) {
    fetch('api/add_system_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: sessionId,
            message: text
        })
    })
    .then(() => loadSession())
    .catch(err => console.error('Failed to add system message:', err));
}
function createNewFolder() {
    if (!currentProject) return alert('Select a project first');
    document.getElementById('newFolderName').value = '';
    document.getElementById('newFolderModal').style.display = 'block';
    document.getElementById('newFolderName').focus();
}

async function createFolder() {
    const name = document.getElementById('newFolderName').value.trim();
    if (!name) return alert('Please enter a folder name');

    try {
        const formData = new FormData();
        formData.append('action', 'create_folder');
        formData.append('project', currentProject);
        formData.append('path', name);

        const res = await fetch('files_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            closeModal('newFolderModal');
            refreshFileTree();
        } else {
            alert(data.error || 'Failed to create folder');
        }
    } catch (err) {
        alert('Error creating folder');
    }
}

// ============== CONTEXT MENU ==============
function showContextMenu(event, element) {
    event.preventDefault();
    event.stopPropagation();

    // If right-clicking something not already in the multi-selection, reset to single
    if (!selectedItems.has(element.dataset.path)) {
        selectedItems.clear();
        document.querySelectorAll('.tree-item.selected').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        selectedItems.add(element.dataset.path);
    }

    contextMenuTarget = element;

    // Show/update "Delete Selected" option
    const deleteSelectedBtn = document.getElementById('ctxDeleteSelected');
    if (selectedItems.size > 1) {
        deleteSelectedBtn.style.display = '';
        deleteSelectedBtn.textContent = `🗑️ Delete Selected (${selectedItems.size})`;
    } else {
        deleteSelectedBtn.style.display = 'none';
    }

    const menu = document.getElementById('contextMenu');
    menu.style.display = 'block';
    menu.style.left = event.pageX + 'px';
    menu.style.top = event.pageY + 'px';
}

async function deleteItem() {
    if (!contextMenuTarget) return;
    
    const path = contextMenuTarget.dataset.path;
    const type = contextMenuTarget.dataset.type;
    
    if (!confirm(`Delete ${type} "${path}"?`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete_item');
        formData.append('project', currentProject);
        formData.append('path', path);

        const res = await fetch('files_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            refreshFileTree();
            if (currentFile === path) {
                currentFile = null;
                document.getElementById('editorEmpty').style.display = 'flex';
                document.getElementById('monacoEditor').style.display = 'none';
                updateEditorHeader();
            }
        } else {
            alert(data.error);
        }
    } catch (err) {
        alert('Error deleting item');
    }
}

function renameItem() {
    if (!contextMenuTarget) return;
    const oldPath = contextMenuTarget.dataset.path;
    const newName = prompt('New name:', oldPath.split('/').pop());
    
    if (newName && newName !== oldPath.split('/').pop()) {
        // Build new path
        const parts = oldPath.split('/');
        parts.pop();
        parts.push(newName);
        const newPath = parts.join('/') || newName;
        
        // Call rename API
        const formData = new FormData();
        formData.append('action', 'rename_item');
        formData.append('project', currentProject);
        formData.append('old_path', oldPath);
        formData.append('new_path', newPath);

        fetch('files_api.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) refreshFileTree();
                else alert(data.error);
            });
    }
}

function copyPath() {
    if (!contextMenuTarget) return;
    navigator.clipboard.writeText(contextMenuTarget.dataset.path);
}

function selectAllFiles() {
    selectedItems.clear();
    document.querySelectorAll('.tree-item.selected').forEach(el => el.classList.remove('selected'));
    document.querySelectorAll('.tree-item.file').forEach(el => {
        selectedItems.add(el.dataset.path);
        el.classList.add('selected');
    });
    document.getElementById('contextMenu').style.display = 'none';
}

async function deleteSelected() {
    if (selectedItems.size === 0) return;
    const paths = [...selectedItems];
    if (!confirm(`Delete ${paths.length} item${paths.length > 1 ? 's' : ''}? This cannot be undone.`)) return;

    document.getElementById('contextMenu').style.display = 'none';

    for (const path of paths) {
        const formData = new FormData();
        formData.append('action', 'delete_item');
        formData.append('project', currentProject);
        formData.append('path', path);
        try {
            await fetch('files_api.php', { method: 'POST', body: formData });
        } catch (e) { /* keep going */ }
    }

    selectedItems.clear();

    if (paths.includes(currentFile)) {
        currentFile = null;
        document.getElementById('editorEmpty').style.display = 'flex';
        document.getElementById('monacoEditor').style.display = 'none';
        updateEditorHeader();
    }

    refreshFileTree();
}

// ============== EDITOR MODAL ==============
function expandEditor() {
    if (!currentFile) return;
    
    initModalEditor();
    document.getElementById('modalEditorFileName').textContent = `📝 ${currentFile}`;
    document.getElementById('editorModal').style.display = 'block';
    
    setTimeout(() => {
        modalEditor.setValue(editor.getValue());
        monaco.editor.setModelLanguage(modalEditor.getModel(), editor.getModel().getLanguageId());
        modalEditor.focus();
    }, 100);
}

function closeEditorModal() {
    // Sync changes back to main editor
    if (modalEditor && editor) {
        editor.setValue(modalEditor.getValue());
    }
    document.getElementById('editorModal').style.display = 'none';
}

function saveFileFromModal() {
    if (modalEditor && editor) {
        editor.setValue(modalEditor.getValue());
    }
    saveFile();
}

// ============== CANVAS MODAL ==============
let canvasActiveTool  = 'pen';
let canvasIsDrawing   = false;
let canvasStartX      = 0;
let canvasStartY      = 0;
let canvasBaseData    = null; // saved ImageData for shape preview
let canvasHasContent  = false;

function openCanvasModal() {
    clearCanvas();
    document.getElementById('canvasModal').style.display = 'block';
    setTimeout(() => document.getElementById('canvasPasteArea').focus(), 60);
}

function setCanvasTool(tool) {
    canvasActiveTool = tool;
    document.querySelectorAll('.canvas-tool-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tool-' + tool).classList.add('active');
    const canvas = document.getElementById('screenshotCanvas');
    canvas.style.cursor = (tool === 'text') ? 'text' : 'crosshair';
}

function getCanvasCoords(e) {
    const canvas = document.getElementById('screenshotCanvas');
    const rect   = canvas.getBoundingClientRect();
    return {
        x: (e.clientX - rect.left)  * (canvas.width  / rect.width),
        y: (e.clientY - rect.top)   * (canvas.height / rect.height)
    };
}

function getDrawCtx() {
    const canvas = document.getElementById('screenshotCanvas');
    const ctx    = canvas.getContext('2d');
    ctx.strokeStyle = document.getElementById('canvasColor').value;
    ctx.fillStyle   = document.getElementById('canvasColor').value;
    ctx.lineWidth   = parseInt(document.getElementById('canvasLineWidth').value);
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    return ctx;
}

function saveCanvasBase() {
    const canvas = document.getElementById('screenshotCanvas');
    canvasBaseData = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
}

function restoreCanvasBase() {
    if (!canvasBaseData) return;
    document.getElementById('screenshotCanvas').getContext('2d').putImageData(canvasBaseData, 0, 0);
}

function markCanvasDirty() {
    canvasHasContent = true;
    document.getElementById('attachImageBtn').disabled = false;
    document.getElementById('clearCanvasBtn').style.display = '';
}

function initBlankCanvas() {
    const canvas = document.getElementById('screenshotCanvas');
    canvas.width  = 1200;
    canvas.height = 800;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#1a1a25';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    canvas.style.display = 'block';
    document.getElementById('canvasPlaceholder').style.display = 'none';
    document.getElementById('canvasStatusText').textContent = '1200×800 — drawing on blank canvas';
    saveCanvasBase();
}

function clearCanvas() {
    const canvas = document.getElementById('screenshotCanvas');
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    canvas.style.display = 'none';
    canvas.style.cursor  = 'crosshair';
    document.getElementById('canvasPlaceholder').style.display = 'flex';
    document.getElementById('attachImageBtn').disabled = true;
    document.getElementById('clearCanvasBtn').style.display  = 'none';
    document.getElementById('canvasStatusText').textContent = 'Paste a screenshot · or click to draw on blank canvas';
    canvasIsDrawing  = false;
    canvasBaseData   = null;
    canvasHasContent = false;
    const ti = document.getElementById('canvasTextInput');
    if (ti) ti.remove();
}

// ── Mouse events (registered after DOM is ready) ─────────────────────────────
document.addEventListener('DOMContentLoaded', function() {

    // Clicking the placeholder area inits blank canvas then starts drawing
    document.getElementById('canvasPasteArea').addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        const canvas = document.getElementById('screenshotCanvas');
        if (canvas.style.display === 'none') {
            initBlankCanvas();
            startDraw(e);
        }
        // if canvas visible, canvas's own listener handles it
    });

    document.getElementById('screenshotCanvas').addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        if (canvasActiveTool === 'text') e.preventDefault(); // prevent focus steal so text input stays focused
        startDraw(e);
    });

    document.getElementById('screenshotCanvas').addEventListener('mousemove', function(e) {
        if (!canvasIsDrawing) return;
        const coords = getCanvasCoords(e);
        const ctx    = getDrawCtx();
        if (canvasActiveTool === 'pen') {
            ctx.lineTo(coords.x, coords.y);
            ctx.stroke();
        } else if (canvasActiveTool === 'rect') {
            restoreCanvasBase();
            ctx.beginPath();
            ctx.strokeRect(canvasStartX, canvasStartY, coords.x - canvasStartX, coords.y - canvasStartY);
        } else if (canvasActiveTool === 'circle') {
            restoreCanvasBase();
            const rx = (coords.x - canvasStartX) / 2;
            const ry = (coords.y - canvasStartY) / 2;
            ctx.beginPath();
            ctx.ellipse(canvasStartX + rx, canvasStartY + ry, Math.abs(rx), Math.abs(ry), 0, 0, Math.PI * 2);
            ctx.stroke();
        }
    });

}); // end DOMContentLoaded for canvas mouse events

// mouseup on document — outside DOMContentLoaded since document is always available
document.addEventListener('mouseup', function(e) {
    if (!canvasIsDrawing) return;
    canvasIsDrawing = false;
    const coords = getCanvasCoords(e);
    const ctx    = getDrawCtx();
    if (canvasActiveTool === 'pen') {
        ctx.lineTo(coords.x, coords.y);
        ctx.stroke();
    } else if (canvasActiveTool === 'rect') {
        restoreCanvasBase();
        ctx.beginPath();
        ctx.strokeRect(canvasStartX, canvasStartY, coords.x - canvasStartX, coords.y - canvasStartY);
    } else if (canvasActiveTool === 'circle') {
        restoreCanvasBase();
        const rx = (coords.x - canvasStartX) / 2;
        const ry = (coords.y - canvasStartY) / 2;
        ctx.beginPath();
        ctx.ellipse(canvasStartX + rx, canvasStartY + ry, Math.abs(rx), Math.abs(ry), 0, 0, Math.PI * 2);
        ctx.stroke();
    }
    saveCanvasBase();
    markCanvasDirty();
});

function startDraw(e) {
    const coords  = getCanvasCoords(e);
    canvasStartX  = coords.x;
    canvasStartY  = coords.y;
    if (canvasActiveTool === 'text') {
        placeTextInput(e, coords);
        return;
    }
    saveCanvasBase();
    canvasIsDrawing = true;
    if (canvasActiveTool === 'pen') {
        const ctx = getDrawCtx();
        ctx.beginPath();
        ctx.moveTo(coords.x, coords.y);
    }
}

// ── Text tool ─────────────────────────────────────────────────────────────────

function placeTextInput(e, coords) {
    const existing = document.getElementById('canvasTextInput');
    if (existing) existing.remove();

    const canvas      = document.getElementById('screenshotCanvas');
    const pasteArea   = document.getElementById('canvasPasteArea');
    const canvasRect  = canvas.getBoundingClientRect();
    const pasteRect   = pasteArea.getBoundingClientRect();
    const fontSize    = parseInt(document.getElementById('canvasFontSize').value);
    const color       = document.getElementById('canvasColor').value;

    const left = (canvasRect.left - pasteRect.left) + (e.clientX - canvasRect.left);
    const top  = (canvasRect.top  - pasteRect.top)  + (e.clientY - canvasRect.top) - fontSize;

    const input = document.createElement('input');
    input.type  = 'text';
    input.id    = 'canvasTextInput';
    input.placeholder = 'Type, press Enter';
    input.style.cssText = `
        position:absolute; left:${left}px; top:${top}px;
        background:transparent; border:1px dashed rgba(255,255,255,0.35);
        color:${color}; font-size:${fontSize}px; font-family:Arial,sans-serif;
        font-weight:bold; padding:2px 5px; outline:none; min-width:120px; z-index:10;
    `;
    pasteArea.appendChild(input);
    input.focus();

    const commit = () => {
        if (input.value.trim()) commitCanvasText(coords.x, coords.y, input.value, fontSize, color);
        input.remove();
    };
    input.addEventListener('keydown', ev => {
        if (ev.key === 'Enter')  { commit(); ev.preventDefault(); }
        if (ev.key === 'Escape') { input.remove(); }
    });
    input.addEventListener('blur', commit);
}

function commitCanvasText(x, y, text, fontSize, color) {
    const ctx = document.getElementById('screenshotCanvas').getContext('2d');
    ctx.font         = `bold ${fontSize}px Arial, sans-serif`;
    ctx.fillStyle    = color;
    ctx.shadowColor  = 'rgba(0,0,0,0.85)';
    ctx.shadowBlur   = 4;
    ctx.shadowOffsetX = 1;
    ctx.shadowOffsetY = 1;
    ctx.fillText(text, x, y);
    ctx.shadowBlur = ctx.shadowOffsetX = ctx.shadowOffsetY = 0;
    saveCanvasBase();
    markCanvasDirty();
}

// ── Paste handler ─────────────────────────────────────────────────────────────

function handleCanvasPaste(e) {
    if (document.getElementById('canvasModal').style.display !== 'block') return;
    const items = e.clipboardData?.items;
    if (!items) return;
    for (const item of items) {
        if (item.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(evt) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.getElementById('screenshotCanvas');
                    let w = img.width, h = img.height;
                    const MAX = 1920;
                    if (w > MAX || h > MAX) {
                        const ratio = Math.min(MAX / w, MAX / h);
                        w = Math.round(w * ratio);
                        h = Math.round(h * ratio);
                    }
                    canvas.width  = w;
                    canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.style.display = 'block';
                    document.getElementById('canvasPlaceholder').style.display = 'none';
                    document.getElementById('canvasStatusText').textContent = `${img.width}×${img.height}px — draw on top or attach`;
                    saveCanvasBase();
                    markCanvasDirty();
                };
                img.src = evt.target.result;
            };
            reader.readAsDataURL(item.getAsFile());
            e.preventDefault();
            break;
        }
    }
}

document.addEventListener('paste', handleCanvasPaste);

// ── Keyboard shortcuts (P/R/C/T) while canvas modal is open ──────────────────
document.addEventListener('keydown', function(e) {
    if (document.getElementById('canvasModal').style.display !== 'block') return;
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    const map = { p: 'pen', r: 'rect', c: 'circle', t: 'text' };
    if (map[e.key.toLowerCase()]) setCanvasTool(map[e.key.toLowerCase()]);
});

// ── Attach / remove ───────────────────────────────────────────────────────────

async function saveCanvasToProject() {
    const canvas = document.getElementById('screenshotCanvas');
    const imageData = canvas.toDataURL('image/jpeg', 0.9);

    let filename = prompt('Save image as (filename):', 'canvas_' + Date.now() + '.jpg');
    if (!filename) return; // user cancelled

    // Ensure .jpg extension
    if (!/\.(jpg|jpeg|png|gif|webp)$/i.test(filename)) filename += '.jpg';

    const btn = document.getElementById('attachImageBtn');
    const origText = btn.textContent;
    btn.textContent = 'Saving…';
    btn.disabled = true;

    try {
        const res = await fetch('api/save_canvas_image.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, filename, image_data: imageData })
        });
        const data = await res.json();

        if (data.success) {
            document.getElementById('canvasStatusText').textContent = `✅ Saved as "${data.filename}" in project folder`;
            btn.textContent = '✅ Saved!';
            refreshFileTree();
            setTimeout(() => {
                btn.textContent = origText;
                btn.disabled = false;
            }, 2500);
        } else {
            alert('Save failed: ' + (data.error || 'Unknown error'));
            btn.textContent = origText;
            btn.disabled = false;
        }
    } catch (err) {
        alert('Save failed: ' + err.message);
        btn.textContent = origText;
        btn.disabled = false;
    }
}

function removeImageAttachment() {
    pendingImageData = null;
    const preview = document.getElementById('imageAttachPreview');
    if (preview) preview.remove();
}

// ============== GITHUB TOOL ==============
async function openGitHubModal() {
    document.getElementById('githubModal').style.display = 'block';
    const body  = document.getElementById('githubModalBody');
    const badge = document.getElementById('githubStatusBadge');
    body.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:32px;">Checking project status…</p>';
    badge.textContent = '';

    try {
        const res  = await fetch(`api/github_tool.php?action=status&project=${encodeURIComponent(currentProject)}`);
        const data = await res.json();
        if (!data.success) { body.innerHTML = `<p style="color:var(--error);">${escapeHtml(data.error)}</p>`; return; }

        if (!data.configured) {
            body.innerHTML = `
                <div style="text-align:center;padding:40px;color:var(--text-muted);">
                    <div style="font-size:48px;margin-bottom:16px;">🔑</div>
                    <p>GitHub token not configured.</p>
                    <p style="font-size:13px;margin-top:8px;">Add your Personal Access Token (repo scope) to:<br>
                    <code style="color:var(--accent-claude);">/var/www/secure_config/braintrust_config.php</code><br>
                    <code style="color:var(--text-secondary);">define('GITHUB_TOKEN', 'ghp_yourtoken');</code></p>
                </div>`;
            return;
        }

        if (data.has_git && data.remote_url) {
            renderCommitUI(body, badge, data);
        } else {
            await renderWizardUI(body, badge, data);
        }
    } catch (err) {
        body.innerHTML = `<p style="color:var(--error);">Error: ${escapeHtml(err.message)}</p>`;
    }
}

async function renderWizardUI(body, badge, status) {
    badge.textContent = '✨ New repo';
    badge.style.color = 'var(--accent-gemini)';

    const fr    = await fetch(`api/github_tool.php?action=get_files&project=${encodeURIComponent(currentProject)}`);
    const fd    = await fr.json();
    const files = fd.files || [];

    const fileRows = files.map(f => `
        <label class="github-file-row">
            <input type="checkbox" value="${escapeHtml(f.name)}" ${f.checked ? 'checked' : ''}>
            <span style="font-family:monospace;font-size:13px;">${escapeHtml(f.name)}</span>
        </label>`).join('');

    const defaultName = (projectName || currentProject).replace(/[^a-zA-Z0-9_\-.]/g, '-').toLowerCase();

    body.innerHTML = `
        <div style="display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid var(--border-subtle);padding-bottom:12px;">
            <button id="ghTabNew"   onclick="ghSwitchTab('new')"   class="btn btn-primary btn-small"   style="flex:1;">✨ New Repo</button>
            <button id="ghTabClone" onclick="ghSwitchTab('clone')" class="btn btn-secondary btn-small" style="flex:1;">📥 Clone Existing</button>
        </div>

        <div id="ghSectionNew">
            <div class="github-section">
                <label class="github-label">Repo name</label>
                <input type="text" id="ghRepoName" value="${escapeHtml(defaultName)}"
                    style="width:100%;box-sizing:border-box;background:var(--bg-elevated);border:1px solid var(--border-subtle);color:var(--text-primary);border-radius:4px;padding:8px 10px;font-size:14px;">
            </div>
            <div class="github-section" style="display:flex;gap:24px;align-items:center;">
                <label class="github-label" style="margin:0;">Visibility</label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="ghVisibility" value="false"> Public
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="ghVisibility" value="true" checked> Private
                </label>
            </div>
            <div class="github-section">
                <label class="github-label">.gitignore — check items to exclude</label>
                <div class="github-file-list" id="ghIgnoreList">${fileRows}</div>
                <div style="margin-top:6px;">
                    <button onclick="ghToggleAll(true)"  class="btn btn-secondary btn-small">Check all</button>
                    <button onclick="ghToggleAll(false)" class="btn btn-secondary btn-small">Uncheck all</button>
                </div>
            </div>
            <div class="github-section">
                <label class="github-label">Commit message</label>
                <input type="text" id="ghCommitMsg" value="Initial commit"
                    style="width:100%;box-sizing:border-box;background:var(--bg-elevated);border:1px solid var(--border-subtle);color:var(--text-primary);border-radius:4px;padding:8px 10px;font-size:14px;">
            </div>
            <div style="margin-top:8px;">
                <button class="btn btn-primary" id="ghCreateBtn" onclick="ghInitPush()">🚀 Create Repo &amp; Push</button>
                <span id="ghSpinner" style="display:none;color:var(--text-muted);font-size:13px;margin-left:12px;">Working…</span>
            </div>
        </div>

        <div id="ghSectionClone" style="display:none;">
            <div class="github-section">
                <label class="github-label">GitHub repository URL</label>
                <input type="text" id="ghCloneUrl" placeholder="https://github.com/username/repo"
                    style="width:100%;box-sizing:border-box;background:var(--bg-elevated);border:1px solid var(--border-subtle);color:var(--text-primary);border-radius:4px;padding:8px 10px;font-size:14px;">
                <p style="font-size:12px;color:var(--text-muted);margin-top:6px;">Clones the repo into your current project folder. Any existing files will be overwritten by the repo contents.</p>
            </div>
            <div style="margin-top:8px;">
                <button class="btn btn-primary" id="ghCloneBtn" onclick="ghClone()">📥 Clone Repository</button>
                <span id="ghCloneSpinner" style="display:none;color:var(--text-muted);font-size:13px;margin-left:12px;">Cloning… (this may take a moment)</span>
            </div>
        </div>

        <div id="ghResult" style="margin-top:16px;"></div>`;
}

function ghSwitchTab(tab) {
    const isNew = tab === 'new';
    document.getElementById('ghSectionNew').style.display   = isNew ? '' : 'none';
    document.getElementById('ghSectionClone').style.display = isNew ? 'none' : '';
    document.getElementById('ghTabNew').className   = 'btn btn-small ' + (isNew ? 'btn-primary' : 'btn-secondary');
    document.getElementById('ghTabClone').className = 'btn btn-small ' + (isNew ? 'btn-secondary' : 'btn-primary');
    document.getElementById('ghResult').innerHTML   = '';
}

async function ghClone() {
    const cloneUrl = document.getElementById('ghCloneUrl').value.trim();
    const btn      = document.getElementById('ghCloneBtn');
    const spinner  = document.getElementById('ghCloneSpinner');
    const result   = document.getElementById('ghResult');

    if (!cloneUrl) { alert('Please enter a repository URL.'); return; }

    btn.disabled = true; spinner.style.display = 'inline';

    const formData = new FormData();
    formData.append('action',    'clone');
    formData.append('project',   currentProject);
    formData.append('clone_url', cloneUrl);

    try {
        const res  = await fetch('api/github_tool.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            result.innerHTML = `<div class="github-success">✅ ${escapeHtml(data.message)}<br>
                <a href="${escapeHtml(data.repo_url)}" target="_blank" style="color:var(--accent-claude);">${escapeHtml(data.repo_url)}</a><br>
                <small style="color:var(--text-muted);">Branch: ${escapeHtml(data.branch)} — Hit 🔄 Refresh in the Explorer to see your files.</small></div>`;
            document.getElementById('githubStatusBadge').textContent = `🌿 ${data.branch}`;
            document.getElementById('githubStatusBadge').style.color = 'var(--accent-claude)';
            btn.textContent = '✅ Cloned!';
        } else {
            result.innerHTML = `<div class="github-error">❌ ${escapeHtml(data.error)}</div>`;
            btn.disabled = false;
        }
    } catch (err) {
        result.innerHTML = `<div class="github-error">❌ ${escapeHtml(err.message)}</div>`;
        btn.disabled = false;
    }
    spinner.style.display = 'none';
}

function renderCommitUI(body, badge, data) {
    const repoDisplay = data.remote_url.replace(/https:\/\/.+@/, 'https://').replace(/\.git$/, '');
    badge.textContent = `🌿 ${data.branch}`;
    badge.style.color = 'var(--accent-claude)';

    const fileRows = data.changed.length === 0
        ? '<p style="color:var(--text-muted);padding:12px;">Nothing to commit — working tree clean.</p>'
        : data.changed.map(f => `
            <label class="github-file-row">
                <input type="checkbox" value="${escapeHtml(f.file)}" checked>
                <span class="github-status-badge github-status-${f.status.toLowerCase()}">${escapeHtml(f.status)}</span>
                <span style="font-family:monospace;font-size:13px;">${escapeHtml(f.file)}</span>
            </label>`).join('');

    body.innerHTML = `
        <div class="github-section" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="min-width:0;">
                <label class="github-label">Repository</label>
                <a href="${escapeHtml(repoDisplay)}" target="_blank"
                   style="color:var(--accent-claude);font-size:13px;font-family:monospace;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(repoDisplay)}</a>
                <button onclick="ghDisconnectRepo()" class="btn btn-secondary btn-small" style="margin-top:8px;font-size:13px;color:var(--danger);">⚠️ Disconnect Repo</button>
            </div>
            <button class="btn btn-secondary btn-small" id="ghPullBtn" onclick="ghPull()" style="white-space:nowrap;flex-shrink:0;">📥 Pull</button>
        </div>
        <div class="github-section">
            <label class="github-label">Changed files</label>
            <div class="github-file-list" id="ghFileList">${fileRows}</div>
            ${data.changed.length > 0 ? `
            <div style="margin-top:6px;">
                <button onclick="ghToggleAll(true)"  class="btn btn-secondary btn-small">Check all</button>
                <button onclick="ghToggleAll(false)" class="btn btn-secondary btn-small">Uncheck all</button>
            </div>` : ''}
        </div>
        ${data.changed.length > 0 ? `
        <div class="github-section">
            <label class="github-label">Commit message</label>
            <input type="text" id="ghCommitMsg" placeholder="Describe your changes…"
                style="width:100%;box-sizing:border-box;background:var(--bg-elevated);border:1px solid var(--border-subtle);color:var(--text-primary);border-radius:4px;padding:8px 10px;font-size:14px;">
        </div>
        <div style="margin-top:8px;">
            <button class="btn btn-primary" id="ghCommitBtn" onclick="ghCommitPush()">📤 Commit &amp; Push</button>
            <span id="ghSpinner" style="display:none;color:var(--text-muted);font-size:13px;margin-left:12px;">Pushing…</span>
        </div>` : ''}
        <div id="ghResult" style="margin-top:16px;"></div>`;
}

function ghToggleAll(checked) {
    const list = document.getElementById('ghIgnoreList') || document.getElementById('ghFileList');
    if (list) list.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = checked);
}

async function ghInitPush() {
    const repoName  = document.getElementById('ghRepoName').value.trim();
    const priv      = document.querySelector('input[name="ghVisibility"]:checked')?.value ?? 'true';
    const commitMsg = document.getElementById('ghCommitMsg').value.trim() || 'Initial commit';
    const ignores   = [...document.querySelectorAll('#ghIgnoreList input:checked')].map(cb => cb.value);
    const btn       = document.getElementById('ghCreateBtn');
    const spinner   = document.getElementById('ghSpinner');
    const result    = document.getElementById('ghResult');

    if (!repoName) { alert('Please enter a repo name.'); return; }
    btn.disabled = true; spinner.style.display = 'inline';

    const formData = new FormData();
    formData.append('action',         'init_push');
    formData.append('project',        currentProject);
    formData.append('repo_name',      repoName);
    formData.append('private',        priv);
    formData.append('commit_message', commitMsg);
    formData.append('gitignore',      JSON.stringify(ignores));

    try {
        const res  = await fetch('api/github_tool.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            result.innerHTML = `<div class="github-success">✅ ${escapeHtml(data.message)}<br>
                <a href="${escapeHtml(data.repo_url)}" target="_blank" style="color:var(--accent-claude);">${escapeHtml(data.repo_url)}</a></div>`;
            document.getElementById('githubStatusBadge').textContent = '✅ Pushed!';
            btn.textContent = '✅ Done!';
        } else {
            result.innerHTML = `<div class="github-error">❌ ${escapeHtml(data.error)}</div>`;
            btn.disabled = false;
        }
    } catch (err) {
        result.innerHTML = `<div class="github-error">❌ ${escapeHtml(err.message)}</div>`;
        btn.disabled = false;
    }
    spinner.style.display = 'none';
}

async function ghCommitPush() {
    const commitMsg = document.getElementById('ghCommitMsg').value.trim();
    const files     = [...document.querySelectorAll('#ghFileList input:checked')].map(cb => cb.value);
    const btn       = document.getElementById('ghCommitBtn');
    const spinner   = document.getElementById('ghSpinner');
    const result    = document.getElementById('ghResult');

    if (!commitMsg) { alert('Please enter a commit message.'); return; }
    if (!files.length) { alert('No files selected.'); return; }
    btn.disabled = true; spinner.style.display = 'inline';

    const formData = new FormData();
    formData.append('action',         'commit_push');
    formData.append('project',        currentProject);
    formData.append('commit_message', commitMsg);
    formData.append('files',          JSON.stringify(files));

    try {
        const res  = await fetch('api/github_tool.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            result.innerHTML = `<div class="github-success">✅ ${escapeHtml(data.message)}</div>`;
            document.getElementById('githubStatusBadge').textContent = '✅ Pushed!';
            btn.textContent = '✅ Done!';
        } else {
            result.innerHTML = `<div class="github-error">❌ ${escapeHtml(data.error)}</div>`;
            btn.disabled = false;
        }
    } catch (err) {
        result.innerHTML = `<div class="github-error">❌ ${escapeHtml(err.message)}</div>`;
        btn.disabled = false;
    }
    spinner.style.display = 'none';
}

async function ghPull() {
    const btn    = document.getElementById('ghPullBtn');
    const result = document.getElementById('ghResult');

    btn.disabled = true;
    btn.textContent = '⏳ Pulling…';

    const formData = new FormData();
    formData.append('action',  'pull');
    formData.append('project', currentProject);

    try {
        const res  = await fetch('api/github_tool.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            result.innerHTML = `<div class="github-success">✅ ${escapeHtml(data.message)}<br>
                <pre style="font-size:12px;margin:6px 0 0;white-space:pre-wrap;color:var(--text-secondary);">${escapeHtml(data.output)}</pre></div>`;
            btn.textContent = '✅ Pulled!';
        } else {
            result.innerHTML = `<div class="github-error">❌ ${escapeHtml(data.error)}</div>`;
            btn.disabled = false;
            btn.textContent = '📥 Pull';
        }
    } catch (err) {
        result.innerHTML = `<div class="github-error">❌ ${escapeHtml(err.message)}</div>`;
        btn.disabled = false;
        btn.textContent = '📥 Pull';
    }
}

async function ghDisconnectRepo() {
    if (!confirm('Disconnect this repo? The .git folder will be removed and the project will return to pre-git state. This cannot be undone.')) return;

    const formData = new FormData();
    formData.append('action',  'git_reset');
    formData.append('project', currentProject);

    try {
        const res  = await fetch('api/github_tool.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            // Re-open the modal fresh — will now show the wizard with Clone tab
            document.getElementById('githubModal').style.display = 'none';
            openGitHubModal();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (err) {
        alert('Error: ' + err.message);
    }
}

// ============== BOOKMARKS ==============
async function toggleBookmark(msgId, btnEl) {
    const formData = new FormData();
    formData.append('action', 'toggle_bookmark');
    formData.append('session_id', sessionId);
    formData.append('message_id', msgId);

    try {
        const res  = await fetch('braintrust_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            btnEl.classList.toggle('bookmarked', data.bookmarked);
            btnEl.title = data.bookmarked ? 'Remove bookmark' : 'Bookmark this message';
        }
    } catch (err) {
        console.error('Bookmark toggle failed:', err);
    }
}

async function openBookmarksModal() {
    document.getElementById('bookmarksModal').style.display = 'block';
    const list  = document.getElementById('bookmarksList');
    const count = document.getElementById('bookmarkCount');
    list.innerHTML = '<p style="color:var(--text-muted); text-align:center;">Loading…</p>';

    try {
        const res  = await fetch(`braintrust_api.php?action=get_bookmarks&session_id=${sessionId}`);
        const data = await res.json();
        if (!data.success) { list.innerHTML = '<p style="color:var(--error);">Failed to load bookmarks.</p>'; return; }

        if (data.bookmarks.length === 0) {
            list.innerHTML = '<p style="color:var(--text-muted); text-align:center; padding:32px;">No bookmarks yet. Hover over any message and click 🔖 to save it.</p>';
            count.textContent = '';
            return;
        }

        count.textContent = `${data.bookmarks.length} bookmark${data.bookmarks.length !== 1 ? 's' : ''}`;
        list.innerHTML = data.bookmarks.map(msg => {
            const avatarClass = msg.sender_type === 'human' ? 'human' : msg.sender_type;
            const avatarText  = msg.sender_type === 'human' ? (msg.sender_name?.substring(0,2).toUpperCase() || 'US') :
                                msg.sender_type === 'claude' ? 'CL' : msg.sender_type === 'gemini' ? 'GE' : 'SYS';
            const senderName  = msg.sender_type === 'human' ? (msg.sender_name || 'You') :
                                msg.sender_type.charAt(0).toUpperCase() + msg.sender_type.slice(1);
            const timeStr     = new Date(msg.created_at.replace(' ', 'T') + 'Z').toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
            const preview     = msg.message_text.replace(/<[^>]+>/g, '').substring(0, 300) + (msg.message_text.length > 300 ? '…' : '');

            return `
                <div class="bookmark-card" data-msg-id="${msg.id}">
                    <div class="bookmark-card-header">
                        <div class="message-avatar ${avatarClass}" style="width:28px;height:28px;font-size:10px;flex-shrink:0;">${avatarText}</div>
                        <span class="message-sender ${avatarClass}" style="font-size:13px;">${senderName}</span>
                        <span style="color:var(--text-muted);font-size:11px;margin-left:auto;">${timeStr}</span>
                        <button onclick="removeBookmark(${msg.id}, this)" title="Remove bookmark" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:16px;padding:0 4px;">×</button>
                    </div>
                    <div class="bookmark-card-body">${escapeHtml(preview)}</div>
                </div>`;
        }).join('');
    } catch (err) {
        list.innerHTML = '<p style="color:var(--error);">Error loading bookmarks.</p>';
    }
}

async function removeBookmark(msgId, btnEl) {
    const formData = new FormData();
    formData.append('action', 'toggle_bookmark');
    formData.append('session_id', sessionId);
    formData.append('message_id', msgId);

    try {
        const res  = await fetch('braintrust_api.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success && !data.bookmarked) {
            // Remove card from modal
            btnEl.closest('.bookmark-card').remove();
            // Update count
            const remaining = document.querySelectorAll('.bookmark-card').length;
            document.getElementById('bookmarkCount').textContent =
                remaining ? `${remaining} bookmark${remaining !== 1 ? 's' : ''}` : '';
            if (!remaining) {
                document.getElementById('bookmarksList').innerHTML =
                    '<p style="color:var(--text-muted);text-align:center;padding:32px;">No bookmarks yet.</p>';
            }
            // Also un-highlight in chat
            const chatBtn = document.querySelector(`.message[data-msg-id="${msgId}"] .bookmark-btn`);
            if (chatBtn) { chatBtn.classList.remove('bookmarked'); chatBtn.title = 'Bookmark this message'; }
        }
    } catch (err) {
        console.error('Remove bookmark failed:', err);
    }
}

// ============== CHAT FUNCTIONS ==============
async function loadSession() {
    try {
        const res = await fetch(`braintrust_api.php?action=get_session&session_id=${sessionId}`);
        const data = await res.json();

        if (data.success) {
            clearAllStreamingBubbles(); // v3: remove in-progress bubbles before rendering from DB
            renderMessages(data.messages);
            updateTurnState(data.turn_state);
            // Refresh file tree when a new message arrives (AI may have created files)
            if (data.messages.length > 0) {
                const latestId = data.messages[data.messages.length - 1].id;
                if (latestId !== lastMessageId) {
                    lastMessageId = latestId;
                    refreshFileTree();
                }
            }
        }
    } catch (err) {
        console.error('Error loading session:', err);
    }
}

function createMessageHTML(msg) {
    const avatarClass = msg.sender_type === 'human' ? 'human' : msg.sender_type;
    const avatarText = msg.sender_type === 'human' ? (msg.sender_name?.substring(0,2).toUpperCase() || 'US') :
                      msg.sender_type === 'claude' ? 'CL' : msg.sender_type === 'gemini' ? 'GE' : 'SYS';
    const senderName = msg.sender_type === 'human' ? (msg.sender_name || 'You') :
                      msg.sender_type.charAt(0).toUpperCase() + msg.sender_type.slice(1);
    const timeStr = new Date(msg.created_at.replace(' ', 'T') + 'Z').toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

    const imageHtml = msg.image_data
        ? `<img src="${msg.image_data}" class="message-image" onclick="this.style.maxWidth=this.style.maxWidth?'':'100%'" title="Click to expand">`
        : '';

    const bookmarkClass = msg.bookmarked ? 'bookmarked' : '';
    const bookmarkTitle = msg.bookmarked ? 'Remove bookmark' : 'Bookmark this message';

    return `
        <div class="message" data-msg-id="${msg.id}">
            <div class="message-avatar ${avatarClass}">${avatarText}</div>
            <div class="message-content">
                <div class="message-header">
                    <span class="message-sender ${avatarClass}">${senderName}</span>
                    <span class="message-time">${timeStr}</span>
                    <button class="bookmark-btn ${bookmarkClass}" onclick="toggleBookmark(${msg.id}, this)" title="${bookmarkTitle}">🔖</button>
                </div>
                ${imageHtml}
                <div class="message-text">${formatMessage(msg.message_text)}</div>
            </div>
        </div>
    `;
}

function formatMessage(text) {
    // Handle code blocks with optional language
    text = text.replace(/```(\w*)\n([\s\S]*?)```/g, function(match, lang, code) {
        const languageClass = lang ? `language-${lang}` : 'language-plaintext';
        return `<pre class="${languageClass}"><code class="${languageClass}">${escapeHtml(code.trim())}</code></pre>`;
    });
    text = text.replace(/`([^`]+)`/g, function(match, code) {
        return '<code>' + escapeHtml(code) + '</code>';
    });
    text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\n/g, '<br>');
    return text;
}

function updateTurnState(state) {
    if (!state) return;
    currentTurn = state.current_turn_type;
    
    // Sync AI buttons
    if (state.hasOwnProperty('claude_enabled')) {
        activeAIs.claude = !!parseInt(state.claude_enabled);
        const btn = document.querySelector('button[onclick="toggleAI(\'claude\')"]');
        if (btn) btn.style.opacity = activeAIs.claude ? '1' : '0.4';
    }
    
    if (state.hasOwnProperty('gemini_enabled')) {
        activeAIs.gemini = !!parseInt(state.gemini_enabled);
        const btn = document.querySelector('button[onclick="toggleAI(\'gemini\')"]');
        if (btn) btn.style.opacity = activeAIs.gemini ? '1' : '0.4';
    }
    // Update CLI/API status dots on toggle buttons
    ['claude', 'gemini'].forEach(ai => {
        const btn = document.getElementById(ai + 'Toggle');
        if (!btn) return;
        let dot = btn.querySelector('.cli-status-dot');
        if (!dot) {
            dot = document.createElement('span');
            dot.className = 'cli-status-dot';
            btn.insertBefore(dot, btn.firstChild);
        }
        const uuidKey = ai + '_cli_session_uuid';
        const isCLI = !!state[uuidKey];
        dot.style.background = isCLI ? '#50fa7b' : '#ffb86c';
        dot.title = isCLI ? 'CLI mode (persistent memory)' : 'API mode (stateless fallback)';
    });

    // Sync model selections
    ['claude', 'gemini'].forEach(provider => {
        const key = provider + '_model';
        if (state[key]) {
            selectedModels[provider] = state[key];
            const models = availableModels[provider];
            const match = models.find(m => m.id === state[key]);
            const el = document.getElementById(provider + 'ModelLabel');
            if (el && match) el.textContent = match.label;
        }
    });

    // Sync floor dropdown
const floorDropdown = document.getElementById('floorDropdown');
if (floorDropdown) {
    const floorHolder = state.floor_holder || 'none';
    floorDropdown.value = floorHolder;
    
    // Update button brightness
    ['claude', 'gemini'].forEach(a => {
        const btn = document.querySelector(`button[onclick="toggleAI('${a}')"]`);
        if (!btn) return;
        if (floorHolder === 'none') {
            btn.style.opacity = activeAIs[a] ? '1' : '0.4';
        } else {
            btn.style.opacity = (a === floorHolder) ? '1' : '0.2';
        }
    });
    }
    document.getElementById('turnDot').className = `turn-dot ${currentTurn}`;

    if (currentTurn === 'human') {
        document.getElementById('turnText').textContent = "Your turn";
        document.getElementById('messageInput').disabled = false;
        document.getElementById('sendBtn').disabled = false;
        if (previousTurn !== 'human') {
            setTimeout(() => document.getElementById('messageInput').focus(), 50);
        }
    } else {
        document.getElementById('turnText').textContent = `${currentTurn.charAt(0).toUpperCase() + currentTurn.slice(1)} is thinking...`;
        document.getElementById('messageInput').disabled = true;
        document.getElementById('sendBtn').disabled = true;
        document.getElementById('shutupBtn').disabled = false;
    }
    previousTurn = currentTurn;
}

async function sendMessage() {
    const input = document.getElementById('messageInput');
    const message = input.value.trim();
    if (!message || isProcessing) return;

    isProcessing = true;
    document.getElementById('sendBtn').disabled = true;
    document.getElementById('sendBtn').textContent = '...';

    try {
        // Send currently-viewing file context to AI
        let contextStr = '';
        if (currentFile && currentProject) {
            contextStr = `Currently viewing: ${currentFile}\n\n${editor ? editor.getValue().substring(0, 2000) : ''}`;
        }

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('session_id', sessionId);
        formData.append('message', message);

        if (contextStr) {
            formData.append('pinned_context', contextStr);
        }
        if (pendingImageData) {
            formData.append('attached_image', pendingImageData);
        }

        const res = await fetch('braintrust_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            input.value = '';
            removeImageAttachment();

            // NOTE: Floor is NOT reset when sending message
            // This allows setting floor -> giving instructions -> AI executes multiple turns
            // Floor only resets when user manually changes dropdown

            if (data.streaming) {
                // v3: ai_manager is handling the AI responses asynchronously.
                // Streaming bubbles will appear via the ai_manager WebSocket (port 8085).
                // Final loadSession() will be triggered by ws_server refresh when turn completes.
                // Just load once now to show the human message.
                await loadSession();
            } else {
                // v2 fallback: full response already in DB, just refresh
                await loadSession();
            }
        } else {
            alert(data.error);
        }
    } catch (err) {
        console.error(err);
        alert('Failed to send message');
    } finally {
        isProcessing = false;
        document.getElementById('sendBtn').disabled = false;
        document.getElementById('sendBtn').textContent = 'Send ➤';
    }
}

async function startAICollab() {
    const btn = document.getElementById('collabBtn');
    const shutupBtn = document.getElementById('shutupBtn');
    const input = document.getElementById('messageInput');
    const message = input.value.trim();

    btn.disabled = true;
    btn.textContent = '🤝 AIs talking...';
    btn.style.opacity = '0.6';
    shutupBtn.disabled = false;
    document.getElementById('sendBtn').disabled = true;

    try {
        // If there's a message typed, send it first before activating collab
        if (message) {
            let contextStr = '';
            if (currentFile && currentProject) {
                contextStr = `Currently viewing: ${currentFile}\n\n${editor ? editor.getValue().substring(0, 2000) : ''}`;
            }
            const msgForm = new FormData();
            msgForm.append('action', 'send_message');
            msgForm.append('session_id', sessionId);
            msgForm.append('message', message);
            if (contextStr) msgForm.append('pinned_context', contextStr);
            if (pendingImageData) msgForm.append('attached_image', pendingImageData);

            const res = await fetch('braintrust_api.php', { method: 'POST', body: msgForm });
            const data = await res.json();
            if (data.success) {
                input.value = '';
                removeImageAttachment();
            } else {
                alert(data.error);
                return;
            }
        }

        const formData = new FormData();
        formData.append('action', 'ai_collab');
        formData.append('session_id', sessionId);
        await fetch('braintrust_api.php', { method: 'POST', body: formData });
    } catch (err) {
        console.error('AI Collab error:', err);
    } finally {
        btn.disabled = false;
        btn.textContent = '🤝 AI Collab';
        btn.style.opacity = '1';
        document.getElementById('sendBtn').disabled = false;
        document.getElementById('sendBtn').textContent = 'Send ➤';
        await loadSession();
    }
}

async function shutUp() {
    const formData = new FormData();
    formData.append('action', 'shut_up');
    formData.append('session_id', sessionId);
    await fetch('braintrust_api.php', { method: 'POST', body: formData });
    document.getElementById('shutupBtn').disabled = true;
    await loadSession();
}

function toggleAI(ai) {
    activeAIs[ai] = !activeAIs[ai];
    const btn = event.currentTarget;
    btn.style.opacity = activeAIs[ai] ? '1' : '0.4';
    
    fetch('braintrust_api.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'toggle_ai',
            session_id: sessionId,
            ai: ai,
            enabled: activeAIs[ai] ? 1 : 0
        })
    });
}
function setFloor(ai) {
    // Update button brightness
    ['claude', 'gemini'].forEach(a => {
        const btn = document.querySelector(`button[onclick="toggleAI('${a}')"]`);
        if (!btn) return;
        if (ai === 'none') {
            // No floor - restore normal opacity based on activeAIs
            btn.style.opacity = activeAIs[a] ? '1' : '0.4';
        } else {
            // Someone has floor - brighten them, dim others
            btn.style.opacity = (a === ai) ? '1' : '0.2';
        }
    });

    // Send to backend
    fetch('braintrust_api.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'set_floor',
            session_id: sessionId,
            ai: ai
        })
    });
}
// ============== MODEL PICKER ==============
function showModelPicker(event, provider) {
    event.preventDefault();
    event.stopPropagation();

    const menu = document.getElementById('modelPickerMenu');
    const models = availableModels[provider];
    const currentModel = selectedModels[provider];

    const providerName = provider.charAt(0).toUpperCase() + provider.slice(1);
    let html = `<div class="context-menu-header">${providerName} Models</div>`;
    html += '<div class="context-menu-divider"></div>';

    models.forEach(model => {
        const isActive = model.id === currentModel;
        html += `<div class="context-menu-item${isActive ? ' active' : ''}" onclick="selectModel('${provider}', '${model.id}', '${model.label}')">
            ${model.label} <span class="model-desc">${model.desc}</span>
        </div>`;
    });

    // CLI Monitor option
    html += '<div class="context-menu-divider"></div>';
    html += `<div class="context-menu-item" onclick="showCLIMonitor('${provider}')">&#x1F5A5;&#xFE0F; View CLI</div>`;
    html += `<div class="context-menu-item" onclick="showAIOptions('${provider}')">&#x2699;&#xFE0F; AI Options</div>`;

    menu.innerHTML = html;
    menu.style.display = 'block';
    menu.style.left = event.pageX + 'px';
    menu.style.top = event.pageY + 'px';

    // Keep menu on screen
    const rect = menu.getBoundingClientRect();
    if (rect.right > window.innerWidth) menu.style.left = (event.pageX - rect.width) + 'px';
    if (rect.bottom > window.innerHeight) menu.style.top = (event.pageY - rect.height) + 'px';
}

function selectModel(provider, modelId, modelLabel) {
    selectedModels[provider] = modelId;

    // Update button label
    const labelEl = document.getElementById(provider + 'ModelLabel');
    if (labelEl) labelEl.textContent = modelLabel;

    // Hide menu
    document.getElementById('modelPickerMenu').style.display = 'none';

    // Persist to backend
    fetch('braintrust_api.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'set_model',
            session_id: sessionId,
            provider: provider,
            model: modelId
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            const name = provider.charAt(0).toUpperCase() + provider.slice(1);
            showToast(`${name} model set to ${modelLabel}`);
        }
    });
}

// ============== CLI MONITOR ==============
function showCLIMonitor(provider) {
    // Close the model picker menu
    document.getElementById('modelPickerMenu').style.display = 'none';

    cliMonitorProvider = provider;
    cliMonitorOffset = 0;

    const providerName = provider.charAt(0).toUpperCase() + provider.slice(1);
    document.getElementById('cliMonitorTitle').textContent = providerName + ' CLI Monitor';
    document.getElementById('cliMonitorLog').innerHTML = '';
    document.getElementById('cliMonitorStatus').className = 'cli-monitor-status idle';
    document.getElementById('cliMonitorStatus').innerHTML = '<span class="cli-monitor-dot"></span> Idle';

    document.getElementById('cliMonitorModal').style.display = 'block';

    // Start polling
    if (cliMonitorInterval) clearInterval(cliMonitorInterval);
    pollCliLog(); // Initial fetch
    cliMonitorInterval = setInterval(pollCliLog, 500);
}

async function pollCliLog() {
    if (!cliMonitorProvider) return;

    try {
        const url = `braintrust_api.php?action=get_cli_log&session_id=${sessionId}&provider=${cliMonitorProvider}&offset=${cliMonitorOffset}`;
        const res = await fetch(url);
        const data = await res.json();

        if (!data.success) return;

        // Update running status
        const statusEl = document.getElementById('cliMonitorStatus');
        if (data.running) {
            statusEl.className = 'cli-monitor-status running';
            statusEl.innerHTML = '<span class="cli-monitor-dot"></span> Running';
        } else {
            statusEl.className = 'cli-monitor-status idle';
            statusEl.innerHTML = '<span class="cli-monitor-dot"></span> Idle';
        }

        // Update offset for next poll
        cliMonitorOffset = data.offset;

        // If log file was reset (new invocation), clear the display
        if (data.reset) {
            document.getElementById('cliMonitorLog').innerHTML = '';
        }

        // Append new log content
        if (data.log) {
            const logEl = document.getElementById('cliMonitorLog');
            const lines = data.log.split('\n');

            for (const line of lines) {
                if (!line.trim()) continue;
                const entry = document.createElement('div');
                entry.className = 'cli-monitor-entry';
                entry.innerHTML = formatCliLogLine(line);
                logEl.appendChild(entry);
            }

            // Auto-scroll to bottom
            logEl.scrollTop = logEl.scrollHeight;
        }
    } catch (err) {
        console.error('CLI Monitor poll error:', err);
    }
}

function formatCliLogLine(line) {
    const match = line.match(/^\[(\d{2}:\d{2}:\d{2})\]\s*\[(\w+)\]\s*(.*)/);
    if (!match) {
        return `<span class="cli-event-text">${escapeHtml(line)}</span>`;
    }

    const [, time, type, message] = match;
    const typeClasses = {
        'system':   'cli-event-system',
        'thinking': 'cli-event-text',
        'tool':     'cli-event-tool',
        'result':   'cli-event-result',
        'complete': 'cli-event-complete',
        'error':    'cli-event-error'
    };

    const cls = typeClasses[type] || 'cli-event-text';
    return `<span class="cli-event-time">[${time}]</span> <span class="${cls}">[${type.toUpperCase()}] ${escapeHtml(message)}</span>`;
}

function closeCliMonitor() {
    if (cliMonitorInterval) {
        clearInterval(cliMonitorInterval);
        cliMonitorInterval = null;
    }
    cliMonitorProvider = null;
    document.getElementById('cliMonitorModal').style.display = 'none';
}

function clearCliMonitor() {
    cliMonitorOffset = 0;
    document.getElementById('cliMonitorLog').innerHTML = '';
}

function uploadFileToProject() {
    if (!currentProject) return alert('Select a project first');
    const input = document.getElementById('fileUploadInput');
    input.value = ''; // Reset so same file can be re-uploaded
    input.onchange = async () => {
        const files = input.files;
        if (!files.length) return;

        for (const file of files) {
            const formData = new FormData();
            formData.append('action', 'upload_file');
            formData.append('project', currentProject);
            formData.append('file', file);
            // Upload to currently open directory, or project root
            const dir = currentFile ? currentFile.substring(0, currentFile.lastIndexOf('/')) : '';
            formData.append('directory', dir);

            try {
                const res = await fetch('files_api.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showToast(`Uploaded: ${file.name}`);
                    if (files.length === 1) openFile(data.path);
                } else {
                    alert(`Upload failed: ${data.error}`);
                }
            } catch (err) {
                alert(`Upload error: ${err.message}`);
            }
        }
        refreshFileTree();
    };
    input.click();
}

function exportChat() {
    document.getElementById('exportModal').style.display = 'block';
}

function doExport() {
    const sender = document.getElementById('exportSender').value;
    const dateFrom = document.getElementById('exportDateFrom').value;
    const dateTo = document.getElementById('exportDateTo').value;
    const excludeSummaries = document.getElementById('exportExcludeSummaries').checked ? '1' : '0';

    let url = `braintrust_api.php?action=export_filtered&session_id=${sessionId}`;
    url += `&sender=${encodeURIComponent(sender)}`;
    if (dateFrom) url += `&date_from=${dateFrom}`;
    if (dateTo) url += `&date_to=${dateTo}`;
    url += `&exclude_summaries=${excludeSummaries}`;

    window.open(url, '_blank');
    closeModal('exportModal');
}

// ============== WHITEBOARD ==============
// Store clear ID in localStorage so it survives page refresh
function getWhiteboardClearKey() {
    return `whiteboardClearedId_${sessionId || 'default'}`;
}

function getWhiteboardClearedId() {
    const val = localStorage.getItem(getWhiteboardClearKey());
    return val ? parseInt(val, 10) : 0;
}

function setWhiteboardClearedId(id) {
    localStorage.setItem(getWhiteboardClearKey(), id.toString());
}

// Track the latest message ID seen to know what to "clear up to"
let currentMaxMessageId = 0;

// Track which messages we've already auto-opened files from
let processedReadFileMessages = new Set();

// Track if this is the first render (to skip auto-opening old files on page load)
let isFirstRender = true;

function clearWhiteboard() {
    currentDiagram = null;
    currentDiagramSource = null;
    whiteboardBtn.classList.remove('has-diagram');
    // Still track cleared ID for message-based logic
    setWhiteboardClearedId(currentMaxMessageId);
}

function renderMessages(messages) {
    let html = '';
    let latestDiagram = null;
    let shouldClearWhiteboard = false;
    let latestCreateFileId = null;
    const clearedId = getWhiteboardClearedId();

    // Track max ID
    messages.forEach(msg => {
        if (msg.id > currentMaxMessageId) currentMaxMessageId = msg.id;
    });

    messages.forEach(msg => {
        let displayText = msg.message_text;
        
        // Check for whiteboard clear command from AI
        const clearRegex = /\[CLEAR[_\s]?WHITEBOARD\]|\[clearboard\]/gi;
        if (clearRegex.test(msg.message_text)) {
            shouldClearWhiteboard = true;
            displayText = displayText.replace(clearRegex, '<div class="diagram-pill">🗑️ Whiteboard cleared</div>');
        }
        
        // Check for mermaid diagrams
        const mermaidRegex = /```mermaid\s*([\s\S]*?)```/gi;
        const match = msg.message_text.match(mermaidRegex);

        if (match) {
            const codeMatch = msg.message_text.match(/```mermaid\s*([\s\S]*?)```/i);
            if (codeMatch) {
                // ID-based check: Only show if message ID is > clearedId
                if (msg.id > clearedId) {
                    latestDiagram = codeMatch[1].trim();
                }
            }
            displayText = displayText.replace(mermaidRegex, '<div class="diagram-pill">📊 Diagram sent to whiteboard</div>');
        }

        // CREATE_FILE: file is written server-side; explorer auto-refreshes — no pill needed
        // Check for RUN_FILE commands
        // Match: RUN_FILE: followed by an optional interpreter ("python ", "php ") then a filename with extension
        const runFileRegex = /RUN_FILE:\s*(?:python3?\s+|php\s+|node\s+)?([^\s\n*`'"]+\.\w+)/i;
        if (runFileRegex.test(msg.message_text)) {
            const rfMatch = msg.message_text.match(runFileRegex);
            let fileToRun = rfMatch[1].trim().replace(/[\*`'"]/g, '');
            const senderPerms = aiPermissions[msg.sender_type];
            if (senderPerms && !senderPerms.can_run) {
                displayText = displayText.replace(/RUN_FILE:\s*(.+)/i, `<div class="action-pill" style="opacity:0.5;cursor:not-allowed;" title="Run Code permission disabled for ${msg.sender_type}">🚫 Execute ${fileToRun} (blocked)</div>`);
            } else {
                displayText = displayText.replace(/RUN_FILE:\s*(.+)/i, `<div class="action-pill" onclick="executeFile('${fileToRun}')">▶️ Execute ${fileToRun}</div>`);
            }
        }
        // Check for READ_FILE commands - support multiple (for floor mode)
        // Only auto-open files from NEW messages to prevent re-opening on every poll
        const readFileRegex = /^[ \t]*READ_FILE:\s*`?([^\s\n`,;]+)`?/gim;
        let readMatch;
        while ((readMatch = readFileRegex.exec(msg.message_text)) !== null) {
            const fileToRead = readMatch[1];

            // Skip auto-opening on first render (page load) to avoid errors with old files
            // Only auto-open if we haven't processed this message before AND it's not first render
            if (!isFirstRender && !processedReadFileMessages.has(msg.id)) {
                openFile(fileToRead);
                processedReadFileMessages.add(msg.id);
            } else if (isFirstRender) {
                // Mark as processed so we don't try to open it later
                processedReadFileMessages.add(msg.id);
            }

            displayText = displayText.replace(readMatch[0],
                `<div class="action-pill">📂 Reading ${fileToRead}</div>`);
        }
        // Check for preview URLs and make them clickable
        const previewUrlRegex = /(http:\/\/[^\s]+preview\.php[^\s]+)/g;
        if (previewUrlRegex.test(displayText)) {
            displayText = displayText.replace(previewUrlRegex, '<div class="action-pill" onclick="window.open(\'$1\', \'_blank\')">🌐 Open Preview</div>');
        }
        html += createMessageHTML({...msg, message_text: displayText});
    });

    // Capture scroll position BEFORE replacing content
    const container = document.getElementById('chatMessages');
    const wasNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;

    container.innerHTML = html;

    // Mark first render as complete
    if (isFirstRender) {
        isFirstRender = false;
    }

    // Clear whiteboard if commanded (before drawing new diagram)
    // Note: AI clear commands are tricky with ID logic, we might need to handle them differently later
    // For now, we assume AI clear commands just clear the visual state
    if (shouldClearWhiteboard && !latestDiagram) {
        // clearWhiteboard(); // Don't call this, it would reset the ID! Just visual clear.
        clearWhiteboard();
    }

    // Draw latest diagram if present
    if (latestDiagram) renderDiagramToWhiteboard(latestDiagram);

    // Pulse only the latest diff pill
    if (latestCreateFileId) {
        const latestPill = document.querySelector(`.diff-pill[data-msg-id="${latestCreateFileId}"]`);
        if (latestPill) latestPill.classList.add('diff-pill-pulse');
    }

    // Trigger Prism Highlighting
    if (window.Prism) {
        try {
            Prism.highlightAllUnder(document.getElementById('chatMessages'));
        } catch (err) {
            console.log('Prism highlighting failed:', err);
        // Not critical, just skip syntax highlighting
        }
    }

    // Smart Scroll: Only scroll to bottom if user was already near the bottom
    if (wasNearBottom) {
        container.scrollTop = container.scrollHeight;
    }

}

function renderDiagramToWhiteboard(code) {
    code = code.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '').trim();
    
    // Only update if this is actually a NEW diagram
    if (currentDiagramSource === code) {
        return; // Already have this diagram, don't re-render or re-pulse
    }
    
    currentDiagramSource = code;
    
    // Render to a temp div to get the SVG
    const tempDiv = document.createElement('div');
    const id = 'mermaid-temp-' + Date.now();
    tempDiv.innerHTML = `<div id="${id}" class="mermaid">${code}</div>`;
    document.body.appendChild(tempDiv);
    
    mermaid.run({ nodes: [document.getElementById(id)] }).then(() => {
        // Force dark theme on rendered SVG by stripping inline light colors
        if (!document.getElementById(id)) { document.body.removeChild(tempDiv); return; }
        const svgEl = document.getElementById(id).querySelector('svg');
        if (svgEl) {
            svgEl.querySelectorAll('[fill]').forEach(el => {
                const fill = el.getAttribute('fill').toLowerCase();
                if (fill === '#ffffff' || fill === 'white' || fill === '#fff' || fill === '#f9f9f9' || fill === '#eee' || fill === '#ececff' || fill === '#e8e8e8' || fill === 'rgb(255, 255, 255)' || fill === '#fafafa' || fill === 'none' || fill.match(/^#[def][def][def]/i)) {
                    el.setAttribute('fill', '#2d2d3f');
                }
            });
            svgEl.querySelectorAll('[stroke]').forEach(el => {
                const stroke = el.getAttribute('stroke').toLowerCase();
                if (stroke === '#ffffff' || stroke === 'white' || stroke === '#fff' || stroke === '#ccc' || stroke === '#d3d3d3') {
                    el.setAttribute('stroke', '#6272a4');
                }
            });
            svgEl.querySelectorAll('text, tspan').forEach(el => {
                const fill = (el.getAttribute('fill') || '').toLowerCase();
                if (fill === '#000' || fill === '#000000' || fill === 'black' || fill === '#333' || fill === '#555') {
                    el.setAttribute('fill', '#e0e0e0');
                }
            });
            // Also fix inline styles
            svgEl.querySelectorAll('[style]').forEach(el => {
                el.style.cssText = el.style.cssText
                    .replace(/fill:\s*#(?:fff(?:fff)?|f9f9f9|eee|ececff|e8e8e8|fafafa)\b/gi, 'fill: #2d2d3f')
                    .replace(/fill:\s*white\b/gi, 'fill: #2d2d3f')
                    .replace(/color:\s*#(?:000(?:000)?|333|555)\b/gi, 'color: #e0e0e0')
                    .replace(/color:\s*black\b/gi, 'color: #e0e0e0');
            });
        }
        const renderedSvg = document.getElementById(id).innerHTML;
        currentDiagram = renderedSvg;
        whiteboardBtn.classList.add('has-diagram');
        document.body.removeChild(tempDiv);
    }).catch(err => {
        console.error('Mermaid render failed:', err);
        if (tempDiv.parentNode) document.body.removeChild(tempDiv);
    });
}

function showWhiteboardModal() {
    const modal = document.getElementById('whiteboardModal');
    const content = document.getElementById('whiteboardContent');
    
    if (currentDiagram) {
        content.innerHTML = currentDiagram;
    } else {
        content.innerHTML = '<div class="whiteboard-empty">No diagrams generated yet.</div>';
    }
    
    modal.style.display = 'block';
    whiteboardBtn.classList.remove('has-diagram');
}

// ============== UTILITIES ==============
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

let terminalPanelVisible = false;

function toggleTerminalPanel() {
    terminalPanelVisible = !terminalPanelVisible;
    const btn        = document.getElementById('terminalToggleBtn');
    const editorView = document.getElementById('editorView');
    const termPanel  = document.getElementById('terminalPanel');

    if (terminalPanelVisible) {
        editorView.style.display = 'none';
        termPanel.style.display  = 'flex';
        btn.textContent = '📄 File Editor';
        // Small delay so panel is visible before xterm measures the container
        setTimeout(initXterm, 50);
    } else {
        termPanel.style.display  = 'none';
        editorView.style.display = 'flex';
        btn.textContent = '🖥️ Terminal';
        // Re-layout Monaco if a file is open
        if (typeof editor !== 'undefined' && editor) editor.layout();
    }
}

// ============== TERMINAL (xterm.js + real PTY) ==============
let term = null;
let termFitAddon = null;
let termSocket = null;
let termResizeObserver = null;

function initXterm() {
    const container = document.getElementById('xtermContainer');
    if (!container) return;

    // Already running — just focus and refit
    if (term) {
        if (termFitAddon) termFitAddon.fit();
        term.focus();
        return;
    }

    term = new Terminal({
        theme: {
            background: '#0d0d0d',
            foreground: '#e0e0e0',
            cursor: '#00ff00',
            cursorAccent: '#0d0d0d',
            selectionBackground: '#264f78',
        },
        fontFamily: '"JetBrains Mono", "Cascadia Code", "Courier New", monospace',
        fontSize: 14,
        lineHeight: 1.4,
        cursorBlink: true,
        cursorStyle: 'block',
        scrollback: 5000,
        allowProposedApi: true,
    });

    termFitAddon = new FitAddon.FitAddon();
    term.loadAddon(termFitAddon);
    term.open(container);
    termFitAddon.fit();

    // Connect to PTY WebSocket server
    const wsHost = window.location.hostname;
    termSocket = new WebSocket(`ws://${wsHost}:8083`);

    termSocket.onopen = () => {
        termSocket.send(JSON.stringify({
            type: 'init',
            project: currentProject || '',
            cols: term.cols,
            rows: term.rows,
        }));
        term.focus();
    };

    termSocket.onmessage = (event) => {
        // Raw PTY output — write directly to xterm
        term.write(typeof event.data === 'string' ? event.data : new Uint8Array(event.data));
    };

    termSocket.onclose = () => {
        if (term) term.write('\r\n\x1b[33m[Disconnected from terminal server]\x1b[0m\r\n');
    };

    termSocket.onerror = () => {
        if (term) term.write('\r\n\x1b[31m[Could not connect to terminal server (port 8083). Is braintrust-terminal-ws running?]\x1b[0m\r\n');
    };

    // Raw keystrokes -> WebSocket -> PTY
    term.onData((data) => {
        if (termSocket && termSocket.readyState === WebSocket.OPEN) {
            termSocket.send(data);
        }
    });

    // Terminal resize -> notify PTY
    term.onResize(({ cols, rows }) => {
        if (termSocket && termSocket.readyState === WebSocket.OPEN) {
            termSocket.send(JSON.stringify({ type: 'resize', cols, rows }));
        }
    });

    // Refit when modal/window resizes
    termResizeObserver = new ResizeObserver(() => {
        if (termFitAddon) termFitAddon.fit();
    });
    termResizeObserver.observe(container);
}

function closeTerminalModal() {
    // Legacy stub — terminal is now a panel swap, use toggleTerminalPanel()
    if (terminalPanelVisible) toggleTerminalPanel();
}

function runQuickCommand() {
    const select = document.getElementById('quickCommands');
    const command = select.value;
    if (!command) return;

    if (termSocket && termSocket.readyState === WebSocket.OPEN) {
        termSocket.send(command + '\n');
        if (term) term.focus();
    }
    select.selectedIndex = 0;
}

function clearTerminal() {
    if (term) term.clear();
}

// ============== AI MANAGER WEBSOCKET (v3 streaming) ==============
let aiManagerWS = null;
let aiManagerReconnectTimer = null;

function initAIManagerWS() {
    if (!sessionId) return;
    const url = `ws://${window.location.hostname}:8085?session_id=${sessionId}`;
    try {
        aiManagerWS = new WebSocket(url);
    } catch(e) {
        console.log('[v3] ai_manager WebSocket unavailable — streaming disabled');
        return;
    }

    aiManagerWS.onmessage = (event) => {
        try {
            const evt = JSON.parse(event.data);
            handleAIManagerEvent(evt);
        } catch(e) { console.warn('[v3] ai_manager WS parse error:', e); }
    };

    aiManagerWS.onclose = () => {
        aiManagerWS = null;
        // Reconnect after 3s — if ai_manager restarts, streaming resumes
        aiManagerReconnectTimer = setTimeout(initAIManagerWS, 3000);
    };

    aiManagerWS.onerror = () => { aiManagerWS?.close(); };
}

function handleAIManagerEvent(evt) {
    switch (evt.type) {
        case 'start':
            showStreamingBubble(evt.provider);
            break;
        case 'chunk':
            appendStreamingChunk(evt.provider, evt.text);
            break;
        case 'complete':
            finalizeStreamingBubble(evt.provider);
            // loadSession() will be triggered by ws_server refresh when full turn ends
            break;
        case 'tool':
            // Show tool use in streaming bubble
            appendStreamingChunk(evt.provider, `\n[${evt.tool}...]\n`);
            break;
        case 'error':
            removeStreamingBubble(evt.provider);
            console.error('[v3] AI error:', evt.provider, evt.message);
            break;
    }
}

function showStreamingBubble(provider) {
    removeStreamingBubble(provider); // clear any stale bubble
    const chat = document.getElementById('chatMessages');
    if (!chat) return;

    const bubble = document.createElement('div');
    bubble.className = `message ${provider}-message streaming-bubble`;
    bubble.dataset.streamingProvider = provider;

    const senderLabel = provider === 'claude' ? '🤖 Claude' : '🟢 Gemini';
    bubble.innerHTML = `<div class="message-sender">${senderLabel}</div>`
                     + `<div class="message-content streaming-content"></div>`
                     + `<div class="streaming-cursor">▋</div>`;
    chat.appendChild(bubble);
    chat.scrollTop = chat.scrollHeight;
}

function appendStreamingChunk(provider, text) {
    const bubble = document.querySelector(`.streaming-bubble[data-streaming-provider="${provider}"]`);
    if (!bubble) { showStreamingBubble(provider); return appendStreamingChunk(provider, text); }
    const content = bubble.querySelector('.streaming-content');
    if (content) {
        content.textContent += text;
        const chat = document.getElementById('chatMessages');
        if (chat) chat.scrollTop = chat.scrollHeight;
    }
}

function finalizeStreamingBubble(provider) {
    const bubble = document.querySelector(`.streaming-bubble[data-streaming-provider="${provider}"]`);
    if (bubble) {
        bubble.querySelector('.streaming-cursor')?.remove();
        bubble.classList.add('streaming-done');
    }
}

function removeStreamingBubble(provider) {
    document.querySelectorAll(`.streaming-bubble[data-streaming-provider="${provider}"]`)
            .forEach(el => el.remove());
}

function clearAllStreamingBubbles() {
    document.querySelectorAll('.streaming-bubble').forEach(el => el.remove());
}

// ============== WEBSOCKET CONNECTION ==============
let ws = null;
let wsReconnectTimer = null;
let wsReconnectDelay = 1000;
const WS_MAX_RECONNECT_DELAY = 30000;

function connectWebSocket() {
    // Determine WebSocket URL based on current page location
    const wsHost = window.location.hostname;
    const wsUrl = `ws://${wsHost}:8081?session_id=${sessionId}`;

    try {
        ws = new WebSocket(wsUrl);
    } catch (e) {
        console.log('WebSocket not available, falling back to polling');
        startPollingFallback();
        return;
    }

    ws.onopen = () => {
        console.log('WebSocket connected');
        wsReconnectDelay = 1000; // Reset reconnect delay on success
        // Clear any fallback polling
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    };

    ws.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            if (data.type === 'refresh' || data.type === 'new_message' || data.type === 'turn_change') {
                if (!isProcessing) loadSession();
            }
        } catch (e) {
            console.log('WS message parse error:', e);
        }
    };

    ws.onclose = () => {
        console.log('WebSocket disconnected, reconnecting in', wsReconnectDelay, 'ms');
        ws = null;
        // Reconnect with exponential backoff
        wsReconnectTimer = setTimeout(() => {
            wsReconnectDelay = Math.min(wsReconnectDelay * 2, WS_MAX_RECONNECT_DELAY);
            connectWebSocket();
        }, wsReconnectDelay);
        // Start fallback polling while disconnected
        startPollingFallback();
    };

    ws.onerror = (err) => {
        console.log('WebSocket error, will reconnect');
        ws.close();
    };
}

function startPollingFallback() {
    // Only start if not already polling
    if (!pollInterval) {
        pollInterval = setInterval(() => {
            if (!isProcessing) loadSession();
        }, 8000);
    }
}

function startPolling() {
    // Try WebSocket first, fall back to polling if it fails
    connectWebSocket();
}

// Mermaid init
mermaid.initialize({
    startOnLoad: false,
    theme: 'dark',
    themeVariables: {
        primaryColor: '#2d2d3f',
        primaryTextColor: '#e0e0e0',
        primaryBorderColor: '#6272a4',
        lineColor: '#8893b0',
        secondaryColor: '#252538',
        tertiaryColor: '#1e1e2e',
        background: '#1e1e2e',
        mainBkg: '#2d2d3f',
        nodeBorder: '#6272a4',
        clusterBkg: '#252538',
        clusterBorder: '#4a4a6a',
        titleColor: '#e0e0e0',
        edgeLabelBackground: '#1e1e2e',
        nodeTextColor: '#e0e0e0',
        textColor: '#e0e0e0',
        labelTextColor: '#e0e0e0',
        actorTextColor: '#e0e0e0',
        actorBkg: '#2d2d3f',
        actorBorder: '#6272a4',
        signalColor: '#e0e0e0',
        labelBoxBkgColor: '#2d2d3f',
        noteBkgColor: '#252538',
        noteTextColor: '#e0e0e0',
        activationBkgColor: '#2d2d3f',
        sequenceNumberColor: '#e0e0e0',
        sectionBkgColor: '#2d2d3f',
        altSectionBkgColor: '#252538',
        sectionBkgColor2: '#252538',
        taskBkgColor: '#2d2d3f',
        taskTextColor: '#e0e0e0',
        activeTaskBkgColor: '#3d3d5f',
        gridColor: '#4a4a6a',
        doneTaskBkgColor: '#1e1e2e',
        taskBorderColor: '#6272a4',
        personBkg: '#2d2d3f',
        personBorder: '#6272a4'
    },
    flowchart: { useMaxWidth: false }
});

// ============== SNAPSHOT HISTORY ==============
let snapshotPreviewEditor = null;
let selectedSnapshot = null;

async function showSnapshotModal() {
    if (!currentFile) return;

    document.getElementById('snapshotModalTitle').textContent = 'History: ' + currentFile;
    document.getElementById('snapshotModal').style.display = 'block';
    document.getElementById('restoreSnapshotBtn').style.display = 'none';
    document.getElementById('snapshotPreviewLabel').textContent = 'Select a snapshot to preview';

    if (!snapshotPreviewEditor) {
        snapshotPreviewEditor = monaco.editor.create(document.getElementById('snapshotPreviewEditor'), {
            theme: 'vs-dark',
            readOnly: true,
            minimap: { enabled: false },
            fontSize: 13,
            fontFamily: "'JetBrains Mono', monospace"
        });
    }
    snapshotPreviewEditor.setValue('');

    try {
        const res = await fetch(`files_api.php?action=list_snapshots&project=${encodeURIComponent(currentProject)}&path=${encodeURIComponent(currentFile)}`);
        const data = await res.json();

        const list = document.getElementById('snapshotList');
        if (data.success && data.snapshots.length > 0) {
            list.innerHTML = data.snapshots.map(s => `
                <div class="snapshot-item" onclick="previewSnapshot('${s.filename}', '${s.date}')"
                     data-filename="${s.filename}"
                     style="padding: 10px; margin-bottom: 6px; background: var(--bg-elevated); border-radius: 6px; cursor: pointer; border: 1px solid var(--border-subtle);">
                    <div style="font-size: 13px; color: var(--text-primary);">${s.date}</div>
                    <div style="font-size: 11px; color: var(--text-muted);">${(s.size / 1024).toFixed(1)} KB</div>
                </div>
            `).join('');
        } else {
            list.innerHTML = '<div style="color: var(--text-muted); padding: 12px;">No snapshots yet. Snapshots are created automatically when AI overwrites files.</div>';
        }
    } catch (err) {
        console.error('Failed to load snapshots:', err);
    }
}

async function previewSnapshot(filename, dateStr) {
    selectedSnapshot = filename;

    document.querySelectorAll('.snapshot-item').forEach(el => {
        el.style.borderColor = el.dataset.filename === filename ? 'var(--accent-gemini)' : 'var(--border-subtle)';
    });

    document.getElementById('snapshotPreviewLabel').textContent = 'Snapshot from ' + dateStr;
    document.getElementById('restoreSnapshotBtn').style.display = 'inline-flex';

    try {
        const res = await fetch(`files_api.php?action=read_file&project=${encodeURIComponent(currentProject)}&path=${encodeURIComponent('.snapshots/' + filename)}`);
        const data = await res.json();
        if (data.success) {
            snapshotPreviewEditor.setValue(data.content);
            const ext = currentFile.split('.').pop();
            monaco.editor.setModelLanguage(snapshotPreviewEditor.getModel(), getLanguageFromExtension(ext));
        }
    } catch (err) {
        console.error('Failed to preview snapshot:', err);
    }
}

async function restoreSelectedSnapshot() {
    if (!selectedSnapshot || !currentFile) return;
    if (!confirm('Restore this snapshot? (Current version will be saved as a new snapshot first.)')) return;

    try {
        const formData = new FormData();
        formData.append('action', 'restore_snapshot');
        formData.append('project', currentProject);
        formData.append('path', currentFile);
        formData.append('snapshot', selectedSnapshot);

        const res = await fetch('files_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            closeModal('snapshotModal');
            openFile(currentFile);
            showToast('Snapshot restored successfully');
        } else {
            alert(data.error);
        }
    } catch (err) {
        alert('Failed to restore snapshot');
    }
}

// ============== ARCHITECTURE VISUALIZER ==============
async function generateProjectMap() {
    if (!currentProject) {
        alert('No project selected — open a project first.');
        return;
    }

    // Open whiteboard and show loading state
    showWhiteboardModal();
    document.getElementById('whiteboardContent').innerHTML =
        '<div style="color:#666; padding:20px; font-family: monospace;">🗺️ Scanning project files...</div>';

    try {
        const res  = await fetch(`api/generate_project_map.php?project=${encodeURIComponent(currentProject)}`);
        const data = await res.json();

        if (!data.success) {
            document.getElementById('whiteboardContent').innerHTML =
                `<div style="color:#ff6b6b; padding:20px;">Error: ${data.error || 'Unknown error'}</div>`;
            return;
        }

        if (!data.mermaid || data.file_count === 0) {
            document.getElementById('whiteboardContent').innerHTML =
                '<div style="color:#666; padding:20px;">No PHP/JS/HTML files found in this project yet.</div>';
            return;
        }

        // Force a fresh render (clear source cache so same diagram re-renders after edits)
        currentDiagramSource = null;
        renderDiagramToWhiteboard(data.mermaid.trim());

        // Give mermaid a moment to render, then update the modal content
        setTimeout(() => {
            const content = document.getElementById('whiteboardContent');
            if (currentDiagram) {
                content.innerHTML = currentDiagram;
            }
        }, 300);

    } catch (err) {
        document.getElementById('whiteboardContent').innerHTML =
            `<div style="color:#ff6b6b; padding:20px;">Error: ${err.message}</div>`;
        console.error('generateProjectMap error:', err);
    }
}

// ============== EXECUTION LOGS ==============
function showLogsModal() {
    document.getElementById('logsModal').style.display = 'block';
}

function appendLog(source, message) {
    const colors = { docker: '#9ece6a', agent: '#ffd93d', error: '#f7768e', system: '#00ffff' };
    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    executionLogs.push({ time, source, message });

    const logEl = document.getElementById('executionLog');
    if (logEl) {
        const entry = document.createElement('div');
        entry.style.marginBottom = '4px';
        entry.innerHTML = `<span style="color:#606078">[${time}]</span> <span style="color:${colors[source] || '#e0e0e0'}">[${source.toUpperCase()}]</span> ${escapeHtml(message)}`;
        logEl.appendChild(entry);
        logEl.scrollTop = logEl.scrollHeight;

        // Pulse the logs button
        const logsBtn = document.getElementById('logsBtn');
        if (logsBtn && document.getElementById('logsModal').style.display !== 'block') {
            logsBtn.classList.add('has-logs');
        }
    }
}

function clearLogs() {
    executionLogs = [];
    const logEl = document.getElementById('executionLog');
    if (logEl) logEl.innerHTML = '';
}

// ============== LAYOUT CYCLING ==============
function cycleLayout() {
    const mainContent = document.querySelector('.main-content');
    const leftPanel = document.getElementById('leftPanel');
    const chatPanel = document.getElementById('chatPanel');
    const rightPanel = document.getElementById('rightPanel');
    const resizeLeft = document.getElementById('resizeLeft');
    const resizeRight = document.getElementById('resizeRight');
    const layoutBtn = document.getElementById('layoutBtn');

    // Get current layout from localStorage, default to 0
    let currentLayout = parseInt(localStorage.getItem('braintrust_layout') || '0');

    // Cycle to next layout
    currentLayout = (currentLayout + 1) % 3;

    // Remove all children from main-content
    while (mainContent.firstChild) {
        mainContent.removeChild(mainContent.firstChild);
    }

    // Reorder based on layout
    // Layout 0: Explorer | Chat | Monaco (original)
    // Layout 1: Chat | Explorer | Monaco
    // Layout 2: Explorer | Monaco | Chat

    let layoutLabel = '';

    if (currentLayout === 0) {
        // Explorer | Chat | Monaco
        mainContent.appendChild(leftPanel);
        mainContent.appendChild(resizeLeft);
        mainContent.appendChild(chatPanel);
        mainContent.appendChild(resizeRight);
        mainContent.appendChild(rightPanel);
        layoutLabel = '📁💬📝';
    } else if (currentLayout === 1) {
        // Chat | Explorer | Monaco
        mainContent.appendChild(chatPanel);
        mainContent.appendChild(resizeLeft);
        mainContent.appendChild(leftPanel);
        mainContent.appendChild(resizeRight);
        mainContent.appendChild(rightPanel);
        layoutLabel = '💬📁📝';
    } else {
        // Explorer | Monaco | Chat
        mainContent.appendChild(leftPanel);
        mainContent.appendChild(resizeLeft);
        mainContent.appendChild(rightPanel);
        mainContent.appendChild(resizeRight);
        mainContent.appendChild(chatPanel);
        layoutLabel = '📁📝💬';
    }

    // Reset panel sizes to defaults
    leftPanel.style.width = '280px';
    leftPanel.style.flex = 'none';
    chatPanel.style.flex = '1';
    chatPanel.style.width = '';
    rightPanel.style.width = '450px';
    rightPanel.style.flex = 'none';

    // Update button to show current layout
    if (layoutBtn) {
        layoutBtn.innerHTML = layoutLabel;
        layoutBtn.title = `Current layout: ${layoutLabel}`;
    }

    // Save layout preference
    localStorage.setItem('braintrust_layout', currentLayout);

    // Trigger Monaco layout update
    if (editor) editor.layout();
    if (modalEditor) modalEditor.layout();

    // Re-initialize resize handlers for new layout
    initResizablePanels();
}

// Apply saved layout on page load
function applySavedLayout() {
    const savedLayout = parseInt(localStorage.getItem('braintrust_layout') || '0');
    const layoutBtn = document.getElementById('layoutBtn');

    let layoutLabel = '📁💬📝'; // Default

    if (savedLayout === 0) {
        // Default layout, just update button
        if (layoutBtn) {
            layoutBtn.innerHTML = layoutLabel;
            layoutBtn.title = `Current layout: ${layoutLabel}`;
        }
        return;
    }

    const mainContent = document.querySelector('.main-content');
    const leftPanel = document.getElementById('leftPanel');
    const chatPanel = document.getElementById('chatPanel');
    const rightPanel = document.getElementById('rightPanel');
    const resizeLeft = document.getElementById('resizeLeft');
    const resizeRight = document.getElementById('resizeRight');

    // Remove all children
    while (mainContent.firstChild) {
        mainContent.removeChild(mainContent.firstChild);
    }

    // Apply saved layout
    if (savedLayout === 1) {
        // Chat | Explorer | Monaco
        mainContent.appendChild(chatPanel);
        mainContent.appendChild(resizeLeft);
        mainContent.appendChild(leftPanel);
        mainContent.appendChild(resizeRight);
        mainContent.appendChild(rightPanel);
        layoutLabel = '💬📁📝';
    } else if (savedLayout === 2) {
        // Explorer | Monaco | Chat
        mainContent.appendChild(leftPanel);
        mainContent.appendChild(resizeLeft);
        mainContent.appendChild(rightPanel);
        mainContent.appendChild(resizeRight);
        mainContent.appendChild(chatPanel);
        layoutLabel = '📁📝💬';
    }

    // Reset panel sizes to defaults
    leftPanel.style.width = '280px';
    leftPanel.style.flex = 'none';
    chatPanel.style.flex = '1';
    chatPanel.style.width = '';
    rightPanel.style.width = '450px';
    rightPanel.style.flex = 'none';

    // Update button to show current layout
    if (layoutBtn) {
        layoutBtn.innerHTML = layoutLabel;
        layoutBtn.title = `Current layout: ${layoutLabel}`;
    }
}

// ============== RESIZABLE PANELS ==============
let resizeState = {
    isResizing: false,
    mode: null, // 'slide' or 'resize'
    targetPanel: null,
    explorerWidth: 280
};

function initResizablePanels() {
    const resizeLeft = document.getElementById('resizeLeft');
    const resizeRight = document.getElementById('resizeRight');
    const leftPanel = document.getElementById('leftPanel');
    const chatPanel = document.getElementById('chatPanel');
    const rightPanel = document.getElementById('rightPanel');

    if (!resizeLeft || !resizeRight) return;

    // Remove old listeners if they exist
    const newResizeLeft = resizeLeft.cloneNode(true);
    const newResizeRight = resizeRight.cloneNode(true);
    resizeLeft.parentNode.replaceChild(newResizeLeft, resizeLeft);
    resizeRight.parentNode.replaceChild(newResizeRight, resizeRight);

    // Determine current layout
    const mainContent = document.querySelector('.main-content');
    const firstPanel = mainContent.children[0];
    const thirdPanel = mainContent.children[4]; // After 2 resize handles

    let currentLayout = 0; // Default
    if (firstPanel === chatPanel) {
        currentLayout = 1; // Chat | Explorer | Monaco
    } else if (thirdPanel === chatPanel) {
        currentLayout = 2; // Explorer | Monaco | Chat
    }

    // Configure handles based on layout
    if (currentLayout === 0) {
        // Explorer | Chat | Monaco - only right handle works
        newResizeLeft.style.cursor = 'default';
        newResizeRight.style.cursor = 'col-resize';
    } else if (currentLayout === 1) {
        // Chat | Explorer | Monaco - only left handle works (slides explorer)
        newResizeLeft.style.cursor = 'col-resize';
        newResizeRight.style.cursor = 'default';
    } else {
        // Explorer | Monaco | Chat - only right handle works
        newResizeLeft.style.cursor = 'default';
        newResizeRight.style.cursor = 'col-resize';
    }

    // Left resize handle
    newResizeLeft.addEventListener('mousedown', (e) => {
        if (currentLayout !== 1) return; // Only works in Chat | Explorer | Monaco layout

        resizeState.isResizing = true;
        resizeState.mode = 'slide';
        resizeState.explorerWidth = leftPanel.offsetWidth;

        newResizeLeft.classList.add('dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    });

    // Right resize handle
    newResizeRight.addEventListener('mousedown', (e) => {
        if (currentLayout === 1) return; // Doesn't work in Chat | Explorer | Monaco layout

        resizeState.isResizing = true;
        resizeState.mode = 'resize';

        if (currentLayout === 0) {
            // Explorer | Chat | Monaco - resize Monaco
            resizeState.targetPanel = rightPanel;
        } else {
            // Explorer | Monaco | Chat - resize Monaco
            resizeState.targetPanel = rightPanel;
        }

        newResizeRight.classList.add('dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    });

    // Mouse move - resize or slide panels
    document.addEventListener('mousemove', (e) => {
        if (!resizeState.isResizing) return;

        if (resizeState.mode === 'slide' && currentLayout === 1) {
            // Chat | Explorer | Monaco: slide Explorer left/right
            const containerWidth = mainContent.offsetWidth;
            const explorerPos = e.clientX;
            const chatWidth = explorerPos;
            const monacoWidth = containerWidth - explorerPos - resizeState.explorerWidth;

            if (chatWidth > 100 && monacoWidth > 100) {
                chatPanel.style.width = chatWidth + 'px';
                chatPanel.style.flex = 'none';
                leftPanel.style.width = resizeState.explorerWidth + 'px';
                leftPanel.style.flex = 'none';
                rightPanel.style.width = monacoWidth + 'px';
                rightPanel.style.flex = 'none';
            }

        } else if (resizeState.mode === 'resize') {
            if (currentLayout === 0) {
                // Explorer | Chat | Monaco: resize Monaco from right edge
                const containerWidth = mainContent.offsetWidth;
                const newWidth = containerWidth - e.clientX;

                if (newWidth > 100) {
                    resizeState.targetPanel.style.width = newWidth + 'px';
                    resizeState.targetPanel.style.flex = 'none';
                }
            } else if (currentLayout === 2) {
                // Explorer | Monaco | Chat: resize Monaco from left edge
                const explorerWidth = leftPanel.offsetWidth;
                const newWidth = e.clientX - explorerWidth;

                if (newWidth > 100) {
                    resizeState.targetPanel.style.width = newWidth + 'px';
                    resizeState.targetPanel.style.flex = 'none';
                }
            }
        }

        // Trigger Monaco layout update
        if (editor) editor.layout();
        if (modalEditor) modalEditor.layout();
    });

    // Mouse up - stop resizing
    document.addEventListener('mouseup', () => {
        if (resizeState.isResizing) {
            const handles = document.querySelectorAll('.resize-handle');
            handles.forEach(handle => handle.classList.remove('dragging'));

            document.body.style.cursor = '';
            document.body.style.userSelect = '';

            resizeState.isResizing = false;
            resizeState.mode = null;
            resizeState.targetPanel = null;
        }
    });
}

// ============== DIFF EDITOR ==============
let diffEditor = null;
let diffTargetFile = null;
let diffNewContent = null;

function initDiffEditor() {
    if (diffEditor) return;
    
    require(['vs/editor/editor.main'], function () {
        diffEditor = monaco.editor.createDiffEditor(document.getElementById('monacoDiffEditor'), {
            originalEditable: false,
            readOnly: false,
            theme: 'braintrust-dark',
            fontSize: 13,
            fontFamily: "'JetBrains Mono', monospace",
            automaticLayout: true,
            renderSideBySide: true
        });
    });
}

async function showDiff(fileName, base64Content) {
    initDiffEditor();
    const newContent = atob(base64Content);
    diffTargetFile = fileName;
    diffNewContent = newContent;
    
    document.getElementById('diffModalTitle').textContent = `⚖️ Review Changes: ${fileName}`;
    document.getElementById('diffModal').style.display = 'block';
    
    // Fetch original content
    let originalContent = '';
    try {
        const res = await fetch(`files_api.php?action=read_file&project=${encodeURIComponent(currentProject)}&path=${encodeURIComponent(fileName)}`);
        const data = await res.json();
        if (data.success) originalContent = data.content;
    } catch (err) {
        console.log("Assuming new file");
    }

    const originalModel = monaco.editor.createModel(originalContent, getLanguageFromExtension(fileName.split('.').pop()));
    const modifiedModel = monaco.editor.createModel(newContent, getLanguageFromExtension(fileName.split('.').pop()));

    diffEditor.setModel({
        original: originalModel,
        modified: modifiedModel
    });

    document.getElementById('applyDiffBtn').onclick = () => applyDiff(fileName, newContent);
}

async function applyDiff(fileName, content) {
    try {
        const formData = new FormData();
        formData.append('action', 'write_file');
        formData.append('project', currentProject);
        formData.append('path', fileName);
        formData.append('content', content);

        const res = await fetch('files_api.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            closeModal('diffModal');
            refreshFileTree();
            openFile(fileName);
            showToast(`Changes applied to ${fileName}`);
            appendLog('system', `Diff applied: ${fileName}`);
        } else {
            alert(data.error);
        }
    } catch (err) {
        alert('Error applying changes');
    }
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => document.body.removeChild(toast), 300);
    }, 3000);
}

function getLanguageFromExtension(ext) {
    const map = {
        'php': 'php', 'js': 'javascript', 'py': 'python', 'html': 'html', 'css': 'css', 'json': 'json', 'md': 'markdown'
    };
    return map[ext] || 'plaintext';
}
// ============== DATABASE QUERY FUNCTIONS ==============
function showDatabaseModal() {
    document.getElementById('databaseModal').style.display = 'block';
    document.getElementById('sqlQuery').focus();
}

function executeQuery(confirmedDangerous = false) {
    const query = document.getElementById('sqlQuery').value.trim();
    const database = document.getElementById('dbSelector').value;
    
    if (!query) {
        document.getElementById('queryResults').innerHTML = 
            '<div style="color: #ff6b6b;">❌ Arrr! Enter a query first, ye landlubber!</div>';
        return;
    }
    
    document.getElementById('queryResults').innerHTML = 
        '<div style="color: #ffd93d;">⚓ Executing query on the high seas...</div>';
    
    fetch('api/execute_sql.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            query: query,
            database: database,
            confirmed: confirmedDangerous
        })
    })
    .then(r => r.json())
    .then(result => {
        if (result.requiresConfirmation) {
            // Show confirmation dialog for dangerous operations
            const keyword = result.keyword;
            const confirmed = confirm(
                `⚠️ DANGER AHEAD, CAPN'!\n\n` +
                `This query contains: ${keyword}\n\n` +
                `This could modify or destroy data!\n\n` +
                `Are ye SURE ye want to proceed?\n\n` +
                `Query: ${query.substring(0, 100)}${query.length > 100 ? '...' : ''}`
            );
            
            if (confirmed) {
                // Re-execute with confirmation flag
                executeQuery(true);
            } else {
                document.getElementById('queryResults').innerHTML = 
                    '<div style="color: #ffd93d;">⚓ Query cancelled by the Captain. Wise choice!</div>';
            }
        } else if (result.success) {
            displayQueryResults(result.data, result.rowCount, result.executionTime);
        } else {
            document.getElementById('queryResults').innerHTML = 
                `<div style="color: #ff6b6b;">❌ Query failed: ${result.error}</div>`;
        }
    })
    .catch(err => {
        document.getElementById('queryResults').innerHTML = 
            `<div style="color: #ff6b6b;">❌ Error: ${err.message}</div>`;
    });
}

function displayQueryResults(data, rowCount, executionTime) {
    const resultsDiv = document.getElementById('queryResults');
    
    if (!data || data.length === 0) {
        resultsDiv.innerHTML = `<div style="color: #51cf66;">✓ Query executed successfully in ${executionTime}ms. No rows returned.</div>`;
        return;
    }
    
    // Build HTML table
    const columns = Object.keys(data[0]);
    let html = `<div style="color: #51cf66; margin-bottom: 12px;">✓ ${rowCount} rows returned in ${executionTime}ms</div>`;
    html += '<table style="width: 100%; border-collapse: collapse; color: #00ff00; font-size: 12px;">';
    
    // Header
    html += '<tr style="background: #1a1a1a;">';
    columns.forEach(col => {
        html += `<th style="border: 1px solid #333; padding: 8px; text-align: left; font-weight: 600; color: #ffd93d;">${escapeHtml(col)}</th>`;
    });
    html += '</tr>';
    
    // Rows (limit display to first 1000 for performance)
    const displayRows = data.slice(0, 1000);
    displayRows.forEach((row, idx) => {
        const bgColor = idx % 2 === 0 ? '#0d0d0d' : '#141414';
        html += `<tr style="background: ${bgColor};">`;
        columns.forEach(col => {
            let value = row[col];
            if (value === null) {
                value = '<i style="color: #666;">NULL</i>';
            } else if (typeof value === 'string' && value.length > 100) {
                value = escapeHtml(value.substring(0, 100)) + '...';
            } else {
                value = escapeHtml(String(value));
            }
            html += `<td style="border: 1px solid #333; padding: 8px;">${value}</td>`;
        });
        html += '</tr>';
    });
    
    if (data.length > 1000) {
        html += `<tr><td colspan="${columns.length}" style="padding: 12px; text-align: center; color: #ffd93d; font-style: italic;">
            Showing first 1000 of ${data.length} rows
        </td></tr>`;
    }
    
    html += '</table>';
    resultsDiv.innerHTML = html;
}

function clearQueryResults() {
    document.getElementById('queryResults').innerHTML = 
        '<div style="color: #666;">⚓ Enter a query and click Execute to see results...</div>';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
// ============== DIRECTORY BROWSER ==============
let currentBrowsePath = '/var/www/html/braintrust-IDE-3/collabchat/projects';
let selectedDirectory = null;


// ============== SERVER FILE BROWSER ==============
let serverFileEditor = null;
let currentServerFile = null;
let currentServerPath = '/var/www'; // Start at /var/www

function showServerBrowser() {
    document.getElementById('serverBrowserModal').style.display = 'block';
    
    // Initialize Monaco editor for server browser if not already done
    if (!serverFileEditor) {
        require.config({ paths: { vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.45.0/min/vs' }});
        require(['vs/editor/editor.main'], function() {
            serverFileEditor = monaco.editor.create(document.getElementById('serverMonacoEditor'), {
                value: '// Select a file from the tree to edit',
                language: 'javascript',
                theme: 'vs-dark',
                automaticLayout: true,
                minimap: { enabled: false }
            });
        });
    }
    
    // Load initial directory tree
    loadServerFileTree(currentServerPath);
}

function navigateServerToParent() {
    const parts = currentServerPath.split('/').filter(p => p);
    if (parts.length > 1) {
        parts.pop();
        currentServerPath = '/' + parts.join('/');
        loadServerFileTree(currentServerPath);
    }
}

function navigateServerToHome() {
    currentServerPath = '/var/www/html/braintrust-IDE-3/collabchat';
    loadServerFileTree(currentServerPath);
}

function loadServerFileTree(path) {
    currentServerPath = path;
    document.getElementById('serverCurrentPath').textContent = path;
    
    fetch('api/browse_directory.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: path })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            renderServerFileTree(data.items, path);
        } else {
            document.getElementById('serverFileTree').innerHTML = 
                `<div style="color: #ff6b6b; padding: 1rem;">❌ ${data.error}</div>`;
        }
    });
}

function renderServerFileTree(items, basePath) {
    const tree = document.getElementById('serverFileTree');
    let html = '';
    
    items.forEach(item => {
        if (item.type === 'dir') {
            html += `
                <div style="margin: 4px 0;">
                    <div style="cursor: pointer; padding: 4px; border-radius: 3px;" 
                         onclick="toggleServerFolder(this, '${basePath}/${item.name}')"
                         onmouseover="this.style.background='var(--bg-elevated)'"
                         onmouseout="this.style.background='transparent'">
                        ▶ 📁 ${item.name}
                    </div>
                    <div style="margin-left: 16px; display: none;"></div>
                </div>`;
        } else {
            html += `
                <div style="cursor: pointer; padding: 4px; margin: 2px 0; border-radius: 3px;"
                     onclick="loadServerFile('${basePath}/${item.name}')"
                     onmouseover="this.style.background='var(--bg-elevated)'"
                     onmouseout="this.style.background='transparent'">
                    📄 ${item.name}
                </div>`;
        }
    });
    
    tree.innerHTML = html;
}

function toggleServerFolder(element, path) {
    const container = element.nextElementSibling;
    
    if (container.style.display === 'none') {
        // Expand
        element.innerHTML = element.innerHTML.replace('▶', '▼');
        container.style.display = 'block';
        
        // Load contents if empty
        if (container.innerHTML === '') {
            fetch('api/browse_directory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: path })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    let html = '';
                    data.items.forEach(item => {
                        if (item.type === 'dir') {
                            html += `
                                <div style="margin: 4px 0;">
                                    <div style="cursor: pointer; padding: 4px; border-radius: 3px;" 
                                         onclick="toggleServerFolder(this, '${path}/${item.name}')"
                                         onmouseover="this.style.background='var(--bg-elevated)'"
                                         onmouseout="this.style.background='transparent'">
                                        ▶ 📁 ${item.name}
                                    </div>
                                    <div style="margin-left: 16px; display: none;"></div>
                                </div>`;
                        } else {
                            html += `
                                <div style="cursor: pointer; padding: 4px; margin: 2px 0; border-radius: 3px;"
                                     onclick="loadServerFile('${path}/${item.name}')"
                                     onmouseover="this.style.background='var(--bg-elevated)'"
                                     onmouseout="this.style.background='transparent'">
                                    📄 ${item.name}
                                </div>`;
                        }
                    });
                    container.innerHTML = html;
                }
            });
        }
    } else {
        // Collapse
        element.innerHTML = element.innerHTML.replace('▼', '▶');
        container.style.display = 'none';
    }
}

function loadServerFile(filePath) {
    fetch('api/read_server_file.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: filePath })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            currentServerFile = filePath;
            serverFileEditor.setValue(data.content);
            
            // Detect language
            const ext = filePath.split('.').pop();
            const langMap = {
                'php': 'php',
                'js': 'javascript',
                'json': 'json',
                'css': 'css',
                'html': 'html',
                'py': 'python',
                'md': 'markdown',
                'sql': 'sql',
                'sh': 'shell'
            };
            monaco.editor.setModelLanguage(serverFileEditor.getModel(), langMap[ext] || 'plaintext');
            
            document.getElementById('serverFileName').textContent = filePath;
            document.getElementById('saveServerFileBtn').disabled = false;
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// Add save button handler to existing DOMContentLoaded
const existingSaveHandler = document.getElementById('saveServerFileBtn');
if (existingSaveHandler) {
    existingSaveHandler.addEventListener('click', function() {
        if (!currentServerFile) return;
        
        fetch('api/write_server_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                path: currentServerFile,
                content: serverFileEditor.getValue()
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ File saved!');
            } else {
                alert('❌ Error: ' + data.error);
            }
        });
    });
}
// Apply saved layout and init resize handlers on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    applySavedLayout();
    initResizablePanels();
});

// ============== AI PERMISSIONS ==============

function loadAIPermissions() {
    if (!sessionId) return;
    fetch(`braintrust_api.php?action=get_ai_permissions&session_id=${sessionId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const p = data.permissions;
            aiPermissions.claude = {
                can_write:    p.claude_can_write,
                can_delete:   p.claude_can_delete,
                can_run:      p.claude_can_run,
                can_terminal: p.claude_can_terminal,
                can_packages: p.claude_can_packages,
                lead:         p.claude_lead
            };
            aiPermissions.gemini = {
                can_write:    p.gemini_can_write,
                can_delete:   p.gemini_can_delete,
                can_run:      p.gemini_can_run,
                can_terminal: p.gemini_can_terminal,
                can_packages: p.gemini_can_packages,
                lead:         p.gemini_lead
            };
        })
        .catch(() => {});
}

function showAIOptions(provider) {
    document.getElementById('modelPickerMenu').style.display = 'none';
    const name = provider.charAt(0).toUpperCase() + provider.slice(1);
    document.getElementById('aiOptionsTitle').textContent = `⚙️ ${name} Options`;

    const perms = aiPermissions[provider] || {};

    const permDefs = [
        { key: 'can_write',    icon: '✏️', label: 'Write Files',      desc: 'Allow creating / overwriting files via CREATE_FILE protocol' },
        { key: 'can_delete',   icon: '🗑️', label: 'Delete Files',     desc: 'Allow file deletion actions' },
        { key: 'can_run',      icon: '▶️', label: 'Run Code',         desc: 'Show RUN_FILE execute buttons in chat' },
        { key: 'can_terminal', icon: '🖥️', label: 'Use Terminal',     desc: 'Permission to issue terminal/shell commands' },
        { key: 'can_packages', icon: '📦', label: 'Install Packages', desc: 'Permission to install packages or dependencies' },
        { key: 'lead',         icon: '👑', label: 'Project Lead',     desc: 'Designate as Project Lead — gets authority over decisions' },
    ];

    let html = `<div style="display:flex;flex-direction:column;gap:10px;">`;
    permDefs.forEach(def => {
        const checked = perms[def.key] ? 'checked' : '';
        html += `
        <label style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:var(--bg-input);border-radius:8px;border:1px solid var(--border-subtle);cursor:pointer;">
            <input type="checkbox" ${checked} onchange="saveAIPermission('${provider}','${def.key}',this.checked?1:0)"
                style="width:16px;height:16px;accent-color:var(--accent-claude);cursor:pointer;">
            <span style="font-size:18px;">${def.icon}</span>
            <span style="flex:1;">
                <strong style="color:var(--text-primary);font-size:14px;">${def.label}</strong>
                <span style="display:block;color:var(--text-muted);font-size:12px;margin-top:2px;">${def.desc}</span>
            </span>
        </label>`;
    });
    html += `</div>`;

    document.getElementById('aiOptionsContent').innerHTML = html;
    document.getElementById('aiOptionsModal').style.display = 'flex';
}

function saveAIPermission(provider, permission, value) {
    fetch('braintrust_api.php', {
        method: 'POST',
        body: new URLSearchParams({
            action:     'set_ai_permission',
            session_id: sessionId,
            ai:         provider,
            permission: permission,
            value:      value
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (!aiPermissions[provider]) aiPermissions[provider] = {};
            aiPermissions[provider][permission] = value;

            // Lead always assigned — mirror inverse on the other AI
            if (permission === 'lead') {
                const otherAI = provider === 'claude' ? 'gemini' : 'claude';
                const otherValue = value ? 0 : 1;
                if (aiPermissions[otherAI]) aiPermissions[otherAI].lead = otherValue;
                const otherCheckbox = document.querySelector(`#aiOptionsContent input[onchange*="${otherAI},'lead'"]`);
                if (otherCheckbox) otherCheckbox.checked = !!otherValue;
            }

            const name = provider.charAt(0).toUpperCase() + provider.slice(1);
            showToast(`${name} ${permission.replace('_',' ')} ${value ? 'enabled' : 'disabled'}`);
        } else {
            showToast('Failed to save permission: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(() => showToast('Network error saving permission'));
}