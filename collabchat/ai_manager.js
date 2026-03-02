/**
 * BrainTrust v3 — AI Session Manager
 * ────────────────────────────────────
 * HTTP  127.0.0.1:8084  — receives /invoke from PHP (internal only)
 * WS    0.0.0.0:8085    — streams AI output to browser clients
 *
 * Flow:
 *   PHP POST /invoke  →  spawn AI process  →  stream chunks to WS clients
 *                    →  callback PHP /ai_response_complete when done
 *
 * API keys are passed in the /invoke payload by PHP (PHP reads them from
 * secure_config). ai_manager never reads config files directly.
 */

'use strict';

const http      = require('http');
const fs        = require('fs');
const { spawn } = require('child_process');
const WebSocket = require('./node_modules/ws');

// ─── CLI Monitor Log Helpers ──────────────────────────────────────────────────
// Writes formatted log entries to /tmp/braintrust_cli_SESSIONID_PROVIDER.log
// so the existing CLI Monitor modal in the UI works with v3 sessions.

const LOG_DIR = '/var/www/html/braintrust-IDE-3/collabchat/logs';

function logPath(sessionId, provider) {
    return `${LOG_DIR}/braintrust_cli_${sessionId}_${provider}.log`;
}

function cliLog(sessionId, provider, eventType, message) {
    const ts   = new Date().toTimeString().substring(0, 8);
    const line = `[${ts}] [${eventType}] ${message}\n`;
    fs.appendFileSync(logPath(sessionId, provider), line);
}

function cliLogStart(sessionId, provider, model) {
    const log = logPath(sessionId, provider);
    fs.writeFileSync(log, ''); // clear previous log
    fs.writeFileSync(log + '.running', '1');
    cliLog(sessionId, provider, 'system', `${provider} CLI invoked (model: ${model})`);
}

function cliLogEnd(sessionId, provider, costInfo) {
    cliLog(sessionId, provider, 'complete', costInfo || 'Done');
    try { fs.unlinkSync(logPath(sessionId, provider) + '.running'); } catch(_) {}
}

const HTTP_PORT = 8084;
const WS_PORT   = 8085;

const CLAUDE_PATH = '/usr/bin/claude';
const GEMINI_PATH = '/usr/bin/gemini';

// ─── WebSocket Server (port 8085 — browser-facing) ───────────────────────────

const wss = new WebSocket.Server({ port: WS_PORT });

// wsRooms: Map<sessionId, Set<WebSocket>>
const wsRooms = new Map();

wss.on('connection', (ws, req) => {
    const url       = new URL(req.url, 'http://localhost');
    const sessionId = url.searchParams.get('session_id');

    if (!sessionId) { ws.close(); return; }

    if (!wsRooms.has(sessionId)) wsRooms.set(sessionId, new Set());
    wsRooms.get(sessionId).add(ws);

    ws.on('close', () => {
        wsRooms.get(sessionId)?.delete(ws);
        if (wsRooms.get(sessionId)?.size === 0) wsRooms.delete(sessionId);
    });

    ws.on('error', () => ws.terminate());
});

function broadcast(sessionId, event) {
    const room = wsRooms.get(String(sessionId));
    if (!room || room.size === 0) return;
    const payload = JSON.stringify(event);
    for (const ws of room) {
        if (ws.readyState === WebSocket.OPEN) {
            ws.send(payload);
        }
    }
}

// ─── HTTP Server (port 8084 — localhost only) ─────────────────────────────────

