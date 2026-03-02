# BrainTrust IDE — Maintenance Log

Full history of all development sessions, bug fixes, and feature additions.
See `braintrust.md` for the current state overview.

---

## February 26, 2026 (Afternoon) — AI Permissions System & Infrastructure Fix

### AI Permissions System
Per-AI permission controls that persist per project/session. Accessed by right-clicking any Claude or Gemini badge → **⚙️ AI Options**.

**Permissions per AI:**
- ✏️ **Write Files** — allow/block CREATE_FILE protocol (server-side enforced)
- 🗑️ **Delete Files** — behavioral restriction injected into system prompt
- ▶️ **Run Code** — show/hide RUN_FILE execute pill in chat
- 🖥️ **Use Terminal** — behavioral restriction injected into system prompt
- 📦 **Install Packages** — behavioral restriction injected into system prompt
- 👑 **Project Lead** — designates AI as lead with final say on architecture/direction

**Technical Implementation:**
*   **DB:** 12 new columns on `braintrust_turn_state` (`claude_can_write`, `claude_can_delete`, `claude_can_run`, `claude_can_terminal`, `claude_can_packages`, `claude_lead`, + gemini equivalents). Plus `claude_perms_notify` / `gemini_perms_notify` one-shot notification flags.
*   **Server-side enforcement:** `processAIToolCalls()` checks `{ai}_can_write` before writing any file. If blocked, inserts a `⛔ File write blocked` system message.
*   **RUN_FILE pill:** Client-side check against `aiPermissions[sender].can_run` — blocked pills render dimmed/non-clickable.
*   **System prompt injection:** `buildPermissionsReminder()` helper prepends a compact status header to the next AI turn after a permission change (one-shot via notify flag), then goes silent. Zero overhead on normal turns.
*   **Project Lead mutual exclusivity:** Crowning one AI auto-dethrones the other — both DB and UI update simultaneously.
*   **Permission change notifications:** Every toggle posts a descriptive system message to chat so AIs don't make stale assumptions.
*   **New API actions:** `get_ai_permissions`, `set_ai_permission`

### Infrastructure Fix: Claude Binary Path (ENOENT / EACCES)
*   **Problem:** Claude auto-updated to `/home/shannon/.local/bin/claude`. Both `braintrust_api.php` and the ai_manager (Node.js) expect `/usr/bin/claude`. www-data cannot execute from `/home/shannon/` (EACCES).
*   **Symptom:** "Claude is thinking..." forever; CLI Monitor shows `spawn /usr/bin/claude ENOENT` or `EACCES`.
*   **Fix:** `sudo cp /home/shannon/.local/bin/claude /usr/local/bin/claude && sudo chmod 755 /usr/local/bin/claude` then symlink: `sudo ln -sf /usr/local/bin/claude /usr/bin/claude`.
*   **Ongoing:** Repeat the copy step after each Claude auto-update. Symlink persists.

**Files Modified:** `collabchat/braintrust_api.php`, `collabchat/braintrust_logic.js`, `collabchat/braintrust.php`, `collabchat/braintrust_style.css`, `braintrust.md`

---

## February 26, 2026 — Explorer Multi-Select & GitHub Clone

### Explorer Multi-File Selection
*   **Ctrl+Click Multi-Select:** Ctrl+click (Cmd+click on Mac) toggles individual files in/out of a multi-selection. Visual highlight maintained across selection.
*   **Select All Files:** Right-click context menu → ☑️ Select All Files — selects every file in the current project explorer in one click.
*   **Batch Delete:** When 2+ items are selected, right-click reveals 🗑️ Delete Selected (N). Single confirmation prompt, sequential API deletes, tree refresh on completion.
*   **Smart Context Menu:** Right-clicking an item already in the multi-selection keeps the full selection intact. Right-clicking outside the selection resets to single-item mode — Rename/Delete/Copy Path still work normally.
*   **Selection Cleared on Refresh:** `selectedItems` Set is cleared whenever `refreshFileTree()` rebuilds the DOM.

### GitHub Clone Existing Repo
*   **📥 Clone Existing Tab:** GitHub modal wizard now has two tabs for projects with no git history — ✨ New Repo (unchanged) and 📥 Clone Existing.
*   **Clone by URL:** Paste any `https://github.com/...` URL. Credentials (token + username) are injected automatically for private repos.
*   **Temp-Dir Strategy:** Clones into `/tmp/bt_clone_<uniqid>` first, then `cp -a` into the project folder — cleanly handles the auto-generated `README.md` without git's empty-directory restriction.
*   **Token Scrubbing:** GitHub token is stripped from any error output before it's returned to the browser.
*   **Post-Clone State:** Branch name returned and displayed in the status badge; user prompted to hit Explorer Refresh to see cloned files.

---

## February 9, 2026 - Major Feature Update
*   **Whiteboard Relocation:** Moved whiteboard from persistent bottom-left panel to on-demand modal with button in top bar. Reduces UI clutter while maintaining full diagram functionality.
*   **Whiteboard Save/Print/Clear:** Added three-button control system for whiteboard modal - Save (creates timestamped .mmd and .svg files in `/whiteboards/` folder), Print (opens print dialog), and Clear (with confirmation prompt).
*   **Orange Pulse Notifications:** Whiteboard button pulses orange when new diagrams are generated, clearing after modal is opened.
*   **Smart Diagram Rendering:** Prevents re-rendering of duplicate diagrams during polling, eliminating unnecessary orange pulses.
*   **Docker Python Execution:** Implemented fully sandboxed Python script execution via Docker containers with resource limits and security restrictions.
*   **Docker PHP Execution:** Extended Docker execution system to support PHP scripts for backend logic testing (token generation, email formatting, etc.).
*   **HTML Preview System:** Added simple preview system for HTML files - generates clickable preview buttons that open rendered pages in authenticated browser sessions.
*   **Multi-Language Code Execution:** Unified execution system (`run_code.php`) that routes Python, PHP, and HTML files to appropriate handlers based on file extension.
*   **Clickable Action Buttons:** Preview URLs and execution commands now generate interactive green buttons instead of plain text links.
*   **UTF-8 Safe Base64 Encoding:** Fixed unicode character handling in diff viewer by implementing proper UTF-8 encoding before base64 conversion.
*   **Git Button Removal:** Removed unused Git integration button to reduce UI clutter.
*   **AI Stats Button Removal:** Decided against token tracking feature to keep interface lean.
*   **Gemini 2.0 Flash Upgrade:** Updated from gemini-2.5-flash to gemini-2.0-flash-exp-0827.

---

## February 6, 2026
*   **Persistent AI Toggling:** Implemented database-backed flags (`claude_enabled`, `gemini_enabled`) that allow users to mute specific AIs. The turn manager now actively skips muted AIs.
*   **Smart Whiteboard Clearing:** Migrated from timestamp-based clearing to a robust **Message ID-based threshold**. Prevents "ghost diagrams" from reappearing during chat polling.
*   **Smart Scroll:** Updated the chat interface to only auto-scroll if the user is already at the bottom.
*   **Project Creation Fix:** Resolved a database constraint error where `turn_order` was missing during initial member insertion.
*   **API Stability:** Fixed an undefined variable bug (`$system_prompt`) in the Claude communication logic.
*   **AI Tool Auto-Execution:** Enabled backend parsing of AI responses for `CREATE_FILE:` commands. System now automatically creates or updates files based on AI instructions.
*   **Visual Diff Editor:** Added a side-by-side Diff Editor (powered by Monaco) for reviewing AI-suggested code changes before applying.
*   **Syntax Highlighting:** Integrated Prism.js for rich syntax highlighting in chat code blocks.
*   **Human-to-Human Collaboration:** Implemented a secure "Invite" system allowing project owners to add other human collaborators by username.
*   **Collaborative Permissions:** Updated session validation logic to support multi-user access.
*   **Polling Optimization:** Increased chat polling interval to 8 seconds to improve server stability and prevent API rate-limiting (429 errors).

