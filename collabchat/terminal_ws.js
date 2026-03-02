/**
 * BrainTrust IDE - Terminal WebSocket Server
 * Real PTY (pseudo-terminal) via node-pty + xterm.js on the frontend.
 * Replaces the fake proc_open terminal with a real bash session.
 *
 * Protocol:
 *   Client -> Server (JSON):  { type: 'init', project: '...', cols: 80, rows: 24 }
 *   Client -> Server (JSON):  { type: 'resize', cols: 80, rows: 24 }
 *   Client -> Server (raw):   Keystroke data passed directly to PTY stdin
 *   Server -> Client (raw):   PTY stdout/stderr output passed directly to xterm.js
 */

const { WebSocketServer } = require('ws');
const pty = require('node-pty');
const fs = require('fs');

const PORT = 8083;
const PROJECTS_ROOT = process.env.BT_PROJECTS_ROOT || '/var/www/html/collabchat/projects/';

const wss = new WebSocketServer({ port: PORT });

wss.on('connection', (ws) => {
    let ptyProcess = null;

    ws.on('message', (data) => {
        const str = data.toString();

        // Try to parse as a JSON control message first
        let msg = null;
        try {
            const parsed = JSON.parse(str);
            if (parsed && typeof parsed.type === 'string') {
                msg = parsed;
            }
        } catch (e) { /* not JSON, treat as raw input */ }

        if (msg) {
            // ---- Control messages ----
            if (msg.type === 'init' && !ptyProcess) {
                // Sanitize project name
                const project = (msg.project || '').replace(/[^a-zA-Z0-9_\-\.]/g, '');
                const projectPath = PROJECTS_ROOT + project;
                const cwd = (project && fs.existsSync(projectPath)) ? projectPath : '/var/www';
                const histFile = (project && fs.existsSync(projectPath))
                    ? projectPath + '/.terminal_history'
                    : '/var/www/.terminal_history';

                ptyProcess = pty.spawn('bash', [], {
                    name: 'xterm-256color',
                    cols: msg.cols || 80,
                    rows: msg.rows || 24,
                    cwd,
                    env: {
                        ...process.env,
                        HOME: '/var/www',
                        TERM: 'xterm-256color',
                        COLORTERM: 'truecolor',
                        PATH: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin',
                        GIT_TERMINAL_PROMPT: '0',
                        HISTFILE: histFile,
                        HISTSIZE: '1000',
                        HISTFILESIZE: '1000',
                        HISTCONTROL: 'ignoredups',
                        PROMPT_COMMAND: 'history -a',
                    }
                });

                ptyProcess.onData((output) => {
                    if (ws.readyState === 1 /* OPEN */) {
                        ws.send(output);
                    }
                });

                ptyProcess.onExit(() => {
                    if (ws.readyState === 1) {
                        ws.send('\r\n\x1b[33m[Shell exited. Close and reopen terminal for a new session.]\x1b[0m\r\n');
                    }
                });

                console.log(`[+] PTY spawned — project: "${project || '(none)'}", cwd: ${cwd}`);

            } else if (msg.type === 'resize' && ptyProcess) {
                ptyProcess.resize(
                    Math.max(1, msg.cols || 80),
                    Math.max(1, msg.rows || 24)
                );
            }

        } else if (ptyProcess) {
            // ---- Raw keystrokes -> PTY stdin ----
            ptyProcess.write(str);
        }
    });

    ws.on('close', () => {
        if (ptyProcess) {
            ptyProcess.kill();
            ptyProcess = null;
            console.log('[-] PTY killed on disconnect');
        }
    });

    ws.on('error', (err) => {
        console.error('[!] WebSocket error:', err.message);
        if (ptyProcess) {
            ptyProcess.kill();
            ptyProcess = null;
        }
    });
});

console.log('\n========================================');
console.log('  BrainTrust Terminal WebSocket Server');
console.log(`  ws://0.0.0.0:${PORT}`);
console.log('========================================\n');