const httpServer = http.createServer((req, res) => {
    // Only accept local PHP connections
    const clientIP = req.socket.remoteAddress;
    if (clientIP !== '127.0.0.1' && clientIP !== '::1' && clientIP !== '::ffff:127.0.0.1') {
        res.writeHead(403);
        res.end('Forbidden');
        return;
    }

    if (req.method === 'GET' && req.url === '/status') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok', sessions: wsRooms.size }));
        return;
    }

    if (req.method === 'POST' && req.url === '/invoke') {
        let body = '';
        req.on('data', chunk => { body += chunk; });
        req.on('end', () => {
            let payload;
            try { payload = JSON.parse(body); }
            catch(e) {
                res.writeHead(400);
                res.end(JSON.stringify({ error: 'Invalid JSON' }));
                return;
            }

            // Respond immediately — processing is async
            res.writeHead(202, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ status: 'accepted' }));

            // Fire off the AI invocation without blocking
            handleInvoke(payload).catch(err => {
                console.error(`[ai_manager] Invoke error (session ${payload.session_id}):`, err.message);
                broadcast(payload.session_id, {
                    type: 'error',
                    provider: payload.provider,
                    message: err.message
                });
            });
        });
        return;
    }

    res.writeHead(404);
    res.end('Not found');
});

httpServer.listen(HTTP_PORT, '127.0.0.1', () => {
    console.log(`[ai_manager] HTTP listening on 127.0.0.1:${HTTP_PORT}`);
    console.log(`[ai_manager] WebSocket listening on 0.0.0.0:${WS_PORT}`);
});

// ─── Invoke Handler ───────────────────────────────────────────────────────────

async function handleInvoke(payload) {
    const { provider } = payload;
    if (provider === 'claude') {
        await invokeClaude(payload);
    } else if (provider === 'gemini') {
        await invokeGemini(payload);
    } else {
        throw new Error(`Unknown provider: ${provider}`);
    }
}

// ─── Claude ───────────────────────────────────────────────────────────────────

async function invokeClaude(payload) {
    const {
        session_id,
        message,
        model         = 'claude-sonnet-4-20250514',
        session_uuid  = null,
        system_prompt = null,
        api_key       = '',
        project_path  = '/tmp',
        callback_url,
        pinned_context = ''
    } = payload;

    broadcast(session_id, { type: 'start', provider: 'claude' });
    cliLogStart(session_id, 'claude', model);

    const args = [
        '-p',
        '--output-format', 'stream-json',
        '--verbose',
        '--model', model,
        '--dangerously-skip-permissions'
    ];

    if (session_uuid) {
        args.push('--resume', session_uuid);
    } else if (system_prompt) {
        args.push('--append-system-prompt', system_prompt);
    }

    const env = {
        PATH:              '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        HOME:              '/var/www',
        TERM:              'dumb',
        ANTHROPIC_API_KEY: api_key,
        // Strip Claude Code nesting detection vars
        CLAUDECODE:                undefined,
        CLAUDE_CODE_SSE_PORT:      undefined,
        CLAUDE_CODE_ENTRYPOINT:    undefined,
    };
    // Remove undefined keys
    Object.keys(env).forEach(k => env[k] === undefined && delete env[k]);

    // Retry up to 3 times with exponential backoff
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            const result = await spawnAndStream(
                CLAUDE_PATH, args, env, project_path,
                message, session_id, 'claude', 180000
            );

            // Update UUID if changed
            const newUuid = result.session_id || session_uuid;

            cliLogEnd(session_id, 'claude');
            broadcast(session_id, {
                type:      'complete',
                provider:  'claude',
                full_text: result.full_text
            });

            if (callback_url) {
                await httpPost(callback_url, {
                    session_id,
                    provider:       'claude',
                    full_text:      result.full_text,
                    new_uuid:       newUuid,
                    pinned_context
                });
            }
            return;

        } catch(err) {
            cliLog(session_id, 'claude', 'error', `Attempt ${attempt} failed: ${err.message}`);
            if (attempt < 3) {
                cliLog(session_id, 'claude', 'system', `Retrying in ${Math.pow(2, attempt)}s...`);
                await sleep(Math.pow(2, attempt) * 1000);
            } else {
                cliLogEnd(session_id, 'claude', `Failed after ${attempt} attempts`);
                throw err;
            }
        }
    }
}

// ─── Gemini ───────────────────────────────────────────────────────────────────