---

## February 10, 2026 (Late Night/Early Morning Marathon Session)

### Terminal Quick Commands & SSH Routing
*   **Quick Command Dropdown:** Added comprehensive dropdown menu in terminal modal with 30+ pre-configured server management commands.
*   **SSH Command Routing:** Implemented automatic SSH routing for privileged commands — sudo commands transparently routed through SSH as admin user (shannon@localhost) with passwordless authentication via ED25519 key pairs.
*   **Passwordless Sudo Configuration:** Configured sudoers to allow admin user full passwordless sudo access for BrainTrust terminal operations.
*   **Quick Command Bypass System:** Added `quick_command` flag that allows pre-approved dropdown commands to bypass terminal security blocklist.
*   **Terminal Layout Redesign:** Restructured terminal modal with two-row header for improved usability.
*   **Terminal Input Auto-Focus:** Fixed terminal input field initialization and focus management.

### Database Query Runner Enhancements
*   **Full SQL Command Support:** Removed read-only restrictions — now supports CREATE, DROP, DELETE, INSERT, UPDATE, ALTER with confirmation dialogs for destructive operations.
*   **Smart Confirmation System:** Dangerous operations trigger JavaScript confirmation dialogs before execution.
*   **Word Boundary Detection:** Improved keyword detection using regex word boundaries to prevent false positives.

### Bug Fixes & Stability
*   **Terminal Command Execution:** Fixed `executeTerminalCommand()` to properly accept command parameter from quick command dropdown.
*   **Prism.js Error Handling:** Wrapped syntax highlighting in try-catch to prevent PHP tokenization errors from breaking chat rendering.
*   **UTF-8 Safe Base64 Encoding:** Fixed unicode character handling in diff viewer.
*   **Terminal Output Element:** Ensured terminalOutput div exists in DOM structure.
*   **Layout Collision Fix:** Resolved terminal input field being obscured by overlapping elements.

### Documentation & Maintenance
*   **Updated PROJECT_STANDARDS.md:** Created comprehensive 300+ line development standards document.
*   **Server Naming Clarification:** "Skynet" is the production server name, "Frankenfritter" is the affectionate nickname.

### Security Enhancements
*   **SSH Key Authentication:** Established passwordless SSH from www-data to admin user using ED25519 key pairs stored in `/var/www/.ssh/`.
*   **Sudoers Configuration:** Created `/etc/sudoers.d/braintrust` file with proper permissions (0440).

---

## February 10, 2026 — Panel Layout Cycling System

*   **Layout Cycling Button:** Added emoji-based layout button (📁💬📝) in top bar that cycles through three different panel arrangements.
*   **Three Layout Modes:** Default balanced / Chat-focused (ultrawide) / Code-focused.
*   **Intelligent Resize Behavior:** Each layout has custom resize logic tailored to its purpose.
*   **Persistent Preferences:** Layout choice saved to localStorage and restored on page load.
*   **Unrestricted Resizing:** Removed CSS min-width/max-width constraints for full flexibility on ultrawide displays.

---

## February 10, 2026 (Afternoon) — Server File Browser

*   **Server File Browser Modal:** Added comprehensive server-wide file browser accessible via 📁 Explorer button.
*   **Two-Panel Interface:** Left (300px collapsible directory tree) + Right (full Monaco editor instance).
*   **Smart Navigation:** ⬆️ Parent, 🏠 Home buttons; current path display.
*   **File Operations:** Click folders to expand/collapse; click files to open; 💾 Save button writes directly to server.
*   **Language Detection:** Automatic syntax highlighting based on file extension.
*   **Security Boundaries:** File browsing restricted to safe base paths (`/var/www`, `/home`, `/etc`, `/usr/local`).
*   **Separate Monaco Instance:** `serverFileEditor` independent of main project editor.
*   **API Endpoints:** `api/browse_directory.php`, `api/read_server_file.php`, `api/write_server_file.php`.

---

## February 11, 2026 — Three-AI Collaboration & Final Polish

### ChatGPT Integration (Third AI Collaborator)
*   **OpenAI API Integration:** Added ChatGPT (GPT-4o) as third AI collaborator.
*   **Turn Order Expansion:** Human → Claude → ChatGPT → Gemini → Repeat.
*   **Dedicated Context Builder:** Created `buildConversationContextForChatGPT()`.
*   **Database Schema Updates:** Added `chatgpt_enabled` column to `braintrust_turn_state`.

### Living Context Pulse System
*   **Auto-Generated State File:** `.braintrust_state.md` updates automatically after every AI turn.
*   **Comprehensive State Capture:** Current mission, recent activity (last 20 messages), project file listing, active AI status.

