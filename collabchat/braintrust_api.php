<?php
/**
 * BrainTrust API - Collaboration Hub Backend
 * Handles messaging, turn management, AI communication
 */

session_start();
require_once '/var/www/secure_config/braintrust_config.php';

header('Content-Type: application/json');

// ── v3: ai_manager callback — must be checked BEFORE auth (no user session) ──
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'ai_response_complete') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '127.0.0.1' || $remote === '::1' || $remote === '::ffff:127.0.0.1') {
        // Defer function call until after all functions are defined — use a flag
        define('HANDLE_AI_CALLBACK', true);
    } else {
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit();
    }
}

// Auth check (skipped for internal ai_manager callbacks)
if (!defined('HANDLE_AI_CALLBACK') && !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;

/**
 * Notify WebSocket server that a session has new data.
 * Fires and forgets - if WS server is down, silently fails.
 */
function notifyWebSocket($session_id, $type = 'refresh') {
    $data = json_encode(['session_id' => $session_id, 'type' => $type]);
    $ch = curl_init('http://127.0.0.1:8082/notify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500); // 500ms max - don't block
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
    @curl_exec($ch);
    curl_close($ch);
}

// ============================================================
// CLI AGENT FUNCTIONS (Hybrid IDE - Persistent Session Support)
// ============================================================

/**
 * Execute a CLI agent command and return parsed JSON response.
 * Uses proc_open with timeout handling (pattern from terminal_api.php).
 *
 * @param string $command - Full CLI command to execute
 * @param string $workingDir - Working directory for the process
 * @param array $env - Environment variables to pass
 * @param int $timeoutSeconds - Maximum execution time (default 180)
 * @return array|null - Parsed JSON response, or null on failure
 */
function executeCLIAgent($command, $workingDir, $env = [], $timeoutSeconds = 180, $stdinData = null) {
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    // Build environment - ensure CLI tools can find config/session storage
    $processEnv = array_merge([
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => '/var/www',
        'NODE_PATH' => '/usr/lib/node_modules',
        'TERM' => 'dumb',
    ], $env);

    // Remove Claude Code nesting detection env vars
    unset($processEnv['CLAUDECODE']);
    unset($processEnv['CLAUDE_CODE_SSE_PORT']);
    unset($processEnv['CLAUDE_CODE_ENTRYPOINT']);

    $process = proc_open($command, $descriptors, $pipes, $workingDir, $processEnv);

    if (!is_resource($process)) {
        error_log("CLI Agent: Failed to start process: $command");
        return null;
    }

    // Write message to stdin if provided (avoids shell argument size limits)
    if ($stdinData !== null) {
        fwrite($pipes[0], $stdinData);
    }
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $startTime = time();

    while (true) {
        $status = proc_get_status($process);

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }

        if (time() - $startTime > $timeoutSeconds) {
            proc_terminate($process, 15); // SIGTERM
            usleep(100000); // 100ms grace
            proc_terminate($process, 9);  // SIGKILL
            error_log("CLI Agent: Timeout after {$timeoutSeconds}s: $command");
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return null;
        }

        usleep(50000); // 50ms polling
    }

    // Final read
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($stderr) {
        error_log("CLI Agent stderr: " . substr($stderr, 0, 500));
    }

    if ($exitCode !== 0) {
        error_log("CLI Agent: Non-zero exit code $exitCode for: " . substr($command, 0, 200));
        // Don't return null yet - some CLIs use non-zero exit for non-fatal issues
        // Try parsing JSON anyway
    }

    // Parse JSON from stdout - may contain log lines before the JSON
    $trimmed = trim($stdout);
    $data = json_decode($trimmed, true);

    // If direct parse fails, try to find JSON object in output
    if ($data === null && !empty($trimmed)) {
        // Look for the last complete JSON object in stdout
        $lastBrace = strrpos($trimmed, '}');
        if ($lastBrace !== false) {
            // Find matching opening brace
            $depth = 0;
            for ($i = $lastBrace; $i >= 0; $i--) {
                if ($trimmed[$i] === '}') $depth++;
                if ($trimmed[$i] === '{') $depth--;
                if ($depth === 0) {
                    $jsonStr = substr($trimmed, $i, $lastBrace - $i + 1);
                    $data = json_decode($jsonStr, true);
                    if ($data !== null) break;
                }
            }
        }
    }

    if ($data === null) {
        error_log("CLI Agent: Failed to parse JSON. Raw output: " . substr($trimmed, 0, 500));
        return null;
    }

    return $data;
}

/**
 * Format a stream-json event into a human-readable log line.
 * Used by executeCLIAgentStream() to write to the CLI monitor log file.
 */
function formatStreamEvent($event) {
    $time = date('H:i:s');
    $type = $event['type'] ?? 'unknown';

    switch ($type) {
        case 'system':
            $subtype = $event['subtype'] ?? '';
            if ($subtype === 'init') {
                $sid = substr($event['session_id'] ?? '', 0, 8);
                return "[$time] [system] Session initialized ($sid...)";
            }
            return $subtype ? "[$time] [system] $subtype" : null;

        case 'assistant':
            $content = $event['message']['content'] ?? [];
            $texts = [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text = substr($block['text'], 0, 200);
                    if (strlen($block['text']) > 200) $text .= '...';
                    $texts[] = $text;
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $name = $block['name'] ?? 'Unknown';
                    $input = $block['input'] ?? [];
                    $detail = '';
                    if (isset($input['file_path'])) $detail = $input['file_path'];
                    elseif (isset($input['command'])) $detail = substr($input['command'], 0, 120);
                    elseif (isset($input['pattern'])) $detail = 'pattern: ' . $input['pattern'];
                    elseif (isset($input['content'])) $detail = '(' . strlen($input['content']) . ' chars)';
                    $texts[] = null; // don't add to thinking
                    // Write tool line separately
                    return "[$time] [tool] $name: $detail";
                }
            }
            $texts = array_filter($texts);
            if (empty($texts)) return null;
            return "[$time] [thinking] " . implode(' ', $texts);

        case 'tool_use':
            $name = $event['name'] ?? 'Unknown';
            $input = $event['input'] ?? [];
            $detail = '';
            if (isset($input['file_path'])) $detail = $input['file_path'];
            elseif (isset($input['command'])) $detail = substr($input['command'], 0, 120);
            elseif (isset($input['pattern'])) $detail = 'pattern: ' . $input['pattern'];
            elseif (isset($input['query'])) $detail = substr($input['query'], 0, 100);
            return "[$time] [tool] $name: $detail";

        case 'tool_result':
            $content = $event['content'] ?? '';
            if (is_array($content)) $content = json_encode($content);
            $len = strlen($content);
            return "[$time] [result] ($len bytes)";

        case 'result':
            $cost = $event['cost_usd'] ?? $event['total_cost_usd'] ?? 0;
            $costStr = $cost > 0 ? sprintf(' (cost: $%.4f)', $cost) : '';
            // Gemini result events have stats
            $stats = $event['stats'] ?? null;
            if ($stats && isset($stats['total_tokens'])) {
                $costStr .= " ({$stats['total_tokens']} tokens)";
            }
            return "[$time] [complete] Done$costStr";

        default:
            // Handle Gemini-style events with 'content' or 'text' at top level
            if (isset($event['content'])) {
                $text = is_string($event['content']) ? $event['content'] : json_encode($event['content']);
                $text = substr($text, 0, 200);
                return "[$time] [thinking] $text";
            }
            if (isset($event['text'])) {
                $text = substr($event['text'], 0, 200);
                return "[$time] [thinking] $text";
            }
            return null;
    }
}

/**
 * Execute a CLI agent with stream-json output, writing events to a log file in real-time.
 * Similar to executeCLIAgent() but processes stdout line-by-line for the CLI Monitor feature.
 *
 * @param string $command - Full CLI command (must use stream-json output format)
 * @param string $workingDir - Working directory for the process
 * @param array $env - Environment variables to pass
 * @param string $logFile - Path to temp log file for CLI Monitor
 * @param int $timeoutSeconds - Maximum execution time (default 180)
 * @return array|null - The 'result' event data, or null on failure
 */