async function invokeGemini(payload) {
    const {
        session_id,
        message,
        model         = 'gemini-2.5-flash',
        session_uuid  = null,
        api_key       = '',
        project_path  = '/tmp',
        callback_url,
        pinned_context = ''
    } = payload;

    broadcast(session_id, { type: 'start', provider: 'gemini' });
    cliLogStart(session_id, 'gemini', model);

    // Gemini takes prompt as -p argument (no stdin support)
    const args = ['-p', message, '-o', 'json', '-y', '--model', model];
    // Only resume if we have a known UUID — never use -r latest on first call,
    // it errors out with "No previous sessions found" if none exists yet
    if (session_uuid) {
        args.unshift('-r', session_uuid);
    }

    const env = {
        PATH:       '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        HOME:       '/var/www',
        TERM:       'dumb',
        GEMINI_API_KEY: api_key,
    };

    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            const result = await spawnGemini(
                GEMINI_PATH, args, env, project_path, session_id, 180000
            );

            const newUuid = result.session_id || session_uuid;

            cliLogEnd(session_id, 'gemini');
            broadcast(session_id, {
                type:      'complete',
                provider:  'gemini',
                full_text: result.full_text
            });

            if (callback_url) {
                await httpPost(callback_url, {
                    session_id,
                    provider:       'gemini',
                    full_text:      result.full_text,
                    new_uuid:       newUuid,
                    pinned_context
                });
            }
            return;

        } catch(err) {
            cliLog(session_id, 'gemini', 'error', `Attempt ${attempt} failed: ${err.message}`);
            if (attempt < 3) {
                cliLog(session_id, 'gemini', 'system', `Retrying in ${Math.pow(2, attempt)}s...`);
                await sleep(Math.pow(2, attempt) * 1000);
            } else {
                cliLogEnd(session_id, 'gemini', `Failed after ${attempt} attempts`);
                throw err;
            }
        }
    }
}

// ─── Spawn + Stream (Claude stream-json) ─────────────────────────────────────

function spawnAndStream(bin, args, env, cwd, stdinMsg, sessionId, provider, timeoutMs) {
    return new Promise((resolve, reject) => {
        const proc = spawn(bin, args, { env, cwd });

        let lineBuffer = '';
        let chunks     = [];
        let newUuid    = null;
        let resolved   = false;

        const timer = setTimeout(() => {
            if (!resolved) {
                proc.kill('SIGKILL');
                reject(new Error(`${provider} timed out after ${timeoutMs}ms`));
            }
        }, timeoutMs);

        proc.stdout.on('data', data => {
            lineBuffer += data.toString();
            // Process complete lines
            let idx;
            while ((idx = lineBuffer.indexOf('\n')) !== -1) {
                const line = lineBuffer.substring(0, idx).trim();
                lineBuffer  = lineBuffer.substring(idx + 1);
                if (!line.startsWith('{')) continue;

                let evt;
                try { evt = JSON.parse(line); }
                catch(_) { continue; }

                // Capture session UUID from init or result events
                if (evt.session_id) newUuid = evt.session_id;

                if (evt.type === 'assistant' && evt.message?.content) {
                    for (const block of evt.message.content) {
                        if (block.type === 'text' && block.text) {
                            chunks.push(block.text);
                            broadcast(sessionId, {
                                type:     'chunk',
                                provider,
                                text:     block.text
                            });
                        }
                    }
                }

                if (evt.type === 'tool_use') {
                    const inputSummary = JSON.stringify(evt.input || {}).substring(0, 120);
                    cliLog(sessionId, provider, 'tool', `${evt.name}: ${inputSummary}`);
                    broadcast(sessionId, {
                        type:     'tool',
                        provider,
                        tool:     evt.name,
                        input:    evt.input
                    });
                }

                if (evt.type === 'tool_result') {
                    const size = JSON.stringify(evt.content || '').length;
                    cliLog(sessionId, provider, 'result', `(${size} bytes)`);
                }

                if (evt.type === 'system' && evt.session_id) {
                    cliLog(sessionId, provider, 'system', `Session: ${evt.session_id}`);
                }

                if (evt.type === 'result') {
                    resolved = true;
                    clearTimeout(timer);
                    resolve({
                        full_text:  evt.result || chunks.join(''),
                        session_id: evt.session_id || newUuid
                    });
                }
            }
        });

        proc.stderr.on('data', data => {
            // Log stderr but don't error — Claude CLI uses stderr for verbose output
            const text = data.toString().trim();
            if (text) console.error(`[ai_manager:${provider}:stderr]`, text.substring(0, 200));
        });

        proc.on('close', code => {
            clearTimeout(timer);
            if (!resolved) {
                if (chunks.length > 0) {
                    // Got chunks but missed result event — resolve with what we have
                    resolve({ full_text: chunks.join(''), session_id: newUuid });
                } else {
                    reject(new Error(`${provider} process exited with code ${code} and no output`));
                }
            }
        });

        proc.on('error', err => {
            clearTimeout(timer);
            if (!resolved) reject(err);
        });

        // Write message to stdin
        if (stdinMsg) {
            proc.stdin.write(stdinMsg);
            proc.stdin.end();
        }
    });
}