### GitHub Integration & Backup
*   **Repository Creation:** Backed up entire BrainTrust IDE to GitHub (https://github.com/Xenobones/Braintrust-IDE).
*   **Privacy-Preserving Commits:** Configured Git to use GitHub no-reply email address.

### Terminal Quick Commands — Production Ready
*   **Direct Sudo Execution:** Eliminated complex SSH routing in favor of direct sudo execution.
*   **Expanded Command Permissions:** Updated `/etc/sudoers.d/braintrust` to include service management, package operations, Docker commands.
*   **Crash Prevention:** Special handling for Apache restart commands to send HTTP response before restarting.
*   **Output Buffering Fix:** Switched from `exec()` to `passthru()` for more reliable command output capture.

### Bug Fixes
*   **ChatGPT Echo Bug:** Fixed context builder issue where ChatGPT would repeat Claude's messages verbatim.
*   **Pulse File Path Resolution:** Corrected `getProjectPath()` to convert relative paths to absolute paths.
*   **Terminal API Permissions:** Resolved www-data SSH key permission issues by switching to direct sudo execution model.

**Total Development Time:** Entire BrainTrust IDE built in 3 days (Feb 9-11, 2026) while managing six healthcare facilities.

---

## February 12, 2026 — The "Power User" Upgrade Phase

### Autonomous Task Agent (Feature #1)
*   **Delegation Mode:** Added a gold "⚡ New Task" button to delegate complex, multi-step engineering tasks to an autonomous background worker.
*   **Recursive Thinking Loop:** `agent_hub.php` allows Claude to operate in a thought-action-result loop (READ, WRITE, RUN, LIST).
*   **Self-Correcting Logic:** Agent can run its own code in Docker sandbox, see errors, and rewrite files to fix them.
*   **Safety Constraints:** Hard-coded 15-step execution limit per task.

### Architecture Visualizer (Feature #2)
*   **Living Project Map:** `api/generate_project_map.php` scans project for PHP includes and JS fetches.
*   **Mermaid Integration:** Generates `.PROJECT_MAP.md` file providing a visual roadmap of application file connections.

### Smart Diff & Approval Workflow (Feature #3)
*   **Pulsing Review System:** AI code suggestions become a prominent, blue-pulsing "🔍 Review Proposed Changes" button.
*   **Approval-Gate Security:** AI can no longer silently overwrite files; changes must be reviewed via Monaco Diff Editor.

### Context Pinning (Feature #4)
*   **Persistent File Focus:** Pin buttons on Explorer files; pinned files turn gold and are automatically included in AI context.
*   **High-Priority Context:** Ensures AI never "forgets" core logic even as conversation grows long.

### Real-time Execution Logs (Feature #5)
*   **Dedicated Console Panel:** "🖥️ Logs" modal captures and streams real-time output from Docker execution engine.
*   **Unified Debugging:** Replaces small alert boxes with a proper, scrollable, timestamped developer console.

### What Claude Built/Fixed (via Claude Code CLI)
*   Built complete Task Agent Modal, `launchAgent()` async loop, fixed `agent_hub.php` RUN_FILE for real Docker execution.
*   Added "Map" button to top bar, wired frontend to render `.PROJECT_MAP.md` to whiteboard.
*   Added blue pulsing CSS on latest CREATE_FILE review pill, green toast notification on successful diff apply.
*   Built pin button system with gold highlighting and localStorage persistence.
*   Built Logs Modal with unified `appendLog()` function, color coding: Docker=green, Agent=gold, Error=red, System=cyan.

**Files Modified:** `braintrust.php`, `braintrust_logic.js` (~250 new lines), `braintrust_style.css` (~120 new lines), `api/agent_hub.php`, `braintrust_api.php`.

---

## February 12, 2026 (Night) — WebSocket Real-Time Communication & Bug Fix Marathon

### WebSocket Architecture (Replacing 8-Second Polling)
*   **Node.js WebSocket Server:** Created `ws_server.js` using the `ws` library with dual-port architecture:
    *   **Port 8081 (Client-facing):** WebSocket connections from browser clients, organized into session-based rooms.
    *   **Port 8082 (Internal only):** HTTP notification endpoint bound to `127.0.0.1` — PHP backend POSTs here to trigger real-time pushes.
*   **Session Rooms:** Clients join rooms by `session_id` on connect.
*   **Heartbeat System:** 30-second ping/pong cycle detects and terminates dead connections.
*   **Systemd Service:** Created `braintrust-ws.service` for auto-start on boot, runs as `www-data` user.
*   **Firewall Configuration:** Port 8081 opened via UFW; port 8082 intentionally left blocked (internal localhost only).

### Automatic Fallback to Polling
*   **Graceful Degradation:** If WebSocket connection fails, automatically falls back to 8-second polling.
*   **Exponential Backoff Reconnection:** Reconnect attempts start at 1s and double up to 30s max.

### PHP Backend WebSocket Integration
*   **`notifyWebSocket()` Helper:** Lightweight cURL POST to internal notify endpoint with 500ms timeout (fire-and-forget).
*   **Notification Triggers:** On human message saved, AI message saved, turn passed, Shut Up pressed.

### Critical Bug Fix: json_encode Silent Failures
*   **Root Cause:** `json_encode()` silently returning `false` when conversation context contained invalid UTF-8 characters.
*   **Impact:** All three AIs were failing with HTTP 400 errors.
*   **Fix:** Added `JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE` flags to all 4 `json_encode()` calls in the API pipeline.

### Other Fixes
*   **generate_project_map.php Rewrite:** Gemini had written the file with corrupted smart quotes breaking all PHP regex patterns.
*   **Server Browser Modal Fix:** Missing `</div>` tag was causing Task Agent and Logs modals to be nested inside a hidden parent.
*   **Removed Unreliable Auto-Map Trigger:** Auto-triggering via `file_get_contents('http://localhost/...')` was unreliable; map now generates on-demand only.

**Files Created:** `ws_server.js`, `braintrust-ws.service`

---

## February 12, 2026 (Late Night) — UI Cleanup: Map & Whiteboard Consolidation

*   **Map Button Log Spam Fix:** Removed `appendLog()` call from `generateProjectMap()` — user-initiated actions don't need background notifications.
*   **Map & Whiteboard Modal Merge:** Consolidated Map functionality into the Whiteboard modal. Removed standalone Map button from top bar; added "🗺️ Generate Map" button inside Whiteboard modal header.

**Files Modified:** `braintrust.php`, `braintrust_logic.js`

---

## February 13, 2026 — Bug Fixes, Terminal Hardening & Upload Feature (Claude Code Session)

### Sandbox Path Validation Fix
*   **Bug:** `CREATE_FILE` commands failed with "Invalid path - sandbox protection!" when target directory didn't exist yet.
*   **Fix:** Updated `validatePath()` to walk up the directory tree until finding an existing ancestor, then validates that ancestor is within the sandbox.

### Project Directory Ownership Fix
*   **Issue:** File writes failed with "Failed to write file" because `projects/` directory tree was owned by `root` instead of `www-data`.
*   **Fix:** `sudo chown -R www-data:www-data` on the projects root directory.

### Terminal Frontend Hardening
*   **Bug:** Terminal modal appeared to "do nothing" when commands were entered.
*   **Root Cause:** `initTerminal()` had no null check on input element; `removeEventListener()` threw on null and silently killed the function.
*   **Fixes:** Added null check with 200ms retry; `res.ok` check for HTTP errors; parse response as text then JSON; refocus input after each command.

### Upload File Button (Replaces Include File)
*   **Solution:** Repurposed "Include File" into "Upload File" for uploading files from user's computer directly into project.
*   **Frontend:** Hidden `<input type="file" multiple>`, `uploadFileToProject()` function, multi-file support, auto-refreshes file tree.
*   **Backend:** New `upload_file` action with `bt_uploadFile()` using `move_uploaded_file()`, full sandbox validation.

### Chat Auto-Scroll Fix
*   **Bug:** Scroll bar moved up instead of following new content on AI responses.
*   **Root Cause:** `isNearBottom` calculated AFTER `innerHTML` replaced chat content, which reset scroll position.
*   **Fix:** Moved `wasNearBottom` calculation BEFORE `innerHTML` replacement.

**Files Modified:** `files_api.php`, `braintrust_logic.js`, `braintrust.php`

---

## February 16, 2026 — Security Audit & Cleanup (Claude Code Session)

*   **findings.md Created:** Full audit report covering all 43 files — 41 findings across Critical/High/Medium/Low.
*   **Auth Gates:** Added `session_start()` + auth checks to `add_user.php`, `get_schema.php`, `test_chatgpt.php`, `api/generate_project_map.php`.
*   **Broken Logout Fix:** Added `session_start()` before `session_destroy()` in both logout.php files.
*   **Chat XSS Cleanup:** Added `escapeHtml()` in code block and inline code replacements in `formatMessage()`.
*   **SQL Query Runner:** Added server-side dangerous keyword detection to `execute_sql.php`.
*   **Error Display:** Turned off `display_errors` in `process_login.php`.

**Files Created:** `findings.md`

---

## February 16, 2026 — Model Switcher & Token Limit Upgrade (Claude Code Session)

### Right-Click Model Switcher on AI Toggle Buttons
*   **On-the-Fly Model Switching:** Right-clicking any AI toggle button opens a context menu with available models.
*   **Available Models:**
    *   Claude: Sonnet 4 (balanced), Opus 4 (most capable), Haiku 4.5 (fast/cheap)
    *   ChatGPT: GPT-4o (flagship), GPT-4o-mini (fast/cheap), o1 (reasoning), o3-mini (fast reasoning)
    *   Gemini: 2.5 Flash (fast), 2.5 Pro (most capable), 2.0 Flash (previous gen)
*   **Visual Indicator:** Active model name shown as small label under each AI button.
*   **Database Persistence:** Model selection stored per session in `braintrust_turn_state` (new columns: `claude_model`, `chatgpt_model`, `gemini_model`).
*   **Server-Side Whitelist Validation:** `setModel()` validates provider and model against hardcoded whitelist.
*   **Reasoning Model Support:** Special API handling for OpenAI o1/o3-mini — omits `temperature`, uses `max_completion_tokens`.

### Token Limit Increase
*   **Bumped `max_tokens` from 2048 to 12288** for all three AIs — 6x more room for code generation.

### Pulse File Cleanup
*   Removed duplicate pinned context from `.braintrust_state.md` — was repeating context already sent in system prompt.
*   Fixed Gemini status bug — always showed "Active" regardless of actual state (copy/paste typo in ternary).

### Toggle Button Click Fix
*   **Fixed `event.target` vs `event.currentTarget`** in `toggleAI()` — clicking model label text inside button only dimmed the span instead of the whole button.

### First Git Workflow Session
*   Shannon learned Git basics and completed first feature branch commit + push workflow via SSH to Skynet.

**Files Modified:** `braintrust_api.php`, `braintrust.php`, `braintrust_logic.js`, `braintrust_style.css`, `sql_schema.txt`
**Files Created:** `migrations/add_model_columns.sql`

---

## February 17, 2026 — Bug Fixes, UX Improvements & 3 New Features (Claude Code Session)

### Bug Fixes

**Multi-User Turn Advancement Fix**
*   **Bug:** Invited collaborators' messages appeared in chat but didn't trigger AI responses.
*   **Root Cause:** `advanceTurn()` enforced strict sequential human ordering — invited user message advanced to "human order 2" instead of to Claude.
*   **Fix:** Removed sequential human ordering. Any human message now advances directly to the first enabled AI.

**Claude API Error Visibility**
*   **Fix:** Updated error handler in `getClaudeResponse()` to surface actual HTTP status code and Anthropic error message in chat.

**ChatGPT Avatar Label**
*   **Bug:** ChatGPT messages showed "SYS" as avatar — falling through to default case in ternary chain.
*   **Fix:** Added explicit `chatgpt` check in `createMessageHTML()` — now displays "CGPT".

### UX Improvements
*   **Whiteboard Modal Scrolling:** Added `overflow: auto` to modal body; large diagrams now scroll.
*   **Mermaid Dark Theme Override:** Added CSS overrides forcing dark backgrounds and light text on all whiteboard SVGs.
*   **Close File Button:** Added "Close" button in editor header; `closeFile()` resets editor state with unsaved changes warning.
*   **Model Lineup Update:** Added Claude Sonnet 4.6 and Opus 4.6; removed Haiku 4.5.

### Feature 1: Rollback/Snapshot System
*   **Auto-Snapshots:** Before any AI-triggered file overwrite, current content auto-saved to `.snapshots/` inside project folder.
*   **Auto-Pruning:** Maximum 20 snapshots kept per file.
*   **History Button:** Appears in editor header when file is open.
*   **Snapshot Modal:** Two-panel layout — snapshot list (left) + read-only Monaco preview (right). Click to preview, "Restore This Version" to revert.
*   **Undoable Restores:** Restoring a snapshot saves current version as new snapshot first.
*   **Hidden from Explorer:** `.snapshots/` directory filtered from file tree.
*   **Backend:** `createFileSnapshot()` in `braintrust_api.php`, `list_snapshots` and `restore_snapshot` actions in `files_api.php`.

### Feature 2: Enhanced Chat Export
*   **Export Modal:** Filter options — by sender, date range, exclude summaries.
*   **Metadata Header:** Exported markdown includes filter settings and message count.
*   **Backend:** New `exportFiltered()` function with dynamic SQL query building.

### Feature 3: Smart Context Suggestions
*   **File Reference Scanner:** Scans last 10 AI messages for file references after each render.
*   **Suggestion Bar:** Gold-tinted bar shows suggested files as clickable chips. Pin, "Pin All", or Dismiss.
*   **Dismissal Persistence:** Dismissed suggestions saved to localStorage per session.

**Files Modified:** `braintrust_api.php`, `files_api.php`, `braintrust_logic.js`, `braintrust.php`, `braintrust_style.css`

---

## February 21, 2026 — Durable Memory System & Adaptive Summaries (Claude Code Session)

### Decisions Extraction — Permanent AI Memory
*   **New Module:** Created `collabchat/api/extract_decisions.php` — after every AI response, extracts durable decisions using Claude Haiku (~$0.00005 per call).
*   **Persistent Storage:** Decisions saved to `.braintrust_decisions.json` in each project directory.
*   **Categories:** architecture, naming, library, pattern, constraint, schema, style, api, security, config.
*   **Deduplication:** Word-overlap algorithm (60% threshold) prevents duplicate decisions.
*   **50-Decision Cap:** Oldest unpinned decisions drop off when limit reached.
*   **System Prompt Injection:** All AIs receive full decisions list as `### PROJECT DECISIONS (permanent memory)` section.
*   **Fault-Tolerant:** If Haiku API call fails, extraction silently skips.

### Summary Surfacing Fix
*   **Bug:** Auto-summaries were filtered out of Claude and ChatGPT context (`AND is_summary = 0`) and never surfaced anywhere.
*   **Fix:** New `getLatestSummary()` injects most recent auto-summary into all AIs' system prompts as `### CONVERSATION HISTORY SUMMARY`.

### Adaptive Summary Trigger
*   **Old:** Every 20 messages regardless of content length.
*   **New:** Triggers when unsummarized messages exceed ~8,000 estimated tokens OR 30 messages (safety net).

### Pinned Decisions
*   **Pinned Field:** New decisions include `"pinned": false` by default. Pinned decisions never evicted.
*   **Smart Eviction:** Cap logic separates pinned from unpinned.
*   **Visual Indicators:** Pinned decisions show `[PINNED]` prefix in AI prompts and pin emoji in pulse file.

### Decisions Manager Modal
*   **Memory Button:** Added "🧠 Memory" button to top bar.
*   **Decision Cards:** Color-coded category badge, fact text, relative timestamp, pin toggle, delete button.
*   **Sorted Display:** Pinned first (gold left border), then unpinned newest first.
*   **Live Count:** Header shows total and pinned count.

**Files Created:** `collabchat/api/extract_decisions.php`
**Files Modified:** `braintrust_api.php`, `braintrust.php`, `braintrust_logic.js`, `braintrust_style.php`, `files_api.php`

---

## February 22, 2026 — BrainTrust IDE v2: Hybrid Upgrade

**Hybrid IDE upgrade implemented by Claude Opus 4.6 via Claude Code CLI.**

### Core Architecture Change
Replaced stateless cURL API calls with persistent CLI agent sessions. PHP shells out to Claude Code CLI and Gemini CLI with `--resume` for persistent conversation history.

*Discovery:* Both CLIs support sequential invocations with `--resume`, eliminating the need for a process manager. PHP shells out directly — no Node.js middleman needed.

### Key Technical Details
```
# Claude — First message
claude -p "message" --output-format json --model <selected> --append-system-prompt "<protocols>" --dangerously-skip-permissions

# Claude — Follow-up
claude -p --resume <uuid> "message" --output-format json --model <selected> --dangerously-skip-permissions

# Gemini — First message
gemini -p "message" -o json -y --model <selected>

# Gemini — Follow-up
gemini -r latest -p "message" -o json -y --model <selected>
```

### Database Migration
*   Added `claude_cli_session_uuid` VARCHAR(36) to `braintrust_turn_state`.
*   Added `gemini_cli_session_uuid` VARCHAR(36) to `braintrust_turn_state`.

### New Functions in braintrust_api.php
*   `executeCLIAgent()` — proc_open-based CLI runner with 180s timeout, JSON parsing, env var passthrough.
*   `buildCLISystemPrompt()` — Lean system prompt with BrainTrust protocols only.
*   `getLatestHumanMessage()` — Fetches just the newest human message.
*   `getClaudeResponseCLI()` — Claude Code CLI invocation with session resume and UUID storage.
*   `getGeminiResponseCLI()` — Gemini CLI invocation with session resume, runs in project directory.

**Server Setup:** Created `/var/www/.claude/` and `/var/www/.gemini/` directories (www-data owned). API keys passed as environment variables (not command-line args — invisible to `ps`).

**Performance:** ~7-9s for Claude, ~5-7s for Gemini. Dramatic input token reduction on follow-up messages. Claude Code CLI leverages prompt caching automatically — resumed sessions show 95%+ cache read rates.

**Files modified:** `collabchat/braintrust_api.php` (5 new functions, 3 modified)
**Frontend changes:** Zero — UI doesn't care how the backend gets AI responses.

---

## February 22, 2026 — Post-Launch Fixes

### Multi-Party Conversation Awareness Fix
*   **Bug:** CLI agents couldn't see each other's messages.
*   **Root Cause:** `getLatestHumanMessage()` only sent the latest human message; messages from other AIs were never included.
*   **Fix:** Replaced with `getMessagesSinceLastResponse()` — queries all messages since this AI's last response (humans AND other AIs) with sender labels: `[Gemini]: ...`, `[Shannon]: ...`.

### AI Collab Mode
*   **Replaces:** The broken "Pass Turn" button (advanced turn but never triggered AI response).
*   **New Button:** "AI Collab" — lets enabled AIs chat back and forth without human input.
*   **Turn Loop:** Backend loops through enabled AIs (Claude → ChatGPT → Gemini → repeat), saving each response to DB and firing WebSocket notifications.
*   **10-Message Cap:** Loop stops after 10 AI messages.
*   **SHUT UP Interrupt:** Breaks the loop between AI turns.
*   **System Messages:** Announces when collab starts and ends.

### Real-Time Message Display Fix (AI Collab)
*   **Bug:** During AI Collab, messages didn't appear until entire session ended.
*   **Root Cause:** PHP session file locking — `session_start()` locked the file, queuing all browser polling requests behind it.
*   **Fix:** Added `session_write_close()` at start of `aiCollab()` to release session lock before entering loop.

### CREATE_FILE Markdown Stripping
*   **Bug:** Files created via `CREATE_FILE:` had `**` appended to filenames (AI used markdown bold formatting).
*   **Fix:** Added `trim($rel_path, '*\`\'"')` in `processAIToolCalls()`. Added equivalent JS-side stripping for `RUN_FILE:` commands.

### Mermaid Diagram Dark Theme Enforcement
*   **Fix (three layers):**
    1. **System prompt rule:** "NEVER add custom colors or style lines in diagrams."
    2. **Mermaid config:** Expanded `mermaid.initialize()` with comprehensive `themeVariables` forcing dark values before rendering.
    3. **Post-render cleanup:** JS scans all SVG elements and replaces remaining light fills/strokes/text, including inline `style` attributes.

### www-data Permission Fixes for Claude CLI
*   **Bug:** Claude CLI hung for 30+ seconds on first invocation, then failed.
*   **Root Cause:** CLI couldn't write `/var/www/.claude.json` (permission denied) and timed out waiting for remote settings.
*   **Fix:** Created `/var/www/.claude.json` and `/var/www/.claude/remote-settings.json` owned by www-data. Response time dropped from ~37s to ~7s.

**Files Modified:** `braintrust_api.php`, `braintrust.php`, `braintrust_logic.js`, `braintrust_style.css`, `collabchat/api/run_code.php`

---

## February 23, 2026 — ChatGPT Removal, Cleanup & Polish (Claude Code Session)

### ChatGPT Excommunicado
With Claude and Gemini on persistent CLI sessions, ChatGPT's backup role was no longer needed.

**Backend Removals (`braintrust_api.php`, ~210 lines deleted):**
*   `getChatGPTResponse()` — Entire function deleted.
*   `buildConversationContextForChatGPT()` — Deleted.
*   ChatGPT blocks removed from `sendMessage()`, `aiCollab()`, `advanceTurn()`, `toggleAI()`, `setModel()`.
*   New sessions now initialize with `claude_enabled=1, gemini_enabled=1` (both on by default).

**Frontend Removals:**
*   `braintrust.php` — Removed ChatGPT toggle button, floor dropdown option, export filter option.
*   `braintrust_logic.js` — Removed all ChatGPT state, models, avatar handling.

### Auto-Summary & Decisions Extraction Deactivated
*   Removed `checkAndGenerateSummary()` and `extractDecisions()` calls from `sendMessage()`.
*   Modules kept dormant — `extract_decisions.php` still exists, decisions modal GUI still reads `.braintrust_decisions.json`. Just stopped calling them automatically.
*   **Cost impact:** Haiku token costs → near zero. OpenAI API costs → zero.

### Exponential Backoff Retry Logic
*   Added to both `getClaudeResponseCLI()` and `getGeminiResponseCLI()` — retries up to 3x with 2/4/8s waits before falling back to API.

### Claude Model ID Fix
*   **Bug:** `claude-sonnet-4-6-20250219` and `claude-opus-4-6-20250219` returned HTTP 404.
*   **Fix:** Correct IDs are `claude-sonnet-4-6` and `claude-opus-4-6` (no date suffix).

### Dead Gemini Model Removal
*   Google discontinued `gemini-2.0-flash`. Removed from backend whitelist and frontend model picker. Gemini now defaults to 2.5 Flash with 2.5 Pro as upgrade option.

### CLI/API Status Dots on AI Toggle Buttons
*   **Green dot** = CLI mode (persistent memory via `--resume`)
*   **Orange dot** = API mode (stateless fallback)
*   Checks for `claude_cli_session_uuid` / `gemini_cli_session_uuid` in turn state.

**Net Result:** ~236 lines deleted, ~29 lines added across 4 files.

---

## February 23, 2026 (Continued) — V1 Remnant Cleanup: The Great Mopping

### Document Pinning — Entire Feature Removed
*   **Why it's gone:** CLI agents have native file system access and persistent memory — they can `READ_FILE` whenever needed.
*   **Removed:** Pin buttons, gold highlighting, `pinnedFiles` localStorage, `togglePin()`, `savePinnedFiles()`, `updatePinnedIndicator()`, `getPinnedContext()`, all `.pin-btn` and `.tree-item.pinned` CSS.

### Memory Button + Decisions Modal — Entire Feature Removed
*   **Why it's gone:** Automatic extraction was already turned off. The JSON file was frozen/stale. Memory button was just viewing dead data.
*   **Removed:** Memory button, decisions modal HTML, all JS functions and handlers, all `.decision-*` CSS, `get_decisions` and `update_decisions` actions from `files_api.php`.

### Smart Context Suggestions — Entire Feature Removed
*   **Why it's gone:** Existed solely to support the pinning feature. No pinning = no suggestions.
*   **Removed:** Suggestion bar HTML, `scanMessagesForFileReferences()`, `updateContextSuggestions()`, `pinAllSuggested()`, `dismissSuggestions()`, all `.context-suggestions` and `.suggestion-chip` CSS.

### Pulse File System — Removed
*   **Why it's gone:** CLI agents maintain their own memory. Pulse file was being written after every AI response for nothing.
*   **Removed:** `updateProjectPulse()` function (~95 lines), all 3 calls to it, pulse content injection from API fallback system prompts.

### Auto-Summary Functions — Removed
*   **Removed:** `checkAndGenerateSummary()` (~27 lines), `generateSummary()` (~80 lines), `getLatestSummary()`, `{$latestSummary}` injection from API fallback prompts.

### API Fallback System Prompt Cleanup
*   Stripped `{$pulseContent}`, `{$decisionsContext}`, and `{$latestSummary}` from both Claude and Gemini API fallback system prompts.
*   `{$pinned_context}` kept — still carries "currently viewing file" info from the editor.

### Dead Backend Actions Removed
*   `save_context`, `pin_to_context` action cases and their functions `saveContext()` / `pinToContext()`.

### `extract_decisions.php` Module Deleted
*   All 4 functions were unused. `require_once` removed from `braintrust_api.php`.

### Orphaned/Backup Files Deleted (8 files)
*   `braintrust_bak.php`, `braintrust_logic_bak.js`, `braintrust_style_bak.css`, `files_api_bak.php`, `files_api_backup.php`, `braintrust_api_pre_memupgrade.php`, `api/chatgpt_api.php`, `api/extract_decisions.php`

**Net Result:** 8 files deleted, ~845 lines removed from active code across 5 files. All core features preserved.

---

## February 23, 2026 (Afternoon) — CLI Monitor, Whiteboard Fix & Stability (Claude Code Session)

### CLI Monitor — Real-Time Agent Visibility
*   **Problem:** Zero visibility into what CLI agents were doing during message processing.
*   **Solution:** Right-click any AI toggle button → "View CLI" opens real-time terminal monitor.

**Backend (`braintrust_api.php`):**
*   **`formatStreamEvent()`** — Parses stream-json events into human-readable log lines: `[system]`, `[thinking]`, `[tool]` (with name + file path), `[result]`, `[complete]` (with cost), `[error]`.
*   **`executeCLIAgentStream()`** — Processes stdout line-by-line during proc_open polling loop, writes formatted events to `/tmp/braintrust_cli_{session_id}_{provider}.log`, uses `.running` flag file for status detection.
*   **`getCliLog()`** — Serves log file content with byte-offset incremental reads. Supports `reset` detection when log file is rewritten. Auto-cleans stale `.running` flags older than 5 minutes.
*   **Claude switched to `--output-format stream-json --verbose`** — Outputs JSON events line-by-line as they happen.
*   **Gemini stays on `-o json`** — Gemini's stream-json echoed the system prompt as the response; reverted to basic log entries.

**Frontend:**
*   Right-click model picker extended with "View CLI" option.
*   CLI Monitor modal — dark terminal-style display, monospace font, auto-scrolling.
*   Status badge — green pulsing "Running" dot when active, gray "Idle" when done.
*   500ms polling via `pollCliLog()` with byte-offset incremental reads.
*   Color-coded events: tool calls (cyan), thinking (white), results (green), completion (bright green bold), errors (red), system (gray).

### Stdin Piping for CLI Messages
*   **Problem:** Large accumulated messages were passed as shell arguments via `escapeshellarg()` — Gemini CLI timed out on massive escaped arguments.
*   **Fix:** Both execution functions now accept `$stdinData` parameter; messages piped through process's stdin pipe instead of embedded in command string.

### AI Collab Fix — Full 10-Message Cycle
*   **Bug:** AI Collab stopped after only 2 messages instead of running full 10-message cycle.
*   **Root Cause:** `consecutive_ai_turns` was 0 by default and never incremented. SHUT UP detection check evaluated to true after one rotation.
*   **Fix:** `aiCollab()` now sets `consecutive_ai_turns = 1` at start. Only SHUT UP resets it to 0.

### CLI Monitor Duplicate Entry Fix
*   **Bug:** Claude's CLI Monitor showed same 3 events repeated 5 times during AI Collab.
*   **Root Cause:** When log file cleared between CLI invocations, frontend's byte offset was stale.
*   **Fix:** Backend detects `offset > fileSize`, resets to 0, sends `reset: true` flag. Frontend clears display on reset.

### Whiteboard Mermaid Protocol Fix
*   **Bug:** Claude said "I don't see a whiteboard tool" despite system prompt including mermaid instructions.
*   **Fix:** Updated CLI system prompt role text: "You are running inside BrainTrust IDE, NOT as a standalone Claude Code CLI."
*   Also updated: Mermaid section header changed to "WHITEBOARD DIAGRAMS (Mermaid Protocol)" with explicit instruction.

### Mermaid Render Error Handling
*   **Fix:** Added `.catch()` error handler on `mermaid.run()` in `renderDiagramToWhiteboard()`. Errors now log to browser console and temp div is cleaned up.

**Files Modified:** `braintrust_api.php`, `braintrust.php`, `braintrust_logic.js`, `braintrust_style.css`

### Testing Results (Session 37 — "IDE Testing Workspace")
*   CLI persistent sessions working (green dots on both AI badges).
*   AI Collab ran full 10-message cycle — Claude and Gemini designed, built, and debugged a D&D 5e character generator collaboratively.
*   Docker sandbox execution working — caught read-only filesystem bug, AIs fixed it.
*   CREATE_FILE, RUN_FILE protocols functional.
*   Claude CLI Monitor showed real-time stream events (system init, thinking, complete with cost).
*   Gemini CLI Monitor showed basic status (invoked/done).
*   Credits ran out mid-session (Anthropic API balance depleted) — not a code issue.

### Known Issues (Open)
*   Gemini CLI Monitor only shows basic status — could be enriched with stderr capture or stream-json investigation.

---

## February 23, 2026 (Evening) — xterm.js Real PTY Terminal (Claude Code Session)


### Problem
The old fake terminal had multiple fundamental issues:
- `cd` was a lie — updated the visual prompt but every command still ran from project root
- No streaming — waited for full command completion before showing any output
- No PTY — git progress bars, colors, and anything needing a real TTY was broken or silent
- `git clone` produced no output due to git detecting no TTY + HOME set to project path (no git config)
- Empty output bug — `else if (data.output)` evaluated empty string as falsy, swallowing all feedback

### Solution: xterm.js + node-pty WebSocket PTY Server
Replaced the fake terminal entirely with a real PTY using xterm.js (the same terminal VS Code uses) on the frontend and node-pty on the backend.

**New file: `collabchat/terminal_ws.js`**
*   Node.js WebSocket server on port 8083
*   Spawns real `bash` process via `node-pty` when a client connects
*   Protocol: JSON `init` and `resize` control messages; raw keystrokes sent directly to PTY stdin; raw PTY output sent directly to xterm.js (no JSON wrapping)
*   Sets `HOME=/var/www` so git config and SSH keys work properly
*   Sets `GIT_TERMINAL_PROMPT=0` to prevent git from hanging on credential prompts
*   Full PATH including `/snap/bin`
*   Sessions persist when modal is closed — reopening reconnects to same shell (same cwd, same running processes)

**New systemd service: `braintrust-terminal-ws.service`**
*   Runs as www-data, auto-starts on boot, auto-restarts on crash
*   Port 8083 opened in UFW

**`collabchat/braintrust.php`**
*   Added xterm.js 5.3.0 CSS + JS and xterm-addon-fit 0.8.0 CDN links
*   Replaced terminal modal — removed fake input/output divs, added `#xtermContainer` div that xterm.js mounts into
*   Kept quick commands dropdown (now sends command + `\n` directly to PTY)
*   Modal is now 80vh tall and 90% wide for a proper terminal-sized window

**`collabchat/braintrust_logic.js`**
*   Removed all fake terminal code (~215 lines): `initTerminal()`, `handleTerminalKeydown()`, `executeTerminalCommand()`, `appendToTerminal()`, `updateTerminalPrompt()`, `showTerminalHelp()`, `clearTerminal()`
*   New `initXterm()` — creates Terminal instance with FitAddon, connects to `ws://{host}:8083`, sends `init` message with project + dimensions
*   `term.onData` pipes raw keystrokes to WebSocket
*   `term.onResize` sends JSON resize message to PTY server
*   `ResizeObserver` on container div calls `fitAddon.fit()` on resize
*   `runQuickCommand()` sends command + `\n` directly to PTY — sudo commands work natively
*   `clearTerminal()` calls `term.clear()` (xterm built-in)
*   `closeTerminalModal()` hides modal but keeps PTY alive

**npm:** `node-pty` installed in `collabchat/node_modules/`

### What You Get Now
*   Real bash — `cd` actually works, shell variables persist, pipes work, vim works
*   Streaming output — see git clone progress as it happens
*   Full color/ANSI support — `ls --color`, `htop`, `docker logs -f` all render properly
*   Tab completion — bash handles it natively
*   Command history — up/down arrows work (bash history, not a JS array)
*   Ctrl+C, Ctrl+Z, Ctrl+L — all real
*   `git clone` works — HOME is /var/www, git finds config, progress bars stream in real-time

---

## February 23, 2026 (Evening, Continued) — Generate Map Resurrection (Claude Code Session)

### Context
The 🗺️ Generate Map button in the Whiteboard modal had never actually worked. A previous Claude designed and partially implemented it, but it silently failed every single time it was clicked. Nobody knew. Diagnosed and fixed in this session.

### Root Causes (Two Bugs + A Bonus)

**Bug 1 — Wrong PROJECTS_ROOT in security check:**
`generate_project_map.php` used `realpath(__DIR__ . '/../projects/')` which resolved to `/var/www/html/braintrust-IDE-2/collabchat/projects/`. But all projects live in the shared v1 folder at `/var/www/html/braintrust-ide/collabchat/projects/`. Every single call returned `"Invalid project path"` and died silently. The button appeared to do nothing.

**Bug 2 — Unnecessary two-step fetch in JS:**
`generateProjectMap()` made two serial fetches: one to generate the map file, then a second to read `.PROJECT_MAP.md` back from the filesystem. Overcomplicated and fragile. The mermaid content was already available in the first response.

**Bonus bug — Empty diagrams for projects with no relationships:**
If a project's files had no `include/require/fetch` relationships between them, the generated diagram was just `graph TD\n` — no nodes, nothing visible. Orphan files were completely invisible.

### Fixes

**`collabchat/api/generate_project_map.php` — Full rewrite:**
*   Hardcoded correct `PROJECTS_ROOT` (`braintrust-ide/collabchat/projects/`) — matches every other API endpoint
*   Accepts project name only (not full path) — `basename()` sanitized, consistent with the rest of the codebase
*   All scanned files declared as Mermaid nodes upfront — orphan files now appear even with no edges
*   Detection expanded: PHP `include/require`, JS `fetch()`, AND JS `import` statements
*   Skips hidden directories (`.snapshots/`, `.PROJECT_MAP.md`, etc.)
*   Returns `mermaid`, `file_count`, and `edge_count` directly in JSON response — second fetch eliminated
*   `.PROJECT_MAP.md` still written to project folder for AI/reference use

**`collabchat/braintrust_logic.js` — `generateProjectMap()` rewrite:**
*   Auto-opens Whiteboard modal immediately with "🗺️ Scanning project files..." indicator — no more staring at nothing
*   Uses `data.mermaid` directly from the response — second fetch gone
*   Clears `currentDiagramSource` cache before rendering — re-clicking Generate Map always produces a fresh diagram even if the project files changed
*   Graceful empty state message if no PHP/JS/HTML files found
*   Errors display in the whiteboard panel instead of a jarring `alert()` popup

### Net Result
*   Generate Map actually works now. First time ever.
*   Whiteboard opens instantly with loading feedback
*   All files visible as nodes — nothing hidden
*   Relationship arrows labeled: `include`, `fetch`, `import`
*   Zero extra network requests

---

## February 24, 2026 — Image Canvas & Visual Collaboration (Claude Code Session)

### Login Redirect Fix
*   **Bug:** Successful login sent staff to `dashboard.php`.
*   **Fix:** `process_login.php` now redirects directly to `collabchat/braintrust_projects.php`.

### Image Canvas Modal — Phase 1: Paste & Send
*   **Canvas Button:** Added 🖼️ Canvas button to the chat-actions bar.
*   **Canvas Modal:** Full-screen modal with paste area, status text, and Clear/Attach/Close controls.
*   **Paste Support:** Ctrl+V captures clipboard images (screenshots, snips) onto the canvas via `handleCanvasPaste()`.
*   **Database:** Added `image_data MEDIUMTEXT NULL` column to `braintrust_messages` via ALTER TABLE.
*   **Vision API Routing:** Added `$has_image` parameter to `getClaudeResponse()` and `getGeminiResponse()` — when an image is attached, CLI path is bypassed entirely and the Anthropic/Google API vision path is used directly.
*   **Multimodal Context:**
    *   Claude: `buildConversationContext()` produces `{type:'image', source:{type:'base64',...}}` + `{type:'text'}` content blocks.
    *   Gemini: `buildConversationContextForGemini()` produces `{inline_data:{mime_type, data}}` + `{text}` parts.
*   **Message Thumbnails:** `createMessageHTML()` renders a clickable image thumbnail when `msg.image_data` is present.

### Image Canvas Modal — Phase 2: Drawing Tools
*   **Tools:** Freehand pen ✏️, rectangle ⬜, circle ⭕, text overlay T.
*   **Controls:** Color picker, stroke width selector, font size selector. Keyboard shortcuts: P / R / C / T.
*   **Text Tool:** Floating `<input>` overlay positioned at click point; Enter commits, Escape cancels. Shadow outline for legibility over any background.
*   **Preview Rendering:** Shape tools (rect/circle) use save/restore base image pattern so rubber-band preview doesn't accumulate strokes.

### Critical Bug Fix: DOMContentLoaded Timing
*   **Symptom:** After Ctrl+Refresh, chat history disappeared, panel resize broke, and canvas paste stopped working.
*   **Root Cause:** `braintrust_logic.js` is loaded in `<head>` (line 406), but canvas modal HTML is defined at line 572+. The new canvas event listener registrations ran at parse time, hit `null` on `canvasPasteArea` → `TypeError` halted all further script execution. `loadSession`, `renderMessages`, `initResizablePanels` were never defined.
*   **Fix:** Wrapped `canvasPasteArea` and `screenshotCanvas` mousedown/mousemove registrations in `document.addEventListener('DOMContentLoaded', ...)`. Document-level mouseup stays outside (document is always available).

### Save to Project (Replacing "Attach to Message")
*   **Problem:** The original "Attach to Message" workflow sent images via the API vision path (stateless). On the next text-only turn, CLI instances had no memory of the image — fragmented context.
*   **Solution:** Replaced with "Save to Project" — canvas exports as JPEG directly to the session's project folder.
*   **New endpoint:** `api/save_canvas_image.php` — decodes base64 image data, resolves project path from DB via session ID, sanitizes filename, writes binary to project directory with path traversal protection.
*   **Workflow:** Draw/paste on canvas → 💾 Save to Project → enter filename → file saved → tell AIs "read filename.jpg" → both CLI instances read it with full persistent context.
*   **Result:** Completely eliminates API vision calls and context fragmentation. AIs can re-reference the file any time in the session.

### Text Tool Focus Bug Fix
*   **Bug:** Text tool appeared to do nothing — clicking the canvas placed the input but it vanished immediately before any typing.
*   **Root Cause:** `canvasPasteArea` has `tabindex="0"` (required for paste events). On mousedown, the browser focused `canvasPasteArea` stealing focus from the newly created text `<input>`, immediately firing `blur` → `commit()` ran with empty value → input removed.
*   **Fix:** Added `e.preventDefault()` on canvas `mousedown` when `canvasActiveTool === 'text'` to block the browser's default focus-steal behavior. One line.

**Files Created:** `collabchat/api/save_canvas_image.php`
**Files Modified:** `process_login.php`, `collabchat/braintrust.php`, `collabchat/braintrust_logic.js`, `collabchat/braintrust_api.php`, `collabchat/braintrust_style.css`

---

## March 1, 2026 — System Prompt Overhaul, READ_FILE Loop, PHP Preview, Path Audit (Claude Code Session)

### Background
Testing revealed that v1-era "personality playbook" system prompts were causing Gemini to act autonomously — overriding Shannon's instructions, rewriting files without being asked, and driving the session rather than following it. These prompts were remnants of the original API-call-per-message architecture where each turn required full context re-injection. With persistent CLI sessions, they were actively harmful.

---

### System Prompt Cleanup

**Problem:** All four prompt locations (CLI + API fallback × Claude + Gemini) contained:
- Role assignments ("Project Lead", "documentation specialist")
- Prescriptive directives ("drive the session", "always use READ_FILE before proposing major changes")
- Long whiteboard tool explanations Claude misread as Claude Code tools
- Gemini-specific "document decisions" instructions

**Fix (`braintrust_api.php` — `buildCLISystemPrompt()` + API fallback blocks ~line 1310/1442):**
- Stripped all role definitions and job-duty descriptions from all four locations
- Removed "drive the session" directive entirely
- Added single rule: "Only act on what's explicitly requested"
- Replaced ~20-line whiteboard explanation with one sentence: "Whenever your response contains a mermaid code block, the IDE renders it on the whiteboard automatically — no tool, no command."
- Added whiteboard contrast tip: use white/light text on dark node backgrounds (`fill:#336,color:#fff`) — discovered during testing when Shannon couldn't read the diagram

**README template (new project auto-generated `README.md`):**
- Rewrote Ground Rules, File Protocols, Whiteboard section
- Added `## Database` section (see below)
- Example READ_FILE usage written as plain prose (not using the exact command syntax, which the regex was matching when AIs echoed the README)
- Whiteboard explanation expanded: "NOT a tool you call — just write the mermaid block" + contrast readability tip

---

### READ_FILE Auto-Loop

**Problem:** `extractReadFileContents()` existed but was never called. AIs requesting files via `READ_FILE:` only caused the frontend to open the file in Monaco — contents were never injected back to the AI. AIs had to be given "the floor" manually to read files.

**Fix (`braintrust_api.php` — `aiResponseComplete()`):**
- After saving an AI response, checks for `READ_FILE:` in the response text
- If found: reads the file(s), injects contents as a follow-up message, re-invokes the same AI without advancing the turn
- Loop continues until AI response contains no READ_FILE
- SHUT UP button terminates the loop
- Falls back to API if `invokeAIManager()` fails

**READ_FILE regex fix (both `braintrust_api.php` and `braintrust_logic.js`):**
- Added line-start anchor (`^[ \t]*`) to prevent matching READ_FILE examples inside README echoes
- Added `,;` to filename character exclusion to fix "File not found: ," bug (comma was being captured as part of the filename when AIs listed multiple files with commas)

---

### PHP Web App Preview

**Problem:** PHP project files ran in Docker CLI (`php:8.2-cli`) — no DB access, no browser rendering. Useless for complex web apps.

**Fix (`api/run_code.php`):**
- Removed `runPhp()` Docker route for `.php` files
- PHP, HTML, and HTM all now route to `runBrowser()`
- `runBrowser()` generates a direct Apache URL: `http://192.168.1.143/braintrust-IDE-3/collabchat/projects/{project}/{file}`
- Returns `preview_url` field in JSON response
- Docker CLI (`runPhp()`) kept in file for potential future CLI-script use case

**Fix (`braintrust_logic.js` — `executeFile()`):**
- Detects `result.preview_url` in response
- Calls `window.open(url, '_blank')` automatically — preview pops in a new tab
- Logs preview URL to system message in chat

**Shared Dev DB Config:**
- Created `/var/www/secure_config/dev_db_config.php` with `DEV_DB_HOST/USER/PASS/NAME` constants
- New project READMEs include `## Database` section instructing AIs to `require_once` this file
- Keeps credentials out of project files; AIs never need to know the actual password
- Pattern matches existing `braintrust_config.php` in same secure directory

---

### Auto-Focus Fix

**Problem:** After Gemini responded and the turn returned to human, focus did not return to the message input. Shannon had to click the text box every time.

**Root Cause:** `updateTurnState()` called `.focus()` on every poll while `currentTurn === 'human'` — not just on the transition. This caused the focus to fight with any other element the user clicked.

**Fix (`braintrust_logic.js`):**
- Added `let previousTurn = null` at module scope
- Focus now only fires when `previousTurn !== 'human'` (i.e., the turn just transitioned to human)
- Wrapped in `setTimeout(..., 50)` to ensure it fires after `renderMessages()` DOM updates settle
- `previousTurn = currentTurn` recorded at end of every `updateTurnState()` call

---

### Global v1/v2 Path Audit

Full audit of all files in `/var/www/html/braintrust-IDE-3/` for stale v1 (`braintrust-ide`) and v2 (`braintrust-IDE-2`) path references.

**Active code fixed:**

| File | Line | Old | New |
|------|------|-----|-----|
| `braintrust_logic.js` | 3401 | `navigateServerToHome()` pointed to v1 collabchat path | v3 path |
| `braintrust.php` | 431 | Server browser path display defaulted to v1 | v3 path |
| `api/run_code.php` | 148 | Preview URL used v1 domain path | v3 path |

**Not changed:** `BT_Maintenance_log.md` (historical references, accurate as written), `.claude/settings.local.json` (historical npm install reference, no functional impact).

**`braintrust_config.php`:** Added `BT3_PROJECTS_ROOT` constant (`/var/www/html/braintrust-IDE-3/collabchat/projects/`) alongside existing `PROJECTS_ROOT` (v1, preserved for v1 compatibility). All v3 API files now reference `BT3_PROJECTS_ROOT`.

---

### Files Modified This Session
- `collabchat/braintrust_api.php` — system prompt cleanup, READ_FILE loop, path fixes
- `collabchat/braintrust_logic.js` — READ_FILE regex, auto-focus transition fix, preview URL handler, server browser home path
- `collabchat/braintrust.php` — server browser default path display
- `collabchat/braintrust_projects.php` — README template (DB section, whiteboard contrast tip, v3 paths)
- `collabchat/files_api.php` — README template (same as above)
- `collabchat/api/run_code.php` — PHP → browser preview routing, fixed preview URL
- `/var/www/secure_config/braintrust_config.php` — added `BT3_PROJECTS_ROOT`
- `/var/www/secure_config/dev_db_config.php` — **created** (shared DB config for project apps)
- `collabchat/api/` (run_code, save_whiteboard, save_canvas_image, generate_project_map, agent_hub, browse_directory, preview, terminal_api) — v1 path → `BT3_PROJECTS_ROOT` (done in prior session, documented here)

---

## March 2, 2026 — Task Agent Removal, AI Collab UX, Web Preview Polish (Claude Code Session)

### Autonomous Task Agent — Removed
The Task Agent was a separate stateless API loop that duplicated what the main chat already does better. Key deficiencies:
- Used stateless API calls (no persistent CLI memory)
- Files it created had no RUN_FILE preview button in chat
- No snapshot/version history on writes
- Parallel system to maintain with no real advantage

Removed cleanly:
- `🤖 New Task` button from toolbar
- `taskAgentModal` from `braintrust.php`
- All agent JS functions (`launchAgent`, `stopAgent`, `appendAgentLog`, `showTaskModal`, `toggleAgentProvider`) from `braintrust_logic.js`
- `.task-btn` and `.agent-log` CSS from `braintrust_style.css`
- `collabchat/api/agent_hub.php` deleted
- Reference removed from `braintrust.md` and `USERGUIDE.html`

### AI Collab — Send + Activate in One Click
**Problem:** To use AI Collab with a prompt, user had to: type message → Send → wait for both AIs to respond → click AI Collab. Three steps and a wait.

**Fix (`braintrust_logic.js` — `startAICollab()`):**
- Checks for text in `messageInput` before activating collab
- If text present: sends message via `send_message` action (same path as `sendMessage()`), clears input and image attachment, then immediately activates collab
- If empty: just activates collab (previous behavior)
- If send fails: bails out before activating collab

### README Template — Two More Clarifications
1. **Non-interactive sandbox warning:** Added to the `RUN_FILE` line — "the sandbox is non-interactive; scripts using input() will fail with EOFError"
2. **File protocol clarification:** Added note that protocols are required (not optional) because native tools bypass snapshot system and version history

### Diagram
Reviewed `bt_operationaldiagram.png` — accurate high-level representation of the architecture. Included in repo.

### Files Modified
- `collabchat/braintrust.php` — removed Task Agent modal and New Task button
- `collabchat/braintrust_logic.js` — removed Task Agent JS, updated `startAICollab()` for send+activate flow
- `collabchat/braintrust_style.css` — removed `.task-btn` and `.agent-log`
- `collabchat/braintrust_projects.php` — README template non-interactive warning
- `collabchat/files_api.php` — README template non-interactive warning
- `collabchat/api/agent_hub.php` — **deleted**
- `USERGUIDE.html` — Task Agent section replaced with Web Preview section, AI Collab updated, tips updated
- `braintrust.md` — Task Agent reference removed
- `BT_Maintenance_log.md` — this entry
- `bt_operationaldiagram.png` — added to repo
