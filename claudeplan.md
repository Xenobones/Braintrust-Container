Now, my research agents came back with great findings. Here's the key discovery that simplifies the whole architecture:

  Both CLIs support sequential invocations with session resume. We don't need to keep long-running processes alive at all.
  Instead:

  - Claude Code: claude -p "message" --session-id <uuid> --output-format json for the first message, then claude -p --resume
  <uuid> "next message" --output-format json for follow-ups. Full conversation history persists automatically.
  - Gemini CLI: gemini -p "message" -o json for the first message, then gemini -r latest -p "next message" -o json for
  follow-ups. Same deal.

  This means the agent_manager.js process manager from hybridide.md becomes unnecessary. PHP can just shell out to the CLI tools
   directly — no Node.js middleman, no stdin/stdout parsing, no process crash handling. Way simpler.

  Let me design the plan now.

● Plan(Design hybrid IDE implementation plan)
  ⎿  Done (56 tool uses · 90.7k tokens · 19m 29s)                                                                               
  (ctrl+o to expand)                                                                                                            
                                                                                                                                
● Great research results. Let me write up the final plan.                                                                       
                                
● Updated plan                                                                                                                  
  ⎿  /plan to preview                                                                                                           
                                                                                                                                
────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
 Ready to code?                                                                                                                 
                                                                                                                                
 Here is Claude's plan:                                                                                                         
╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌
 Hybrid IDE: CLI Agent Integration Plan                                                                                         
                                                                                                                                
 Context                                                                                                                        
                                                                                                                                
 BrainTrust IDE currently makes fresh stateless API calls for every AI response, rebuilding the entire context each time:       
 system prompt + 50 messages + pinned files + pulse file + decisions JSON + latest summary. This is expensive (re-sending       
 thousands of input tokens every message) and lossy (anything beyond 50 messages is gone).                                      
                                                                                                                                
 The fix: Replace the cURL API calls for Claude and Gemini with their CLI tools (already installed on Skynet), which maintain   
 persistent conversation history via session resume. ChatGPT stays on API calls (no CLI exists).
                                                                                                                                
 Key discovery: Both CLIs support sequential invocations with session resume — no persistent process manager needed. PHP shells
  out to the CLI, gets JSON back, done.

 - Claude Code CLI v2.1.50: claude -p "msg" --session-id <uuid> --output-format json then claude -p --resume <uuid> "msg"
 --output-format json
 - Gemini CLI v0.26.0: gemini -p "msg" -o json then gemini -r latest -p "msg" -o json

 ---
 What Changes

 1 file modified: collabchat/braintrust_api.php
 1 migration: Add claude_cli_session_uuid and gemini_cli_session_uuid columns to braintrust_turn_state
 0 frontend changes — the frontend doesn't care how the backend gets AI responses

 ---
 Implementation Steps

 Step 1: Database Migration

 Add two nullable VARCHAR(36) columns to braintrust_turn_state for storing CLI session UUIDs.

 Step 2: Environment Setup

 Create /var/www/.claude/ and /var/www/.gemini/ directories owned by www-data so the CLIs can store session data.

 Step 3: Add executeCLIAgent() Helper

 New function using proc_open (same pattern as terminal_api.php) that:
 - Runs a CLI command with environment variables (API keys)
 - Captures stdout as JSON
 - Handles timeouts (180 seconds)
 - Strips CLAUDECODE env vars to prevent nesting detection
 - Returns parsed JSON or null on failure

 Step 4: Add buildCLISystemPrompt()

 Trimmed-down system prompt with BrainTrust protocols (CREATE_FILE, READ_FILE, RUN_FILE, Mermaid rules). No pulse, no
 decisions, no summary injection — CLI agents remember natively. Includes explicit instruction to NOT use built-in file editing
  tools (use CREATE_FILE protocol instead, so BrainTrust's snapshot system works).

 Step 5: Add getLatestHumanMessage() Helper

 Simple DB query to get the most recent human message text — that's all we send to CLI agents (they have full history).

 Step 6: Add getClaudeResponseCLI()

 - Checks DB for existing claude_cli_session_uuid
 - First invocation: claude -p "msg" --output-format json --model <selected> --append-system-prompt "<protocols>"
 --dangerously-skip-permissions
 - Resume: claude -p --resume <uuid> "msg" --output-format json --model <selected> --dangerously-skip-permissions
 - Stores returned session UUID in DB
 - Returns response text, or null on any failure

 Step 7: Add getGeminiResponseCLI()

 - Same pattern but with Gemini CLI flags
 - First invocation: system prompt prepended to first message (no --system-prompt flag in Gemini CLI)
 - Resume: gemini -r latest -p "msg" -o json -y --model <selected>
 - Runs in project directory (Gemini sessions are per-working-directory)

 Step 8: Modify getClaudeResponse() — CLI-First with API Fallback

 Add CLI attempt at the TOP of the existing function. If CLI returns null, the entire existing API logic runs unchanged as
 fallback. Zero risk to existing functionality.

 Step 9: Modify getGeminiResponse() — Same Pattern

 Same CLI-first, API-fallback wrapper.

 Step 10: Modify setModel() — Reset CLI Session on Model Change

 When user switches models, clear the CLI session UUID so next message starts a fresh session with the new model.

 ---
 Key Design Decisions

 - No tools for CLI agents in v1: Use --tools "" for Claude to disable built-in file editing tools. The agents respond with
 text only, and BrainTrust's existing processAIToolCalls() handles CREATE_FILE commands from the response. This avoids
 bypassing the snapshot/diff system.
 - API fallback is automatic: If CLI fails for any reason (not installed, timeout, bad output), the existing API path runs. No
 user-visible change.
 - Pulse/decisions/summaries kept running: They're cheap and still needed for ChatGPT (API-based) and as fallback. Optimization
  for v2.
 - 180-second timeout: CLI agents may take longer than API calls. set_time_limit(300) in PHP for safety.

 ---
 Files Reference

 - collabchat/braintrust_api.php — All changes go here (4 new functions, 3 modified functions)
 - collabchat/terminal_api.php — Reference pattern for proc_open with timeout
 - /var/www/secure_config/braintrust_config.php — API keys passed as env vars to CLI processes

 ---
 Verification

 1. Send a message in BrainTrust UI with Claude enabled
 2. Check DB: SELECT claude_cli_session_uuid FROM braintrust_turn_state — should have a UUID
 3. Send a follow-up message — response should show awareness of prior conversation (persistent memory working)
 4. Check Apache error log for CLI Claude: entries confirming CLI path was used
 5. Repeat for Gemini
 6. Test fallback: temporarily rename /usr/bin/claude, send a message — should fall back to API seamlessly
 7. Test model switch: change model via right-click, send message — new session UUID should appear