// ─── Spawn Gemini (single JSON response) ─────────────────────────────────────

function spawnGemini(bin, args, env, cwd, sessionId, timeoutMs) {
    return new Promise((resolve, reject) => {
        const proc = spawn(bin, args, { env, cwd });

        let stdout = '';
        let stderr = '';

        const timer = setTimeout(() => {
            proc.kill('SIGKILL');
            reject(new Error(`Gemini timed out after ${timeoutMs}ms`));
        }, timeoutMs);

        proc.stdout.on('data', d => { stdout += d.toString(); });
        proc.stderr.on('data', d => { stderr += d.toString(); });

        proc.on('close', code => {
            clearTimeout(timer);
            try {
                const json = JSON.parse(stdout.trim());
                if (!json.response) {
                    reject(new Error(`Gemini response missing 'response' field. Keys: ${Object.keys(json).join(',')}`));
                    return;
                }
                resolve({
                    full_text:  json.response,
                    session_id: json.session_id || null
                });
            } catch(e) {
                console.error('[ai_manager:gemini] Parse error. stdout:', stdout.substring(0, 300));
                console.error('[ai_manager:gemini] stderr:', stderr.substring(0, 300));
                reject(new Error(`Gemini JSON parse error: ${e.message}`));
            }
        });

        proc.on('error', err => { clearTimeout(timer); reject(err); });
    });
}

// ─── HTTP POST helper (callback to PHP) ──────────────────────────────────────

function httpPost(url, data) {
    return new Promise((resolve, reject) => {
        const body    = JSON.stringify(data);
        const urlObj  = new URL(url);
        const options = {
            hostname: urlObj.hostname || '127.0.0.1',
            port:     urlObj.port    || 80,
            path:     urlObj.pathname + (urlObj.search || ''),
            method:   'POST',
            headers:  {
                'Content-Type':   'application/json',
                'Content-Length': Buffer.byteLength(body),
                'X-Internal-Callback': '1'
            }
        };

        const req = http.request(options, res => {
            let body = '';
            res.on('data', d => { body += d; });
            res.on('end', () => resolve(body));
        });

        req.on('error', err => {
            console.error('[ai_manager] Callback POST error:', err.message);
            reject(err);
        });

        req.setTimeout(10000, () => {
            req.destroy();
            reject(new Error('Callback POST timed out'));
        });

        req.write(body);
        req.end();
    });
}

// ─── Utility ──────────────────────────────────────────────────────────────────

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// ─── Graceful shutdown ────────────────────────────────────────────────────────

process.on('SIGTERM', () => {
    console.log('[ai_manager] SIGTERM received, shutting down...');
    httpServer.close();
    wss.close();
    process.exit(0);
});

process.on('uncaughtException', err => {
    console.error('[ai_manager] Uncaught exception:', err.message);
    // Stay alive — don't crash on individual request errors
});

process.on('unhandledRejection', (reason) => {
    console.error('[ai_manager] Unhandled rejection:', reason);
});
