*One IDE to code them all, One Prompt to find them,*
*One Human to bring them all, and in the codebase bind them.*

# BrainTrust IDE v3: Collaboration Hub 🧠💻

BrainTrust is a specialized web-based IDE for pair/round-table collaboration between a human developer and two AI models: **Claude** and **Gemini**.

## Core Philosophy
Unlike standard AI chat interfaces, BrainTrust treats AI models as first-class collaborators in a shared workspace. It orchestrates a structured conversation where both AIs can see each other's work and build upon it, while the human maintains ultimate control.

---

## How It Operates

### Turn Order
**Human → Claude → Gemini → Repeat**
- Both AIs on by default; either can be toggled off
- **AI Collab mode:** AIs chat back and forth without human input (10-message cap, SHUT UP interrupts)
- **Floor feature:** Lock the turn to a specific AI for multi-turn tasks; persists until manually cleared

### Triple-Panel Layout
- **Explorer (Left):** File tree with creation, deletion, upload — auto-refreshes when any file is created (by AI or user). Ctrl+click for multi-select; right-click → Select All / Delete Selected (N)
- **Collaboration Chat (Center):** Turn-based discussion with syntax highlighting, action buttons, message bookmarks
- **Monaco Editor (Right):** Full VS Code editor with snapshot history, close button, diff viewer

### Layout Cycling
Three panel arrangements (📁💬📝 / 💬📁📝 / 📁📝💬), saved to localStorage, optimized for ultrawide monitors.

---

## v2 Architecture: Persistent CLI Sessions

BrainTrust v2 replaced stateless cURL API calls with persistent CLI agent sessions:

- **Claude:** `claude -p --resume <uuid> "message" --output-format stream-json --verbose --dangerously-skip-permissions`
- **Gemini:** `gemini -r latest -p "message" -o json -y --model <selected>`

**First message:** CLI invoked with system prompt; returns session UUID stored in DB.
**Subsequent messages:** PHP invokes CLI with `--resume <uuid>` — only the new message is sent; full history lives in the CLI agent's memory.
**Fallback:** If CLI fails, retries up to 3x with exponential backoff (2/4/8s), then falls back to v1 stateless API calls automatically.

### What v1 Band-Aids Are Gone (CLI agents remember natively)

| Removed System | Was Used For |
|---|---|
| Decisions extraction (Haiku) | Remember past choices |
| Pulse file (.braintrust_state.md) | Project state snapshot |
| Auto-summary system | Compress old messages |
| Pinned file context injection | Ensure AI sees key files |
| Smart context suggestions | Suggest files to pin |
| 50-message SQL limit | Control context size |

All v1 code still runs for the API fallback path. ChatGPT removed entirely in v2.

### CLI/API Status Dots
Each AI toggle button shows:
- **Green dot** = CLI mode (persistent memory active)
- **Orange dot** = API fallback (stateless)

---

## Features

### Core
- **Docker Sandbox Execution:** Python (3.11-slim) runs in Docker sandbox. PHP and HTML/HTM files open as live browser previews served directly by Apache (full PHP execution, DB access via shared dev config).
- **WebSocket Real-Time:** Node.js ws server (port 8081 client / 8082 internal notify), falls back to 8s polling
- **Terminal:** xterm.js real PTY terminal (same engine as VS Code) — true bash, tab completion, command history persists per-project across sessions, streaming output, full ANSI color
- **Server File Browser:** Monaco-powered editor for any file on the server (safe base paths: /var/www, /home, /etc, /usr/local)

### AI Workflow
- **Model Switcher:** Right-click AI toggle button → select model. Claude (Sonnet 4.6 / Opus 4.6), Gemini (2.5 Flash / 2.5 Pro). Switching model resets CLI session UUID.
- **CLI Monitor:** Right-click AI toggle button → "View CLI" → real-time streaming terminal showing agent tool calls, thinking, results, cost. 500ms polling, color-coded events.
- **AI Protocols:** AIs use `CREATE_FILE:`, `READ_FILE:`, `RUN_FILE:` text commands; system processes them automatically. `READ_FILE` auto-loops — file contents injected back to same AI without advancing turn; AI may request multiple files before passing the turn. SHUT UP cancels the loop.
- **Diff Review:** Side-by-side Monaco Diff Editor for reviewing AI-suggested file changes before applying.

### Editor & Files
- **Snapshot/Rollback:** Auto-snapshot before any AI file overwrite; 20-snapshot cap per file; restore previews in read-only Monaco.
- **Upload:** Multi-file upload directly into project; auto-directory creation.
- **Whiteboard:** Mermaid.js diagrams via modal; Save/Print/Clear; Generate Map button; dark theme enforcement (3-layer: system prompt rule + themeVariables + post-render SVG cleanup).