function executeCLIAgentStream($command, $workingDir, $env, $logFile, $timeoutSeconds = 180, $stdinData = null) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $processEnv = array_merge([
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => '/var/www',
        'NODE_PATH' => '/usr/lib/node_modules',
        'TERM' => 'dumb',
    ], $env);

    unset($processEnv['CLAUDECODE']);
    unset($processEnv['CLAUDE_CODE_SSE_PORT']);
    unset($processEnv['CLAUDE_CODE_ENTRYPOINT']);

    // Initialize log file and running flag
    file_put_contents($logFile, '');
    file_put_contents($logFile . '.running', '1');

    $process = proc_open($command, $descriptors, $pipes, $workingDir, $processEnv);

    if (!is_resource($process)) {
        error_log("CLI Agent Stream: Failed to start process: $command");
        file_put_contents($logFile, "[" . date('H:i:s') . "] [error] Failed to start CLI process\n", FILE_APPEND);
        @unlink($logFile . '.running');
        return null;
    }

    // Write message to stdin if provided (avoids shell argument size limits)
    if ($stdinData !== null) {
        fwrite($pipes[0], $stdinData);
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdoutBuffer = '';
    $stderr = '';
    $resultEvent = null;
    $accumulatedText = ''; // Accumulate response text from assistant events
    $lastSessionId = null;
    $startTime = time();

    while (true) {
        $status = proc_get_status($process);

        $chunk = stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if ($chunk !== '' && $chunk !== false) {
            $stdoutBuffer .= $chunk;

            // Process complete lines
            while (($nlPos = strpos($stdoutBuffer, "\n")) !== false) {
                $line = substr($stdoutBuffer, 0, $nlPos);
                $stdoutBuffer = substr($stdoutBuffer, $nlPos + 1);

                $line = trim($line);
                if ($line === '') continue;

                $event = json_decode($line, true);
                if ($event === null) continue;

                // Format and write to log file
                $logEntry = formatStreamEvent($event);
                if ($logEntry) {
                    file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);
                }

                // Capture the result event
                $eventType = $event['type'] ?? '';
                if ($eventType === 'result') {
                    $resultEvent = $event;
                }
                // Track session ID from system init events
                if ($eventType === 'system' && isset($event['session_id'])) {
                    $lastSessionId = $event['session_id'];
                }
                // Accumulate text from assistant/content events
                if ($eventType === 'assistant') {
                    foreach (($event['message']['content'] ?? []) as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $accumulatedText .= $block['text'];
                        }
                    }
                }
                // Gemini may send text in 'content' or 'text' fields directly
                if (isset($event['content']) && is_string($event['content'])) {
                    $accumulatedText .= $event['content'];
                } elseif (isset($event['text']) && is_string($event['text'])) {
                    $accumulatedText .= $event['text'];
                }
            }
        }

        if (!$status['running']) break;

        if (time() - $startTime > $timeoutSeconds) {
            proc_terminate($process, 15);
            usleep(100000);
            proc_terminate($process, 9);
            error_log("CLI Agent Stream: Timeout after {$timeoutSeconds}s");
            file_put_contents($logFile, "[" . date('H:i:s') . "] [error] Timeout after {$timeoutSeconds}s\n", FILE_APPEND);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($logFile . '.running');
            return null;
        }

        usleep(50000);
    }

    // Process remaining buffer
    $stdoutBuffer .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    // Parse any remaining complete lines
    $remainingLines = explode("\n", $stdoutBuffer);
    foreach ($remainingLines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $event = json_decode($line, true);
        if ($event === null) continue;

        $logEntry = formatStreamEvent($event);
        if ($logEntry) {
            file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);
        }

        $eventType = $event['type'] ?? '';
        if ($eventType === 'result') {
            $resultEvent = $event;
        }
        if ($eventType === 'system' && isset($event['session_id'])) {
            $lastSessionId = $event['session_id'];
        }
        if ($eventType === 'assistant') {
            foreach (($event['message']['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $accumulatedText .= $block['text'];
                }
            }
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($stderr) {
        error_log("CLI Agent Stream stderr: " . substr($stderr, 0, 500));
    }

    @unlink($logFile . '.running');

    // Build result: prefer the result event, fall back to accumulated text
    if ($resultEvent !== null) {
        // Ensure session_id is available
        if (!isset($resultEvent['session_id']) && $lastSessionId) {
            $resultEvent['session_id'] = $lastSessionId;
        }
        // Ensure result/response text is available
        if (!isset($resultEvent['result']) && !isset($resultEvent['response']) && $accumulatedText) {
            $resultEvent['result'] = $accumulatedText;
        }
        return $resultEvent;
    }

    // No result event but we have accumulated text (Gemini pattern)
    if ($accumulatedText) {
        error_log("CLI Agent Stream: No result event, using accumulated text (" . strlen($accumulatedText) . " chars)");
        return [
            'result' => $accumulatedText,
            'response' => $accumulatedText,
            'session_id' => $lastSessionId,
        ];
    }

    error_log("CLI Agent Stream: No result event and no accumulated text");
    file_put_contents($logFile, "[" . date('H:i:s') . "] [error] No result event in stream\n", FILE_APPEND);
    return null;
}

/**
 * Build a system prompt for CLI agents.
 * Leaner than the API prompt: no pulse, no decisions, no summary injection.
 * CLI agents maintain their own memory via session persistence.
 */
/**
 * Build a compact permissions/role reminder prepended to each resumed-session message.
 * Returns an empty string when everything is at default (no noise for normal sessions).
 */
function buildPermissionsReminder($ai_type, $perms) {
    $restrictions = [];
    if (isset($perms[$ai_type . '_can_write'])    && !$perms[$ai_type . '_can_write'])    $restrictions[] = "✏️ Write Files: DISABLED — do NOT use CREATE_FILE:";
    if (isset($perms[$ai_type . '_can_delete'])   && !$perms[$ai_type . '_can_delete'])   $restrictions[] = "🗑️ Delete Files: DISABLED";
    if (isset($perms[$ai_type . '_can_run'])      && !$perms[$ai_type . '_can_run'])      $restrictions[] = "▶️ Run Code: DISABLED — do NOT use RUN_FILE:";
    if (isset($perms[$ai_type . '_can_terminal']) && !$perms[$ai_type . '_can_terminal']) $restrictions[] = "🖥️ Terminal: DISABLED";
    if (isset($perms[$ai_type . '_can_packages']) && !$perms[$ai_type . '_can_packages']) $restrictions[] = "📦 Install Packages: DISABLED";

    $isLead = !empty($perms[$ai_type . '_lead']);

    $parts = [];
    if ($isLead) $parts[] = "👑 You are PROJECT LEAD — you have final say on architecture and code direction.";
    if (!empty($restrictions)) $parts[] = "⛔ Active restrictions: " . implode(" | ", $restrictions) . ". You MUST respect these.";

    if (empty($parts)) return ''; // Everything at default — no reminder needed

    return "[SESSION STATUS: " . implode(" ", $parts) . "]\n\n";
}

function buildCLISystemPrompt($agent_type, $pinned_context, $perms = []) {
    $isLead = !empty($perms[$agent_type . '_lead']);

    $roles = [
        'claude' => 'You are Claude, an AI collaborator in BrainTrust IDE. CRITICAL: You are running as a chat participant inside BrainTrust IDE, NOT as a standalone Claude Code CLI agent. Your Claude Code tool list (Read, Write, Bash, etc.) is irrelevant here — do NOT reference it, do NOT say "I don\'t see that in my tools", do NOT say "I don\'t have a tool for that". Everything in BrainTrust IDE works through plain text output using the protocols described below.',
        'gemini' => 'You are Gemini, an AI collaborator in BrainTrust IDE. IMPORTANT: You are running inside BrainTrust IDE — use the text-based protocols below to interact with the IDE.',
    ];
    $role = $roles[$agent_type] ?? 'You are an AI collaborator in BrainTrust IDE.';
    if ($isLead) $role .= ' You have been designated 👑 Project Lead for this session by the human.';

    $prompt = "{$role}

CONTEXT FOR THIS PROJECT:
{$pinned_context}

You have access to the project's file system.

### ACTION PROTOCOLS
1. FILE CREATION: To create a new file or overwrite an existing one, you MUST use:
CREATE_FILE: path/to/filename.ext
```language
[full file content here]
```

2. FILE READING: To request that a file be opened in the editor, use:
READ_FILE: path/to/filename.ext

3. FILE EXECUTION: To test a script and see the output in the terminal, use:
RUN_FILE: path/to/filename.ext

IMPORTANT: Do NOT use your built-in file editing tools (Edit, Write, Bash). Always use the CREATE_FILE protocol described above. The BrainTrust IDE processes CREATE_FILE commands to manage file snapshots and version tracking.

### OPERATIONAL GUIDELINES
1. Normally only output ONE action command per response. EXCEPTION: If you have been given the FLOOR, you may execute multiple READ_FILE commands in sequence.
2. Only take actions explicitly requested by the human. If you spot something unrelated that looks like it needs attention, mention it and ask — do not act on it unilaterally.
3. Use RUN_FILE after creating a script to verify it works.
4. Ensure filenames and paths are accurate to the project structure.

### WHITEBOARD DIAGRAMS:
To draw on the whiteboard, include a mermaid code block in your response — the IDE renders it automatically. No tool, no command. Just write the mermaid block. Rules: use 'graph TD' or 'graph LR', quote node text with special characters, never use 'end' as a node ID, no // comments, no style/classDef overrides.

### COLLABORATION RULES:
1. Be concise - this is a real-time working session.
2. Focus on solving challenges and being a helpful part of the engineering team.";

    // Inject permission restrictions
    $restrictions = [];
    if (isset($perms[$agent_type . '_can_write']) && !$perms[$agent_type . '_can_write']) {
        $restrictions[] = "✏️ Write Files: DENIED — Do NOT use CREATE_FILE: under any circumstances. Suggest changes in plain text only.";
    }
    if (isset($perms[$agent_type . '_can_run']) && !$perms[$agent_type . '_can_run']) {
        $restrictions[] = "▶️ Run Code: DENIED — Do NOT use RUN_FILE: under any circumstances.";
    }
    if (isset($perms[$agent_type . '_can_terminal']) && !$perms[$agent_type . '_can_terminal']) {
        $restrictions[] = "🖥️ Terminal Access: DENIED — Do not suggest or attempt any shell/terminal commands.";
    }
    if (isset($perms[$agent_type . '_can_packages']) && !$perms[$agent_type . '_can_packages']) {
        $restrictions[] = "📦 Install Packages: DENIED — Do not suggest installing any packages or dependencies.";
    }
    if (!empty($restrictions)) {
        $prompt .= "\n\n### ⛔ PERMISSION RESTRICTIONS FOR THIS SESSION:\nThe human has restricted your permissions. You MUST respect these limits:\n- " . implode("\n- ", $restrictions);
    }

    return $prompt;
}

/**
 * Get messages since this AI's last response, formatted for the CLI agent.
 * Includes messages from other AIs and humans so the agent sees the full
 * multi-party conversation, not just the human's message.
 *
 * @param string $ai_type - 'claude' or 'gemini'
 * @return string|null - Formatted conversation chunk, or null if no messages
 */
function getMessagesSinceLastResponse($conn, $session_id, $ai_type) {
    // Find the ID of this AI's last message
    $stmt = $conn->prepare("
        SELECT id FROM braintrust_messages
        WHERE session_id = ? AND sender_type = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("is", $session_id, $ai_type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $lastAiMessageId = $row['id'] ?? 0;

    // Get all messages after that point (from other AIs and humans)
    $stmt = $conn->prepare("
        SELECT m.message_text, m.sender_type, u.username as sender_name
        FROM braintrust_messages m
        LEFT JOIN users u ON m.sender_user_id = u.id
        WHERE m.session_id = ? AND m.id > ? AND m.sender_type != ? AND m.is_summary = 0
        ORDER BY m.id ASC
    ");
    $stmt->bind_param("iis", $session_id, $lastAiMessageId, $ai_type);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($messages)) return null;

    // If it's just one human message, send it plain
    if (count($messages) === 1 && $messages[0]['sender_type'] === 'human') {
        return $messages[0]['message_text'];
    }

    // Multiple messages — format with sender labels so the AI sees the full conversation
    $formatted = "";
    foreach ($messages as $msg) {
        if ($msg['sender_type'] === 'human') {
            $sender = $msg['sender_name'] ?? 'Human';
        } else {
            $sender = ucfirst($msg['sender_type']);
        }
        $formatted .= "[{$sender}]: {$msg['message_text']}\n\n";
    }

    return trim($formatted);
}

/**
 * Get Claude response via Claude Code CLI with session persistence.
 * Returns response text on success, or null on failure (triggers API fallback).
 */
function getClaudeResponseCLI($conn, $session_id, $message, $pinned_context) {
    set_time_limit(300); // 5 minutes for CLI agent

    $project_path = getProjectPath($conn, $session_id);
    $workingDir = $project_path ?: '/tmp';

    // Get CLI session UUID and permissions from DB
    $stmt = $conn->prepare("SELECT claude_cli_session_uuid, claude_can_write, claude_can_delete, claude_can_run, claude_can_terminal, claude_can_packages, claude_lead, claude_perms_notify FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cliSessionUUID = $row['claude_cli_session_uuid'] ?? null;
    $perms = $row ?: [];

    // Get selected model
    $model = getSelectedModel($conn, $session_id, 'claude', 'claude-sonnet-4-20250514');

    // Build command — message piped via stdin to avoid shell argument size limits
    if ($cliSessionUUID) {
        // Resume existing session — inject permissions reminder only if permissions changed since last turn
        if (!empty($row['claude_perms_notify'])) {
            $message = buildPermissionsReminder('claude', $perms) . $message;
            $conn->query("UPDATE braintrust_turn_state SET claude_perms_notify = 0 WHERE session_id = $session_id");
        }
        $cmd = sprintf(
            '/usr/bin/claude -p --resume %s --output-format stream-json --verbose --model %s --dangerously-skip-permissions',
            escapeshellarg($cliSessionUUID),
            escapeshellarg($model)
        );
    } else {
        // First invocation - include system prompt
        $systemPrompt = buildCLISystemPrompt('claude', $pinned_context, $perms);
        $cmd = sprintf(
            '/usr/bin/claude -p --output-format stream-json --verbose --model %s --append-system-prompt %s --dangerously-skip-permissions',
            escapeshellarg($model),
            escapeshellarg($systemPrompt)
        );
    }

    // Execute with streaming output (retry up to 3 times with exponential backoff)
    $logFile = '/var/www/html/collabchat/logs/braintrust_cli_' . $session_id . '_claude.log';
    $env = ['ANTHROPIC_API_KEY' => CLAUDE_API_KEY];
    $result = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $result = executeCLIAgentStream($cmd, $workingDir, $env, $logFile, 180, $message);
        if ($result !== null) break;
        error_log("CLI Claude: Attempt $attempt failed, retrying in " . pow(2, $attempt) . "s...");
        sleep(pow(2, $attempt)); // 2s, 4s, 8s
    }

    if (!$result) return null;

    // Validate response
    if (!isset($result['result'])) {
        error_log("CLI Claude: Missing 'result' field. Keys: " . implode(',', array_keys($result)));
        return null;
    }

    if (($result['is_error'] ?? false) || ($result['subtype'] ?? '') === 'error') {
        error_log("CLI Claude: Error response: " . substr($result['result'] ?? 'unknown', 0, 200));
        return null;
    }

    // Store session UUID
    $newUUID = $result['session_id'] ?? null;
    if ($newUUID && $newUUID !== $cliSessionUUID) {
        $stmt = $conn->prepare("UPDATE braintrust_turn_state SET claude_cli_session_uuid = ? WHERE session_id = ?");
        $stmt->bind_param("si", $newUUID, $session_id);
        $stmt->execute();
        $stmt->close();
    }

    // Log cost
    $cost = $result['total_cost_usd'] ?? $result['cost_usd'] ?? 0;
    if ($cost > 0) {
        error_log("CLI Claude: session=$session_id, cost=\${$cost}");
    }

    return $result['result'];
}

/**
 * Get Gemini response via Gemini CLI with session persistence.
 * Returns response text on success, or null on failure (triggers API fallback).
 */
function getGeminiResponseCLI($conn, $session_id, $message, $pinned_context) {
    set_time_limit(300);

    $project_path = getProjectPath($conn, $session_id);
    if (!$project_path) return null; // Gemini sessions need a project directory

    // Get CLI session UUID and permissions from DB
    $stmt = $conn->prepare("SELECT gemini_cli_session_uuid, gemini_can_write, gemini_can_delete, gemini_can_run, gemini_can_terminal, gemini_can_packages, gemini_lead, gemini_perms_notify FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cliSessionUUID = $row['gemini_cli_session_uuid'] ?? null;
    $perms = $row ?: [];

    // Get selected model
    $model = getSelectedModel($conn, $session_id, 'gemini', 'gemini-2.5-flash');

    // Build command — Gemini CLI requires the prompt as a -p argument (does not read from stdin)
    if ($cliSessionUUID) {
        // Resume existing session — inject protocol reminder; add permissions reminder only if permissions changed
        $protocolReminder = "PROTOCOL REMINDER: To create or update a file, you MUST output the literal text CREATE_FILE: followed by the filename on one line, then a fenced code block with the full file content. Example:\nCREATE_FILE: example.md\n```markdown\n# Hello\n```\nDo NOT claim a file was created without outputting that block.\n\n";
        $permReminder = '';
        if (!empty($row['gemini_perms_notify'])) {
            $permReminder = buildPermissionsReminder('gemini', $perms);
            $conn->query("UPDATE braintrust_turn_state SET gemini_perms_notify = 0 WHERE session_id = $session_id");
        }
        $prompt = $protocolReminder . $permReminder . "User message:\n\n" . $message;
        $cmd = sprintf(
            '/usr/bin/gemini -r latest -p %s -o json -y --model %s',
            escapeshellarg($prompt),
            escapeshellarg($model)
        );
    } else {
        // First invocation - prepend system instructions to prompt
        $systemPrompt = buildCLISystemPrompt('gemini', $pinned_context, $perms);
        $prompt = $systemPrompt . "\n\n---\n\nUser message:\n" . $message;
        $cmd = sprintf(
            '/usr/bin/gemini -p %s -o json -y --model %s',
            escapeshellarg($prompt),
            escapeshellarg($model)
        );
    }
    $stdinMessage = null; // Gemini CLI does not support stdin input

    // Execute in project directory (retry up to 3 times with exponential backoff)
    // Write stderr to log file for CLI Monitor visibility
    $logFile = '/var/www/html/collabchat/logs/braintrust_cli_' . $session_id . '_gemini.log';
    file_put_contents($logFile, "[" . date('H:i:s') . "] [system] Gemini CLI invoked\n");
    file_put_contents($logFile . '.running', '1');

    $env = ['GEMINI_API_KEY' => GEMINI_API_KEY];
    $result = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $result = executeCLIAgent($cmd, $project_path, $env, 180, $stdinMessage);
        if ($result !== null) break;
        error_log("CLI Gemini: Attempt $attempt failed, retrying in " . pow(2, $attempt) . "s...");
        file_put_contents($logFile, "[" . date('H:i:s') . "] [error] Attempt $attempt failed\n", FILE_APPEND);
        sleep(pow(2, $attempt)); // 2s, 4s, 8s
    }

    file_put_contents($logFile, "[" . date('H:i:s') . "] [complete] " . ($result ? "Done" : "Failed — falling back to API") . "\n", FILE_APPEND);
    @unlink($logFile . '.running');

    if (!$result) return null;

    // Validate response
    if (!isset($result['response'])) {
        error_log("CLI Gemini: Missing 'response' field. Keys: " . implode(',', array_keys($result)));
        return null;
    }

    // Store session UUID
    $newUUID = $result['session_id'] ?? null;
    if ($newUUID && $newUUID !== $cliSessionUUID) {
        $stmt = $conn->prepare("UPDATE braintrust_turn_state SET gemini_cli_session_uuid = ? WHERE session_id = ?");
        $stmt->bind_param("si", $newUUID, $session_id);
        $stmt->execute();
        $stmt->close();
    }

    return $result['response'];
}

// ============================================================
// END CLI AGENT FUNCTIONS
// ============================================================

// ============================================================
// v3: AI MANAGER FUNCTIONS
// ============================================================

/**
 * POST to ai_manager /invoke (fire and forget).
 * PHP passes all required data including the API key and current session UUID.
 * Returns true if ai_manager accepted the request, false on failure (triggers fallback).
 */
function invokeAIManager($conn, $session_id, $provider, $message, $pinned_context) {
    $model = getSelectedModel($conn, $session_id, $provider,
        $provider === 'claude' ? 'claude-sonnet-4-20250514' : 'gemini-2.5-flash');

    // Get current CLI session UUID and permissions
    $uuidColumn = $provider === 'claude' ? 'claude_cli_session_uuid' : 'gemini_cli_session_uuid';
    $permCols   = $provider === 'claude'
        ? 'claude_can_write, claude_can_delete, claude_can_run, claude_can_terminal, claude_can_packages, claude_lead, claude_perms_notify'
        : 'gemini_can_write, gemini_can_delete, gemini_can_run, gemini_can_terminal, gemini_can_packages, gemini_lead, gemini_perms_notify';
    $stmt = $conn->prepare("SELECT $uuidColumn, $permCols FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $session_uuid = $row[$uuidColumn] ?? null;
    $perms = $row ?: [];

    $project_path = getProjectPath($conn, $session_id) ?: '/tmp';

    // Build system prompt only for first message (no UUID yet)
    $system_prompt = null;
    if (!$session_uuid) {
        $system_prompt = buildCLISystemPrompt($provider, $pinned_context, $perms);
        // For Gemini (no --append-system-prompt flag), prepend to message
        if ($provider === 'gemini') {
            $message = $system_prompt . "\n\n---\n\nUser message:\n" . $message;
            $system_prompt = null;
        }
    } else {
        // Resume path — inject permissions reminder only if permissions changed since last turn
        $notifyCol = $provider . '_perms_notify';
        if (!empty($row[$notifyCol])) {
            $permReminder = buildPermissionsReminder($provider, $perms);
            $conn->query("UPDATE braintrust_turn_state SET $notifyCol = 0 WHERE session_id = $session_id");
            if ($provider === 'gemini') {
                $protoReminder = "PROTOCOL REMINDER: To create or update a file, output CREATE_FILE: filename on one line, then a fenced code block with the full content. Do NOT claim a file was created without outputting that block.\n\n";
                $message = $protoReminder . $permReminder . "User message:\n" . $message;
            } else {
                $message = $permReminder . $message;
            }
        } elseif ($provider === 'gemini') {
            $protoReminder = "PROTOCOL REMINDER: To create or update a file, output CREATE_FILE: filename on one line, then a fenced code block with the full content. Do NOT claim a file was created without outputting that block.\n\nUser message:\n";
            $message = $protoReminder . $message;
        }
    }

    // Callback URL — ai_manager POSTs here when AI response is complete
    $callback_url = 'http://127.0.0.1/collabchat/braintrust_api.php?action=ai_response_complete';

    $payload = json_encode([
        'session_id'     => $session_id,
        'provider'       => $provider,
        'message'        => $message,
        'model'          => $model,
        'session_uuid'   => $session_uuid,
        'system_prompt'  => $system_prompt,
        'api_key'        => $provider === 'claude' ? CLAUDE_API_KEY : GEMINI_API_KEY,
        'project_path'   => $project_path,
        'callback_url'   => $callback_url,
        'pinned_context' => $pinned_context,
    ]);

    $ch = curl_init('http://127.0.0.1:8084/invoke');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000);      // 2s to accept — ai_manager should respond 202 fast
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 202) {
        return true;
    }

    error_log("v3: ai_manager not available (HTTP $httpCode). Falling back to CLI path.");
    return false;
}

/**
 * v3 callback: ai_manager POSTs here when an AI response is complete.
 * Saves the message, advances turn, triggers next AI or notifies human.
 * Only callable from 127.0.0.1 (enforced above the switch).
 */
function aiResponseComplete($conn) {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid payload']);
        return;
    }

    $session_id     = intval($data['session_id']   ?? 0);
    $provider       = $data['provider']             ?? '';
    $full_text      = $data['full_text']            ?? '';
    $new_uuid       = $data['new_uuid']             ?? null;
    $pinned_context = $data['pinned_context']       ?? '';

    if (!$session_id || !in_array($provider, ['claude', 'gemini']) || empty($full_text)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }

    // Save AI message to DB
    saveAIMessage($conn, $session_id, $provider, $full_text);

    // Update CLI session UUID if changed
    if ($new_uuid) {
        $col  = $provider === 'claude' ? 'claude_cli_session_uuid' : 'gemini_cli_session_uuid';
        $stmt = $conn->prepare("UPDATE braintrust_turn_state SET $col = ? WHERE session_id = ?");
        $stmt->bind_param("si", $new_uuid, $session_id);
        $stmt->execute();
        $stmt->close();
    }

    // Guard: if SHUT UP was pressed while the AI was responding, the turn was already
    // reset to 'human' — don't advance or re-invoke anything.
    $stmt = $conn->prepare("SELECT current_turn_type FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $preAdvance = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (($preAdvance['current_turn_type'] ?? 'human') === 'human') {
        notifyWebSocket($session_id, 'new_message');
        echo json_encode(['success' => true]);
        return;
    }

    // Check for READ_FILE commands — if the AI requested files, inject the contents
    // and re-invoke the SAME AI without advancing the turn. Loops until the AI stops
    // issuing READ_FILE, then falls through to normal turn advance.
    // SHUT UP guard (checked above) still applies — if human reset the turn, we already returned.
    $readFileContents = extractReadFileContents($conn, $session_id, $full_text);
    if (!empty($readFileContents)) {
        $fileMsg = "Here are the file contents you requested:\n" . $readFileContents;
        $ok = invokeAIManager($conn, $session_id, $provider, $fileMsg, $pinned_context);
        if (!$ok) {
            // Sync fallback — re-invoke same provider directly
            $resp = ($provider === 'claude')
                ? getClaudeResponse($conn, $session_id, $pinned_context)
                : getGeminiResponse($conn, $session_id, $pinned_context);
            if ($resp) {
                saveAIMessage($conn, $session_id, $provider, $resp);
                advanceTurn($conn, $session_id);
                notifyWebSocket($session_id, 'new_message');
            }
        }
        echo json_encode(['success' => true]);
        return;
    }

    // Advance turn (advanceTurn handles floor mode internally — keeps floor AI's turn)
    advanceTurn($conn, $session_id);

    // Read new turn state
    $stmt = $conn->prepare("SELECT * FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $turn_state = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nextTurn = $turn_state['current_turn_type'] ?? 'human';

    if (in_array($nextTurn, ['claude', 'gemini'])) {
        // Next AI's turn — covers normal chaining (claude→gemini) AND floor mode loops.
        // In floor mode there are no new messages since the AI just spoke, so fall back to
        // "Continue." rather than $full_text (which would echo the AI's own last response back).
        $nextMsg = getMessagesSinceLastResponse($conn, $session_id, $nextTurn) ?: 'Continue.';
        $ok = invokeAIManager($conn, $session_id, $nextTurn, $nextMsg, $pinned_context);
        if (!$ok) {
            // Sync fallback
            $resp = ($nextTurn === 'claude')
                ? getClaudeResponse($conn, $session_id, $pinned_context)
                : getGeminiResponse($conn, $session_id, $pinned_context);
            if ($resp) {
                saveAIMessage($conn, $session_id, $nextTurn, $resp);
                advanceTurn($conn, $session_id);
                notifyWebSocket($session_id, 'new_message');
            }
        }
        // If ok, the next AI will callback when done
    } else {
        // Turn is back to human — notify WebSocket to refresh
        notifyWebSocket($session_id, 'new_message');
    }

    echo json_encode(['success' => true]);
}

// ── v3: fire callback handler now that all functions are defined ──
if (defined('HANDLE_AI_CALLBACK')) {
    aiResponseComplete($conn);
    exit();
}

switch ($action) {
    case 'get_session':
        getSession($conn, $user_id);
        break;
    case 'send_message':
        sendMessage($conn, $user_id);
        break;
    case 'ai_collab':
        aiCollab($conn, $user_id);
        break;
    case 'shut_up':
        shutUp($conn, $user_id);
        break;
    case 'toggle_ai':
        toggleAI($conn, $user_id);
        break;
    case 'set_floor':
        setFloor($conn, $user_id);
        break;
    case 'export':
        exportChat($conn, $user_id);
        break;
    case 'create_session':
        createSession($conn, $user_id);
        break;
    case 'list_sessions':
        listSessions($conn, $user_id);
        break;
    case 'delete_session':
        deleteSession($conn, $user_id);
        break;
    case 'set_model':
        setModel($conn, $user_id);
        break;
    case 'export_filtered':
        exportFiltered($conn, $user_id);
        break;
    case 'get_cli_log':
        getCliLog($conn, $user_id);
        break;
    case 'toggle_bookmark':
        toggleBookmark($conn, $user_id);
        break;
    case 'get_bookmarks':
        getBookmarks($conn, $user_id);
        break;
    case 'get_ai_permissions':
        getAIPermissions($conn, $user_id);
        break;
    case 'set_ai_permission':
        setAIPermission($conn, $user_id);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Get full session data
 */
function getSession($conn, $user_id) {
    $session_id = intval($_GET['session_id'] ?? 0);
    
    // Verify user has access
    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }
    
    // Get session info
    $stmt = $conn->prepare("SELECT * FROM braintrust_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get participants
    $stmt = $conn->prepare("
        SELECT bm.*, u.username, u.full_name 
        FROM braintrust_members bm 
        JOIN users u ON bm.user_id = u.id 
        WHERE bm.session_id = ? 
        ORDER BY bm.turn_order
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get messages (last 50 for context window)
    $stmt = $conn->prepare("
        SELECT m.*, u.username as sender_name
        FROM braintrust_messages m
        LEFT JOIN users u ON m.sender_user_id = u.id
        WHERE m.session_id = ?
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $messages = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    
    // Get turn state
    $stmt = $conn->prepare("SELECT * FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $turn_state = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get stats
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as message_count,
            SUM(token_count) as total_tokens
        FROM braintrust_messages 
        WHERE session_id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'session' => $session,
        'participants' => $participants,
        'messages' => $messages,
        'turn_state' => $turn_state,
        'stats' => $stats
    ]);
}

/**
 * Send a message and trigger AI responses
 */
function sendMessage($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $pinned_context = trim($_POST['pinned_context'] ?? '');

    // Validate and extract attached image (data URL)
    $attached_image = $_POST['attached_image'] ?? '';
    if (!empty($attached_image) && !preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/', $attached_image)) {
        $attached_image = ''; // reject anything that's not a valid image data URL
    }

    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Empty message']);
        return;
    }

    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Get user's turn order
    $stmt = $conn->prepare("SELECT turn_order FROM braintrust_members WHERE session_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Save user message (with optional image)
    $stmt = $conn->prepare("
        INSERT INTO braintrust_messages (session_id, sender_type, sender_user_id, message_text, image_data, token_count)
        VALUES (?, 'human', ?, ?, ?, ?)
    ");
    $token_count = estimateTokens($message);
    $stmt->bind_param("iissi", $session_id, $user_id, $message, $attached_image, $token_count);
    $stmt->execute();
    $stmt->close();

    // Notify WebSocket clients that human sent a message
    notifyWebSocket($session_id, 'new_message');

    // NOTE: Floor is NOT cleared when human speaks
    // This allows setting floor -> giving instructions -> AI executes multiple turns
    // Floor is only cleared when user manually changes dropdown to "Nobody"

    // Advance turn
    advanceTurn($conn, $session_id);
    
    // Get current turn state
    $stmt = $conn->prepare("SELECT * FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $turn_state = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // If it's an AI's turn, invoke via ai_manager (v3) or fall back to sync CLI (v2)
    if ($turn_state['current_turn_type'] === 'claude' || $turn_state['current_turn_type'] === 'gemini') {
        $firstProvider = $turn_state['current_turn_type'];
        $recentMessages = getMessagesSinceLastResponse($conn, $session_id, $firstProvider);
        $msgToSend = $recentMessages ?: $message;

        // ── v3 path: delegate to ai_manager (streaming, async) ──
        // Skip ai_manager when image is attached — CLI doesn't support vision; use API path instead
        $aiManagerOk = empty($attached_image)
            ? invokeAIManager($conn, $session_id, $firstProvider, $msgToSend, $pinned_context)
            : false;

        if ($aiManagerOk) {
            // ai_manager accepted — return immediately, AI response comes via callback
            echo json_encode(['success' => true, 'streaming' => true]);
            return;
        }

        // ── v2 fallback: synchronous CLI path ──
        error_log("v3 sendMessage: falling back to sync CLI for session $session_id");

        if ($firstProvider === 'claude') {
            $ai_response = getClaudeResponse($conn, $session_id, $pinned_context, !empty($attached_image));
            saveAIMessage($conn, $session_id, 'claude', $ai_response);
            advanceTurn($conn, $session_id);

            $stmt = $conn->prepare("SELECT * FROM braintrust_turn_state WHERE session_id = ?");
            $stmt->bind_param("i", $session_id);
            $stmt->execute();
            $turn_state = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($turn_state['current_turn_type'] === 'gemini') {
            $ai_response = getGeminiResponse($conn, $session_id, $pinned_context, !empty($attached_image));
            saveAIMessage($conn, $session_id, 'gemini', $ai_response);
            advanceTurn($conn, $session_id);
        }
    }

    echo json_encode(['success' => true]);
}

/**
 * Extract READ_FILE commands from AI message and read file contents
 */
function extractReadFileContents($conn, $session_id, $message_text) {
    $project_path = getProjectPath($conn, $session_id);
    if (!$project_path) return '';

    // Match READ_FILE commands — must be at line start to avoid matching inline examples
    preg_match_all('/^[ \t]*READ_FILE:\s*`?([^\s\n`,;]+)`?/im', $message_text, $matches);

    if (empty($matches[1])) return '';

    $fileContents = "\n\n### FILES REQUESTED (READ_FILE):\n";

    foreach ($matches[1] as $filename) {
        $file_path = $project_path . '/' . $filename;

        // Security: prevent path traversal
        $real_path = realpath($file_path);
        $real_project = realpath($project_path);

        if ($real_path && $real_project && strpos($real_path, $real_project) === 0 && file_exists($real_path)) {
            $content = file_get_contents($real_path);

            // Truncate large files to prevent context overflow (max 10000 chars per file)
            $maxFileSize = 10000;
            if (strlen($content) > $maxFileSize) {
                $content = substr($content, 0, $maxFileSize) . "\n\n[... File truncated - showing first {$maxFileSize} characters ...]";
            }

            $fileContents .= "\n--- File: {$filename} ---\n";
            $fileContents .= $content;
            $fileContents .= "\n--- End of {$filename} ---\n";
        } else {
            $fileContents .= "\n[File not found: {$filename}]\n";
        }
    }

    return $fileContents;
}

/**
 * Get Claude API response
 */
function getClaudeResponse($conn, $session_id, $pinned_context, $has_image = false) {
    // --- CLI AGENT PATH (preferred, skipped when message has an image) ---
    if (!$has_image) {
        $recentMessages = getMessagesSinceLastResponse($conn, $session_id, 'claude');
        if ($recentMessages) {
            $cliResponse = getClaudeResponseCLI($conn, $session_id, $recentMessages, $pinned_context);
            if ($cliResponse !== null) {
                error_log("CLI Claude: SUCCESS for session $session_id (CLI path used)");
                return $cliResponse;
            }
            error_log("CLI Claude: FALLBACK to API for session $session_id");
        }
    } else {
        error_log("Claude: image attached — skipping CLI, using API (vision) path for session $session_id");
    }

    // --- API FALLBACK ---
    $context = buildConversationContext($conn, $session_id, $pinned_context);

    $system_instruction = "You are Claude, an AI collaborator in BrainTrust IDE, working with a human developer.

CONTEXT FOR THIS PROJECT:
{$pinned_context}

You have direct access to the server's file system and terminal via specialized protocols.

### ACTION PROTOCOLS
1. FILE CREATION: To create a new file or overwrite an existing one, you MUST use:
CREATE_FILE: path/to/filename.ext
```language
[full file content here]
```

2. FILE READING: To request that a file be opened in the editor, use:
READ_FILE: path/to/filename.ext
(The file contents will be returned to you automatically).

3. FILE EXECUTION: To test a script and see the output in the terminal, use:
RUN_FILE: path/to/filename.ext

### OPERATIONAL GUIDELINES
1. Normally only output ONE action command per response.
2. Only take actions explicitly requested by the human. If you spot something that looks like it needs attention, mention it and ask — do not act unilaterally.
3. Use RUN_FILE after creating a script to verify it works or to troubleshoot errors.
4. Ensure filenames and paths are accurate to the project structure.

### WHITEBOARD DIAGRAMS:
To draw on the whiteboard, include a mermaid code block in your response — the IDE renders it automatically. No tool, no command. Just write the mermaid block. Rules: use 'graph TD' or 'graph LR', quote node text with special characters, never use 'end' as a node ID, no // comments, no style/classDef overrides.

### COLLABORATION RULES:
1. Be concise - this is a real-time working session.
2. Build on Gemini's contributions, making suggestions on how to improve the project.
3. Think about unforeseeable challenges and bring them up in the conversation.
4. Focus on solving challenges and being a helpful part of the engineering team.";

    $data = [
        'model' => getSelectedModel($conn, $session_id, 'claude', 'claude-sonnet-4-20250514'),
        'max_tokens' => 12288,
        'system' => $system_instruction,
        'messages' => $context
    ];

    $json_body = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    if ($json_body === false) {
        error_log("Claude json_encode failed: " . json_last_error_msg());
        return "Sorry, I encountered an encoding error. Please try again.";
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        error_log("Claude API Error (HTTP $http_code): " . $response);
        $err_data = json_decode($response, true);
        $err_msg = $err_data['error']['message'] ?? $response;
        return "Claude API Error (HTTP $http_code): $err_msg";
    }

    $response_data = json_decode($response, true);
    return $response_data['content'][0]['text'] ?? "No response generated.";
}

/**
 * Get Gemini API response
 */
function getGeminiResponse($conn, $session_id, $pinned_context, $has_image = false) {
    // --- CLI AGENT PATH (preferred, skipped when message has an image) ---
    if (!$has_image) {
        $recentMessages = getMessagesSinceLastResponse($conn, $session_id, 'gemini');
        if ($recentMessages) {
            $cliResponse = getGeminiResponseCLI($conn, $session_id, $recentMessages, $pinned_context);
            if ($cliResponse !== null) {
                error_log("CLI Gemini: SUCCESS for session $session_id (CLI path used)");
                return $cliResponse;
            }
            error_log("CLI Gemini: FALLBACK to API for session $session_id");
        }
    } else {
        error_log("Gemini: image attached — skipping CLI, using API (vision) path for session $session_id");
    }

    // --- API FALLBACK ---
    $context = buildConversationContextForGemini($conn, $session_id, $pinned_context);

    // If context is empty or only has one message, add a starter
    if (empty($context)) {
        $context = [
            ['role' => 'user', 'parts' => [['text' => 'Hello, please introduce yourself.']]]
        ];
    }

    // Gemini requires first message to be 'user' role - ensure this
    if ($context[0]['role'] === 'model') {
        array_unshift($context, [
            'role' => 'user',
            'parts' => [['text' => '[System]: Conversation history follows.']]
        ]);
    }

    $system_instruction = "You are Gemini, an AI collaborator in BrainTrust IDE.

CONTEXT FOR THIS PROJECT:
{$pinned_context}

You have direct access to the server's file system and terminal via specialized protocols.

### ACTION PROTOCOLS
1. FILE CREATION: To create a new file or overwrite an existing one, you MUST use:
CREATE_FILE: path/to/filename.ext
```language
[full file content here]
```

2. FILE READING: To see the current contents of a file before editing it, use:
READ_FILE: path/to/filename.ext
(The file contents will be returned to you automatically).

3. FILE EXECUTION: To test a script and see the output in the terminal, use:
RUN_FILE: path/to/filename.ext

### OPERATIONAL GUIDELINES
1. Normally only output ONE action command per response.
2. Only take actions explicitly requested by the human. If you spot something that looks like it needs attention, mention it and ask — do not act unilaterally.
3. Use RUN_FILE after creating a script to verify it works or to troubleshoot errors.
4. Ensure filenames and paths are accurate to the project structure.

### WHITEBOARD DIAGRAMS:
To draw on the whiteboard, include a mermaid code block in your response — the IDE renders it automatically. No tool, no command. Just write the mermaid block. Rules: use 'graph TD' or 'graph LR', quote node text with special characters, never use 'end' as a node ID, no // comments, no style/classDef overrides.

### COLLABORATION RULES:
1. Be concise - this is a real-time working session.
2. Build on Claude's contributions, making suggestions on how to improve the project.
3. Think about unforeseeable challenges and bring them up in the conversation.
4. Focus on solving challenges and being a helpful part of the engineering team.";

    $data = [
        'contents' => $context,
        'systemInstruction' => [
            'parts' => [['text' => $system_instruction]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => 12288,
            'temperature' => 0.7
        ]
    ];
    $model_name = getSelectedModel($conn, $session_id, 'gemini', 'gemini-2.5-flash');
    $api_url = "https://generativelanguage.googleapis.com/v1beta/models/{$model_name}:generateContent?key=" . GEMINI_API_KEY;
    
    $json_body = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    if ($json_body === false) {
        error_log("Gemini json_encode failed: " . json_last_error_msg());
        return "Sorry, I encountered an encoding error. Please try again.";
    }

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("Gemini cURL Error: " . $curl_error);
        return "Sorry, I encountered a network error. Please try again.";
    }
    
    if ($http_code !== 200) {
        error_log("Gemini API Error (HTTP $http_code): " . $response);
        return "Sorry, I encountered an error (HTTP $http_code). Please try again.";
    }
    
    $response_data = json_decode($response, true);
    return $response_data['candidates'][0]['content']['parts'][0]['text'] ?? "No response generated.";
}
/**
 * Build conversation context for Claude (API fallback only)
 */
function buildConversationContext($conn, $session_id, $pinned_context) {
    $stmt = $conn->prepare("
        SELECT m.*, u.username as sender_name
        FROM braintrust_messages m
        LEFT JOIN users u ON m.sender_user_id = u.id
        WHERE m.session_id = ? AND m.is_summary = 0
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $messages = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    
    $context = [];
    foreach ($messages as $msg) {
        $sender = $msg['sender_type'] === 'human' ? $msg['sender_name'] : ucfirst($msg['sender_type']);
        $role = ($msg['sender_type'] === 'claude') ? 'assistant' : 'user';

        // STEALTH MODE: Hide raw mermaid code from chat history context
        $clean_text = preg_replace('/```mermaid\s*[\s\S]*?```/i', '[DIAGRAM GENERATED - VIEW ON WHITEBOARD]', $msg['message_text']);
        $text_content = ($msg['sender_type'] !== 'claude') ? "[{$sender}]: " . $clean_text : $clean_text;

        // If this message has an attached image, send as multimodal content block
        if (!empty($msg['image_data']) && preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,(.+)$/s', $msg['image_data'], $imgMatch)) {
            $media_type = 'image/' . ($imgMatch[1] === 'jpg' ? 'jpeg' : $imgMatch[1]);
            $content = [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $media_type, 'data' => $imgMatch[2]]],
                ['type' => 'text', 'text' => $text_content]
            ];
        } else {
            $content = $text_content;
        }

        $context[] = [
            'role' => $role,
            'content' => $content
        ];
    }

    return $context;
}

/**
 * Build conversation context for Gemini
 */
function buildConversationContextForGemini($conn, $session_id, $pinned_context) {
    $stmt = $conn->prepare("
        SELECT m.*, u.username as sender_name
        FROM braintrust_messages m
        LEFT JOIN users u ON m.sender_user_id = u.id
        WHERE m.session_id = ?
        ORDER BY m.created_at DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $messages = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    
    $context = [];
    foreach ($messages as $msg) {
        $sender = $msg['sender_type'] === 'human' ? $msg['sender_name'] : ucfirst($msg['sender_type']);
        $role = ($msg['sender_type'] === 'gemini') ? 'model' : 'user';

        $text_content = ($msg['sender_type'] !== 'gemini') ? "[{$sender}]: " . $msg['message_text'] : $msg['message_text'];

        // If this message has an attached image, include as inline_data part
        if (!empty($msg['image_data']) && preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,(.+)$/s', $msg['image_data'], $imgMatch)) {
            $media_type = 'image/' . ($imgMatch[1] === 'jpg' ? 'jpeg' : $imgMatch[1]);
            $parts = [
                ['inline_data' => ['mime_type' => $media_type, 'data' => $imgMatch[2]]],
                ['text' => $text_content]
            ];
        } else {
            $parts = [['text' => $text_content]];
        }

        $context[] = [
            'role' => $role,
            'parts' => $parts
        ];
    }

    return $context;
}
/**
 * Save AI message to database
 */
function saveAIMessage($conn, $session_id, $ai_type, $message) {
    $token_count = estimateTokens($message);
    $stmt = $conn->prepare("
        INSERT INTO braintrust_messages (session_id, sender_type, message_text, token_count)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("issi", $session_id, $ai_type, $message, $token_count);
    $stmt->execute();
    $stmt->close();
    
    // Check for whiteboard content (mermaid diagrams)
    if (preg_match('/```mermaid\n([\s\S]*?)```/', $message, $matches)) {
        $stmt = $conn->prepare("
            INSERT INTO braintrust_whiteboard (session_id, title, diagram_type, content, created_by_type)
            VALUES (?, 'Diagram', 'mermaid', ?, ?)
        ");
        $stmt->bind_param("iss", $session_id, $matches[1], $ai_type);
        $stmt->execute();
        $stmt->close();
    }
    
    // Process AI Tool Calls (CREATE_FILE, etc.)
    processAIToolCalls($conn, $session_id, $message, $ai_type);

    // Notify WebSocket clients
    notifyWebSocket($session_id, 'new_message');
}

/**
 * Process AI Tool Calls (Auto-Execution)
 */
function processAIToolCalls($conn, $session_id, $message, $ai_type = '') {
    // 1. Get Project Path
    $stmt = $conn->prepare("
        SELECT p.project_path 
        FROM braintrust_sessions s 
        JOIN braintrust_projects p ON s.project_id = p.id 
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result) return;
    
    // Define root (hardcoded for safety/consistency)
    $projects_root = '/var/www/html/collabchat/projects/';
    $project_dir = $projects_root . $result['project_path'];
    
    // 2. CREATE_FILE Logic
    // Pattern: CREATE_FILE: <filename> ... ```<lang> <content> ```
    // We use a regex that captures the filename and the FIRST code block following it
    if (preg_match('/CREATE_FILE:\s*([^\s\n]+)(?:[\s\S]*?)```(?:\w+)?\n([\s\S]*?)```/', $message, $matches)) {
        // Check can_write permission for this AI
        if ($ai_type) {
            $permCol = $ai_type . '_can_write';
            $pStmt = $conn->prepare("SELECT $permCol FROM braintrust_turn_state WHERE session_id = ?");
            $pStmt->bind_param("i", $session_id);
            $pStmt->execute();
            $pRow = $pStmt->get_result()->fetch_assoc();
            $pStmt->close();
            if (isset($pRow[$permCol]) && !$pRow[$permCol]) {
                // Permission denied — insert system notice and bail
                $notice = "⛔ File write blocked: " . ucfirst($ai_type) . "'s **Write Files** permission is disabled for this session.";
                $nStmt = $conn->prepare("INSERT INTO braintrust_messages (session_id, sender_type, message_text) VALUES (?, 'system', ?)");
                $nStmt->bind_param("is", $session_id, $notice);
                $nStmt->execute();
                $nStmt->close();
                notifyWebSocket($session_id, 'new_message');
                return;
            }
        }
        $rel_path = trim($matches[1]);
        // Strip stray markdown formatting chars (**, `, etc.) from filename
        $rel_path = trim($rel_path, '*`\'"');
        $content = $matches[2];

        // Security: Block path traversal
        if (strpos($rel_path, '..') !== false || strpos($rel_path, '/') === 0) {
            return; 
        }
        
        $full_path = $project_dir . '/' . $rel_path;
        $dir = dirname($full_path);
        
        // Ensure directory exists
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        
        // Snapshot: Save current version before AI overwrites
        if (file_exists($full_path)) {
            createFileSnapshot($project_dir, $rel_path);
        }

        // Write the file
        file_put_contents($full_path, $content);
    }
}

/**
 * Create a snapshot of a file before overwriting it.
 * Stores in .snapshots/ directory inside the project folder.
 */
function createFileSnapshot($projectDir, $relPath) {
    $fullPath = rtrim($projectDir, '/') . '/' . ltrim($relPath, '/');
    if (!file_exists($fullPath)) return false;

    $content = file_get_contents($fullPath);
    if ($content === false) return false;

    $snapshotDir = rtrim($projectDir, '/') . '/.snapshots';
    if (!is_dir($snapshotDir)) {
        mkdir($snapshotDir, 0775, true);
    }

    $safeName = str_replace(['/', '\\'], '--', ltrim($relPath, '/'));
    $snapshotFile = $safeName . '_' . time();
    file_put_contents($snapshotDir . '/' . $snapshotFile, $content);

    // Prune: keep max 20 snapshots per file
    $files = glob($snapshotDir . '/' . $safeName . '_*');
    if (count($files) > 20) {
        usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
        $toDelete = count($files) - 20;
        for ($i = 0; $i < $toDelete; $i++) {
            unlink($files[$i]);
        }
    }

    return $snapshotFile;
}

/**
 * Advance turn to next participant
 */
function advanceTurn($conn, $session_id) {
    $stmt = $conn->prepare("SELECT * FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$state) {
        // Initialize turn state with Claude and Gemini enabled
        $stmt = $conn->prepare("
            INSERT INTO braintrust_turn_state
            (session_id, claude_enabled, gemini_enabled)
            VALUES (?, 1, 1)
        ");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $stmt->close();
        return;
    }
    
    // Get flags
    $claude_enabled = $state['claude_enabled'] ?? 1;
    $gemini_enabled = $state['gemini_enabled'] ?? 1;
    $floor_holder = $state['floor_holder'] ?? null;
    
    $current_type = $state['current_turn_type'];
    $current_human_order = $state['current_human_order'];

    // 👑 FLOOR MODE: If an AI has the floor and current turn is NOT human,
    // keep giving that AI the turn
    if ($floor_holder && $current_type !== 'human') {
        $stmt = $conn->prepare("
            UPDATE braintrust_turn_state 
            SET current_turn_type = ?, current_human_order = 1, updated_at = CURRENT_TIMESTAMP
            WHERE session_id = ?
        ");
        $stmt->bind_param("si", $floor_holder, $session_id);
        $stmt->execute();
        $stmt->close();
        return;
    }

    // Get number of human participants
    $stmt = $conn->prepare("SELECT MAX(turn_order) as max_order FROM braintrust_members WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $max_human_order = $result['max_order'] ?? 1;
    $stmt->close();
    
    // Logic: Human -> Claude (if on) -> Gemini (if on) -> Human
    // Any human message advances directly to AI turn (no sequential human ordering)
    if ($current_type === 'human') {
        if ($claude_enabled) {
            $new_type = 'claude';
            $new_order = 1;
        } elseif ($gemini_enabled) {
            $new_type = 'gemini';
            $new_order = 1;
        } else {
            $new_type = 'human';
            $new_order = 1;
        }
    } elseif ($current_type === 'claude') {
        if ($gemini_enabled) {
            $new_type = 'gemini';
            $new_order = 1;
        } else {
            $new_type = 'human';
            $new_order = 1;
        }
    } else {
        // From Gemini, always back to Human
        $new_type = 'human';
        $new_order = 1;
    }
    
    $stmt = $conn->prepare("
        UPDATE braintrust_turn_state 
        SET current_turn_type = ?, current_human_order = ?, updated_at = CURRENT_TIMESTAMP
        WHERE session_id = ?
    ");
    $stmt->bind_param("sii", $new_type, $new_order, $session_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * AI Collab Mode - Let enabled AIs chat back and forth without human input.
 * Loops through AI turns until the cap is hit or SHUT UP is triggered.
 * WebSocket notifications fire after each AI message so the user sees them in real-time.
 */
function aiCollab($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $max_messages = 10; // Cap: max AI messages before stopping

    set_time_limit(600); // 10 minutes max for the full collab session

    // Release PHP session lock so WebSocket-triggered get_session requests
    // can proceed while collab is running (enables real-time message display)
    session_write_close();

    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Get pinned context (needed for AI responses)
    $stmt = $conn->prepare("SELECT pinned_context FROM braintrust_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pinned_context = $session['pinned_context'] ?? '';

    // Add system message announcing collab mode
    $stmt = $conn->prepare("
        INSERT INTO braintrust_messages (session_id, sender_type, message_text)
        VALUES (?, 'system', ?)
    ");
    $msg = "🤝 AI Collab mode activated — AIs will discuss among themselves (up to {$max_messages} messages). Click SHUT UP to jump in.";
    $stmt->bind_param("is", $session_id, $msg);
    $stmt->execute();
    $stmt->close();
    notifyWebSocket($session_id, 'new_message');

    // Mark collab as active (consecutive_ai_turns > 0) so the SHUT UP detection works.
    // SHUT UP resets consecutive_ai_turns to 0 — that's how we detect it.
    $stmt = $conn->prepare("UPDATE braintrust_turn_state SET consecutive_ai_turns = 1 WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();

    // Set turn to first enabled AI
    advanceTurn($conn, $session_id);

    $ai_message_count = 0;

    while ($ai_message_count < $max_messages) {
        // Re-read turn state each iteration (for SHUT UP detection)
        $stmt = $conn->prepare("SELECT * FROM braintrust_turn_state WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $turn_state = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // SHUT UP detection: consecutive_ai_turns reset to 0 means user interrupted
        if (($turn_state['consecutive_ai_turns'] ?? 0) === 0 && $ai_message_count > 0) {
            break;
        }

        $current = $turn_state['current_turn_type'];

        // If turn is back to human, skip to next AI
        if ($current === 'human') {
            advanceTurn($conn, $session_id);

            // Re-check after advancing
            $stmt = $conn->prepare("SELECT current_turn_type FROM braintrust_turn_state WHERE session_id = ?");
            $stmt->bind_param("i", $session_id);
            $stmt->execute();
            $check = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($check['current_turn_type'] === 'human') {
                // No AIs enabled — nothing to do
                break;
            }
            $current = $check['current_turn_type'];
        }

        // Sync AI call — saveAIMessage() inside notifies WS so browser updates in real-time
        $ai_response = ($current === 'claude')
            ? getClaudeResponse($conn, $session_id, $pinned_context)
            : getGeminiResponse($conn, $session_id, $pinned_context);

        if ($ai_response) {
            saveAIMessage($conn, $session_id, $current, $ai_response);
            $ai_message_count++;
        } else {
            // AI failed to respond — stop to avoid infinite loop
            break;
        }

        // Advance to next turn
        advanceTurn($conn, $session_id);
    }

    // Return turn to human when done, clear collab flag
    $stmt = $conn->prepare("
        UPDATE braintrust_turn_state
        SET current_turn_type = 'human', current_human_order = 1, consecutive_ai_turns = 0
        WHERE session_id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();

    // Add system message announcing collab ended
    $stmt = $conn->prepare("
        INSERT INTO braintrust_messages (session_id, sender_type, message_text)
        VALUES (?, 'system', ?)
    ");
    $endMsg = "🤝 AI Collab ended — {$ai_message_count} messages exchanged. Your turn!";
    $stmt->bind_param("is", $session_id, $endMsg);
    $stmt->execute();
    $stmt->close();
    notifyWebSocket($session_id, 'new_message');

    echo json_encode(['success' => true, 'messages' => $ai_message_count]);
}

/**
 * Stop current AI response (shut up)
 */
function shutUp($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    
    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }
    
    // Reset to human turn
    $stmt = $conn->prepare("
        UPDATE braintrust_turn_state 
        SET current_turn_type = 'human', current_human_order = 1, consecutive_ai_turns = 0
        WHERE session_id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();
    
    // Add system message
    $stmt = $conn->prepare("
        INSERT INTO braintrust_messages (session_id, sender_type, message_text)
        VALUES (?, 'system', '🛑 AI response interrupted. Control returned to humans.')
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();

    notifyWebSocket($session_id, 'turn_change');

    echo json_encode(['success' => true]);
}

/**
 * Toggle AI participation
 */
function toggleAI($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $ai = $_POST['ai'] ?? '';
    $enabled = intval($_POST['enabled'] ?? 1);
    
    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }
    
    if ($ai === 'claude') {
    $stmt = $conn->prepare("UPDATE braintrust_turn_state SET claude_enabled = ? WHERE session_id = ?");
} elseif ($ai === 'gemini') {
    $stmt = $conn->prepare("UPDATE braintrust_turn_state SET gemini_enabled = ? WHERE session_id = ?");
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid AI']);
    return;
}
    
    $stmt->bind_param("ii", $enabled, $session_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true]);
}
/**
 * Set floor holder - gives an AI consecutive turns
 */
function setFloor($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $ai = $_POST['ai'] ?? 'none';
    
    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }
    
    // 'none' clears the floor, otherwise set the AI
    $newFloor = ($ai !== 'none') ? $ai : null;
    
    $stmt = $conn->prepare("UPDATE braintrust_turn_state SET floor_holder = ? WHERE session_id = ?");
    $stmt->bind_param("si", $newFloor, $session_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true, 'floor_holder' => $newFloor]);
}

/**
 * Set AI model selection for a provider
 */
function setModel($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $provider = $_POST['provider'] ?? '';
    $model = $_POST['model'] ?? '';

    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Whitelist of allowed models per provider
    $allowed_models = [
        'claude' => ['claude-sonnet-4-20250514', 'claude-opus-4-20250514', 'claude-sonnet-4-6', 'claude-opus-4-6'],
        'gemini' => ['gemini-2.5-flash', 'gemini-2.5-pro']
    ];

    if (!isset($allowed_models[$provider]) || !in_array($model, $allowed_models[$provider])) {
        echo json_encode(['success' => false, 'error' => 'Invalid provider or model']);
        return;
    }

    $column = $provider . '_model';
    $stmt = $conn->prepare("UPDATE braintrust_turn_state SET {$column} = ? WHERE session_id = ?");
    $stmt->bind_param("si", $model, $session_id);
    $stmt->execute();
    $stmt->close();

    // Reset CLI session when model changes (new model = new session)
    if ($provider === 'claude') {
        $stmt2 = $conn->prepare("UPDATE braintrust_turn_state SET claude_cli_session_uuid = NULL WHERE session_id = ?");
        $stmt2->bind_param("i", $session_id);
        $stmt2->execute();
        $stmt2->close();
    } elseif ($provider === 'gemini') {
        $stmt2 = $conn->prepare("UPDATE braintrust_turn_state SET gemini_cli_session_uuid = NULL WHERE session_id = ?");
        $stmt2->bind_param("i", $session_id);
        $stmt2->execute();
        $stmt2->close();
    }

    echo json_encode(['success' => true]);
}

/**
 * Get the user-selected model for an AI provider, or fall back to default
 */
function getSelectedModel($conn, $session_id, $provider, $default) {
    $allowed_columns = ['claude_model', 'gemini_model'];
    $column = $provider . '_model';
    if (!in_array($column, $allowed_columns)) return $default;

    $stmt = $conn->prepare("SELECT {$column} FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $model = $result[$column] ?? null;
    return $model ? $model : $default;
}

/**
 * Export chat as markdown
 */
function exportChat($conn, $user_id) {
    $session_id = intval($_GET['session_id'] ?? 0);
    
    if (!userHasAccess($conn, $user_id, $session_id)) {
        die('Access denied');
    }
    
    // Get session
    $stmt = $conn->prepare("SELECT * FROM braintrust_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get messages
    $stmt = $conn->prepare("
        SELECT m.*, u.username as sender_name
        FROM braintrust_messages m
        LEFT JOIN users u ON m.sender_user_id = u.id
        WHERE m.session_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Build markdown
    $md = "# {$session['session_name']}\n\n";
    $md .= "**Exported:** " . date('Y-m-d H:i:s') . "\n\n";
    
    if (!empty($session['pinned_context'])) {
        $md .= "## Pinned Context\n\n{$session['pinned_context']}\n\n";
    }
    
    $md .= "## Conversation\n\n";
    
    foreach ($messages as $msg) {
        $sender = $msg['sender_type'] === 'human' ? $msg['sender_name'] : ucfirst($msg['sender_type']);
        $time = date('H:i', strtotime($msg['created_at']));
        $md .= "### [{$time}] {$sender}\n\n{$msg['message_text']}\n\n---\n\n";
    }
    
    // Output as download
    header('Content-Type: text/markdown');
    header('Content-Disposition: attachment; filename="braintrust-export-' . date('Y-m-d') . '.md"');
    echo $md;
    exit();
}

/**
 * Enhanced export with filters
 */
function exportFiltered($conn, $user_id) {
    $session_id = intval($_GET['session_id'] ?? 0);
    $senderFilter = $_GET['sender'] ?? 'all';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $excludeSummaries = ($_GET['exclude_summaries'] ?? '0') === '1';

    if (!userHasAccess($conn, $user_id, $session_id)) {
        die('Access denied');
    }

    $stmt = $conn->prepare("SELECT * FROM braintrust_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $query = "SELECT m.*, u.username as sender_name
              FROM braintrust_messages m
              LEFT JOIN users u ON m.sender_user_id = u.id
              WHERE m.session_id = ?";
    $params = [$session_id];
    $types = "i";

    if ($senderFilter !== 'all') {
        $query .= " AND m.sender_type = ?";
        $params[] = $senderFilter;
        $types .= "s";
    }
    if (!empty($dateFrom)) {
        $query .= " AND DATE(m.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }
    if (!empty($dateTo)) {
        $query .= " AND DATE(m.created_at) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }
    if ($excludeSummaries) {
        $query .= " AND m.is_summary = 0";
    }

    $query .= " ORDER BY m.created_at ASC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $md = "# {$session['session_name']}\n\n";
    $md .= "**Exported:** " . date('Y-m-d H:i:s') . "\n";
    $md .= "**Filter:** Sender={$senderFilter}";
    if ($dateFrom) $md .= ", From={$dateFrom}";
    if ($dateTo) $md .= ", To={$dateTo}";
    if ($excludeSummaries) $md .= ", Summaries excluded";
    $md .= "\n**Messages:** " . count($messages) . "\n\n";

    if (!empty($session['pinned_context'])) {
        $md .= "## Pinned Context\n\n{$session['pinned_context']}\n\n";
    }

    $md .= "## Conversation\n\n";

    foreach ($messages as $msg) {
        $sender = $msg['sender_type'] === 'human' ? ($msg['sender_name'] ?? 'User') : ucfirst($msg['sender_type']);
        $time = date('Y-m-d H:i', strtotime($msg['created_at']));
        $md .= "### [{$time}] {$sender}\n\n{$msg['message_text']}\n\n---\n\n";
    }

    header('Content-Type: text/markdown');
    $filename = 'braintrust-export-' . date('Y-m-d') . '-' . $senderFilter . '.md';
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    echo $md;
    exit();
}

/**
 * Create new session
 */
function createSession($conn, $user_id) {
    $name = trim($_GET['name'] ?? 'New Session');
    
    // Create session
    $stmt = $conn->prepare("INSERT INTO braintrust_sessions (owner_id, session_name) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $name);
    $stmt->execute();
    $session_id = $conn->insert_id;
    $stmt->close();
    
    // Add owner as first member
    $stmt = $conn->prepare("INSERT INTO braintrust_members (session_id, user_id, role, turn_order) VALUES (?, ?, 'owner', 1)");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $stmt->close();
    
    // Initialize turn state
    $stmt = $conn->prepare("INSERT INTO braintrust_turn_state (session_id) VALUES (?)");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();
    
    // Redirect to new session
    header("Location: braintrust.php?session_id={$session_id}");
    exit();
}
/**
 * Get project path from session
 */
function getProjectPath($conn, $session_id) {
    $stmt = $conn->prepare("
        SELECT p.project_path 
        FROM braintrust_sessions s
        JOIN braintrust_projects p ON s.project_id = p.id
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $path = $result['project_path'] ?? null;
    
    // Convert relative path to absolute
    if ($path && $path[0] !== '/') {
        $path = '/var/www/html/collabchat/projects/' . $path;
    }
    
    return $path;
}
/**
 * List user's sessions
 */
function listSessions($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT s.*, 
               (SELECT COUNT(*) FROM braintrust_messages WHERE session_id = s.id) as message_count
        FROM braintrust_sessions s
        JOIN braintrust_members m ON s.id = m.session_id
        WHERE m.user_id = ? AND s.is_archived = 0
        ORDER BY s.updated_at DESC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode(['success' => true, 'sessions' => $sessions]);
}

/**
 * Check if user has access to session
 */
function userHasAccess($conn, $user_id, $session_id) {
    $stmt = $conn->prepare("SELECT id FROM braintrust_members WHERE session_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_access = $result->num_rows > 0;
    $stmt->close();
    return $has_access;
}

/**
 * Estimate token count (rough approximation)
 */
function estimateTokens($text) {
    // Roughly 4 characters per token
    return intval(strlen($text) / 4);
}
/**
 * Get CLI monitor log for real-time agent visibility.
 * Returns log content from byte offset for incremental reads.
 */

/**
 * Toggle bookmark flag on a message
 */
function toggleBookmark($conn, $user_id) {
    $msg_id     = intval($_POST['message_id'] ?? 0);
    $session_id = intval($_POST['session_id'] ?? 0);
    if (!$msg_id || !$session_id) {
        echo json_encode(['success' => false, 'error' => 'Missing fields']); return;
    }
    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']); return;
    }
    // Verify message belongs to session
    $stmt = $conn->prepare("SELECT bookmarked FROM braintrust_messages WHERE id = ? AND session_id = ?");
    $stmt->bind_param("ii", $msg_id, $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { echo json_encode(['success' => false, 'error' => 'Message not found']); return; }

    $new_val = $row['bookmarked'] ? 0 : 1;
    $stmt = $conn->prepare("UPDATE braintrust_messages SET bookmarked = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_val, $msg_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'bookmarked' => (bool)$new_val]);
}

/**
 * Get all bookmarked messages for a session
 */
function getBookmarks($conn, $user_id) {
    $session_id = intval($_GET['session_id'] ?? 0);
    if (!$session_id) { echo json_encode(['success' => false, 'error' => 'Missing session_id']); return; }
    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']); return;
    }
    $stmt = $conn->prepare("
        SELECT m.id, m.sender_type, m.message_text, m.created_at, u.username as sender_name
        FROM braintrust_messages m
        LEFT JOIN users u ON m.sender_user_id = u.id
        WHERE m.session_id = ? AND m.bookmarked = 1
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $bookmarks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['success' => true, 'bookmarks' => $bookmarks]);
}

function getCliLog($conn, $user_id) {
    $session_id = intval($_GET['session_id'] ?? 0);
    $provider = $_GET['provider'] ?? '';
    $offset = intval($_GET['offset'] ?? 0);

    if (!in_array($provider, ['claude', 'gemini'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid provider']);
        return;
    }

    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    $logFile = '/var/www/html/collabchat/logs/braintrust_cli_' . $session_id . '_' . $provider . '.log';
    $runningFile = $logFile . '.running';

    // Check running status (auto-clean stale flags older than 5 minutes)
    $running = false;
    if (file_exists($runningFile)) {
        if (filemtime($runningFile) < time() - 300) {
            @unlink($runningFile);
        } else {
            $running = true;
        }
    }

    if (!file_exists($logFile)) {
        echo json_encode(['success' => true, 'log' => '', 'running' => $running, 'offset' => 0]);
        return;
    }

    clearstatcache(true, $logFile);
    $fileSize = filesize($logFile);
    $newContent = '';

    $wasReset = false;
    if ($offset > $fileSize) {
        // File was truncated/rewritten (new invocation) — reset and read from start
        $offset = 0;
        $wasReset = true;
    }

    if ($offset > 0 && $offset < $fileSize) {
        $fh = fopen($logFile, 'r');
        fseek($fh, $offset);
        $newContent = fread($fh, $fileSize - $offset);
        fclose($fh);
    } elseif ($offset === $fileSize) {
        $newContent = '';
    } else {
        $newContent = file_get_contents($logFile);
        if (strlen($newContent) > 51200) {
            $newContent = "...(truncated)...\n" . substr($newContent, -51200);
        }
    }

    echo json_encode([
        'success' => true,
        'log' => $newContent,
        'running' => $running,
        'offset' => $fileSize,
        'reset' => $wasReset
    ]);
}

function deleteSession($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    
    // Safety check: ensure the user owns the session before deleting
    $stmt = $conn->prepare("SELECT id FROM braintrust_sessions WHERE id = ? AND owner_id = ?");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        return;
    }
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM braintrust_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $success = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $success]);
}
/**
 * Get AI permissions for a session
 */
function getAIPermissions($conn, $user_id) {
    $session_id = intval($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
    if (!$session_id || !userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']); return;
    }
    $stmt = $conn->prepare("SELECT claude_can_write, claude_can_delete, claude_can_run, claude_can_terminal, claude_can_packages, claude_lead, gemini_can_write, gemini_can_delete, gemini_can_run, gemini_can_terminal, gemini_can_packages, gemini_lead FROM braintrust_turn_state WHERE session_id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        // Return defaults if row doesn't exist yet
        $row = ['claude_can_write'=>1,'claude_can_delete'=>1,'claude_can_run'=>1,'claude_can_terminal'=>1,'claude_can_packages'=>1,'claude_lead'=>0,'gemini_can_write'=>1,'gemini_can_delete'=>1,'gemini_can_run'=>1,'gemini_can_terminal'=>1,'gemini_can_packages'=>1,'gemini_lead'=>0];
    }
    // Cast to int for clean JSON
    foreach ($row as $k => $v) $row[$k] = intval($v);
    echo json_encode(['success' => true, 'permissions' => $row]);
}

/**
 * Set a single AI permission for a session
 */
function setAIPermission($conn, $user_id) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $ai         = $_POST['ai'] ?? '';       // 'claude' or 'gemini'
    $perm       = $_POST['permission'] ?? ''; // e.g. 'can_write'
    $value      = intval($_POST['value'] ?? 1);

    if (!userHasAccess($conn, $user_id, $session_id)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']); return;
    }

    $allowed = ['can_write','can_delete','can_run','can_terminal','can_packages','lead'];
    $allowedAI = ['claude','gemini'];

    if (!in_array($ai, $allowedAI) || !in_array($perm, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameter']); return;
    }

    $col = $ai . '_' . $perm;
    $notifyCol = $ai . '_perms_notify';
    $stmt = $conn->prepare("UPDATE braintrust_turn_state SET $col = ?, $notifyCol = 1 WHERE session_id = ?");
    $stmt->bind_param("ii", $value, $session_id);
    $stmt->execute();
    $stmt->close();

    // Lead is mutually exclusive and always assigned — one AI must always be lead
    if ($perm === 'lead') {
        $otherAI = $ai === 'claude' ? 'gemini' : 'claude';
        $otherCol = $otherAI . '_lead';
        $otherNotify = $otherAI . '_perms_notify';
        // Crown this AI → dethrone the other; uncheck this AI → auto-crown the other
        $otherValue = $value === 1 ? 0 : 1;
        $dStmt = $conn->prepare("UPDATE braintrust_turn_state SET $otherCol = ?, $otherNotify = 1 WHERE session_id = ?");
        $dStmt->bind_param("ii", $otherValue, $session_id);
        $dStmt->execute();
        $dStmt->close();
    }

    // Post a system message so all AIs see the change and don't make assumptions
    $permLabels = [
        'can_write'    => 'Write Files',
        'can_delete'   => 'Delete Files',
        'can_run'      => 'Run Code',
        'can_terminal' => 'Use Terminal',
        'can_packages' => 'Install Packages',
        'lead'         => 'Project Lead',
    ];
    $permDesc = [
        'can_write'    => $value ? 'may now create and modify files using CREATE_FILE.' : 'may NO LONGER create or modify files.',
        'can_delete'   => $value ? 'may now delete files.' : 'may NO LONGER delete files.',
        'can_run'      => $value ? 'may now use RUN_FILE to execute scripts.' : 'may NO LONGER execute scripts.',
        'can_terminal' => $value ? 'may now issue terminal/shell commands.' : 'may NO LONGER use terminal commands.',
        'can_packages' => $value ? 'may now install packages and dependencies.' : 'may NO LONGER install packages.',
        'lead'         => $value ? 'is now 👑 PROJECT LEAD. This AI has final say on architecture and code direction. It should drive decisions and coordinate work.' : 'is no longer Project Lead.',
    ];
    $aiName  = ucfirst($ai);
    $label   = $permLabels[$perm] ?? $perm;
    $state   = $value ? '✅ ENABLED' : '🚫 DISABLED';
    $desc    = $permDesc[$perm] ?? '';
    $notice  = "⚙️ Permission update — {$aiName} · {$label}: {$state}\n{$aiName} {$desc}";
    $nStmt = $conn->prepare("INSERT INTO braintrust_messages (session_id, sender_type, message_text) VALUES (?, 'system', ?)");
    $nStmt->bind_param("is", $session_id, $notice);
    $nStmt->execute();
    $nStmt->close();
    notifyWebSocket($session_id, 'new_message');

    echo json_encode(['success' => true]);
}

$conn->close();
?>