### Image Canvas
- **Canvas Modal:** 🖼️ Canvas button opens a full-screen drawing/annotation workspace.
- **Paste Support:** Ctrl+V any screenshot directly onto the canvas.
- **Drawing Tools:** Freehand pen ✏️, rectangle ⬜, circle ⭕, text overlay T — with color picker, stroke width, and font size controls. Keyboard shortcuts: P/R/C/T.
- **Save to Project:** 💾 Save to Project button prompts for a filename and writes the canvas as a JPEG to the session's project folder.
- **AI Visibility:** AIs read the saved image file directly via CLI (`read xxxx.jpg`) — full persistent context, no API detour, no fragmented memory.

### Chat
- **Bookmarks:** 🔖 icon appears on hover for any message; bookmarked messages saved to DB and viewable in the Bookmarks modal with sender, timestamp, and preview. Persists across page reloads.
- **Export:** Filtered export (by sender, date range, exclude summaries) with metadata header.
- **Shut Up Button:** Immediately interrupts AI turn cycle.

### GitHub Integration
- **🐙 GitHub button** in top bar — context-aware, three modes:
- **New repo wizard:** Repo name (defaults to project name), public/private toggle, file list with checkboxes for `.gitignore` (common patterns pre-checked), commit message. Creates repo on GitHub via API, inits git, writes `.gitignore`, commits everything, pushes — all in one click.
- **Clone existing repo:** Tab in the wizard for projects with no git history. Paste a `https://github.com/...` URL, clones into `/tmp` first then copies into project folder (handles existing files cleanly), full git history preserved.
- **Commit & push:** For existing repos, shows `git status` with changed/untracked files as checkboxes, commit message field, pushes to origin. Includes 📥 Pull button.
- Token and username stored in `/var/www/secure_config/braintrust_config.php` (never committed).

---

## Technical Stack
- **Backend:** Vanilla PHP 8.2
- **Frontend:** JavaScript ES6+, Monaco Editor, Mermaid.js, Prism.js
- **APIs:** Anthropic (Claude), Google (Gemini)
- **CLIs:** Claude Code CLI, Gemini CLI
- **WebSocket:** Node.js + ws library, systemd service (`braintrust-ws.service`)
- **Execution:** Docker (python:3.11-slim, php:8.2-cli)
- **Server:** HP DL380p Gen8 "Skynet", Ubuntu 24, Apache 2.4.58, local IP 192.168.1.143
- **GitHub:** https://github.com/Xenobones/Braintrust-IDE-V3

## Security & Sandboxing
All file operations sandboxed to `projects/` directory. Path validation prevents traversal attacks. Terminal blocklist for dangerous commands.

Docker containers: read-only mounts, `--network none`, 256MB RAM, 0.5 CPU, 50 process limit, 30s timeout.

**Shared Dev DB Config:** `/var/www/secure_config/dev_db_config.php` — defines `DEV_DB_HOST/USER/PASS/NAME`. New project READMEs instruct AIs to `require_once` this file for DB connections. Credentials never appear in project files.

---

## AI Collaborator Personalities

Both AIs are generalist collaborators — no rigid role assignments. Shannon orchestrates their focus via conversation.

**Claude (Sonnet 4.6 / Opus 4.6):** Verbose, explains reasoning, strong on architecture and system design.

**Gemini (2.5 Flash / 2.5 Pro):** Thorough, detailed, good at verification and documentation.

Both active by default. Toggle either off for solo sessions. Roles are fluid — Shannon drives, AIs follow.

---

## Known Issues
- Gemini CLI Monitor only shows basic status (invoked/done) — stream-json investigation pending
- PHP web app preview opens directly via Apache (no auth gate on the projects URL) — acceptable for home server, worth revisiting if exposed externally

## Maintenance Notes
- **Claude binary path:** Both `braintrust_api.php` and the ai_manager (Node.js) expect the `claude` CLI at `/usr/bin/claude`. Claude auto-updates to `/home/shannon/.local/bin/claude` — www-data cannot execute from `/home/shannon/` (EACCES). If Claude stops responding ("Claude is thinking..." forever), check the CLI Monitor for ENOENT or EACCES errors. Fix after any Claude update: `sudo cp /home/shannon/.local/bin/claude /usr/local/bin/claude && sudo chmod 755 /usr/local/bin/claude`. The symlink `/usr/bin/claude → /usr/local/bin/claude` should persist; only the copy needs refreshing on updates.

---

*Created by Shannon Hensley - BrainTrust IDE 2026*
*Full maintenance history: BT_Maintenance_log.md*
