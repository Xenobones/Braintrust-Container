/**
 * BrainTrust IDE - WebSocket Server
 * Replaces 8-second polling with real-time push notifications.
 *
 * Usage:
 *   node ws_server.js
 *
 * Clients connect via: ws://SERVER_IP:8081?session_id=XX
 * PHP notifies via:    POST http://localhost:8082/notify  { session_id: XX }
 */

const { WebSocketServer, WebSocket } = require('ws');
const http = require('http');

const WS_PORT = 8081;   // Client-facing WebSocket port
const HTTP_PORT = 8082;  // Internal PHP notification port

// ============== SESSION ROOMS ==============
// Map of session_id -> Set of WebSocket clients
const rooms = new Map();

// ============== WEBSOCKET SERVER ==============
const wss = new WebSocketServer({ port: WS_PORT });

wss.on('connection', (ws, req) => {
    // Parse session_id from query string
    const url = new URL(req.url, `http://localhost:${WS_PORT}`);
    const sessionId = url.searchParams.get('session_id');

    if (!sessionId) {
        ws.close(4001, 'Missing session_id');
        return;
    }

    // Join the session room
    if (!rooms.has(sessionId)) {
        rooms.set(sessionId, new Set());
    }
    rooms.get(sessionId).add(ws);

    console.log(`[+] Client joined session ${sessionId} (${rooms.get(sessionId).size} clients)`);

    // Send confirmation
    ws.send(JSON.stringify({ type: 'connected', session_id: sessionId }));

    // Heartbeat - keep connection alive
    ws.isAlive = true;
    ws.on('pong', () => { ws.isAlive = true; });

    // Handle client messages (future use - e.g., typing indicators)
    ws.on('message', (data) => {
        try {
            const msg = JSON.parse(data);
            // Could handle typing indicators, cursor positions, etc.
            if (msg.type === 'ping') {
                ws.send(JSON.stringify({ type: 'pong' }));
            }
        } catch (e) {
            // Ignore malformed messages
        }
    });

    // Clean up on disconnect
    ws.on('close', () => {
        const room = rooms.get(sessionId);
        if (room) {
            room.delete(ws);
            if (room.size === 0) {
                rooms.delete(sessionId);
            }
            console.log(`[-] Client left session ${sessionId} (${room.size} clients remaining)`);
        }
    });
});

// Heartbeat interval - ping clients every 30 seconds, kill dead connections
const heartbeat = setInterval(() => {
    wss.clients.forEach((ws) => {
        if (!ws.isAlive) {
            return ws.terminate();
        }
        ws.isAlive = false;
        ws.ping();
    });
}, 30000);

wss.on('close', () => clearInterval(heartbeat));

// ============== HTTP NOTIFICATION SERVER ==============
// PHP calls this to tell us "session X has new data"
const notifyServer = http.createServer((req, res) => {
    if (req.method === 'POST' && req.url === '/notify') {
        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', () => {
            try {
                const data = JSON.parse(body);
                const sessionId = String(data.session_id);
                const eventType = data.type || 'refresh';

                const room = rooms.get(sessionId);
                if (room) {
                    const message = JSON.stringify({
                        type: eventType,
                        session_id: sessionId,
                        timestamp: Date.now()
                    });

                    let sent = 0;
                    room.forEach((client) => {
                        if (client.readyState === WebSocket.OPEN) {
                            client.send(message);
                            sent++;
                        }
                    });
                    console.log(`[>] Notified ${sent} clients in session ${sessionId} (${eventType})`);
                }

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true }));
            } catch (e) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: 'Invalid JSON' }));
            }
        });
    } else {
        res.writeHead(404);
        res.end('Not found');
    }
});

notifyServer.listen(HTTP_PORT, '127.0.0.1', () => {
    console.log(`\n========================================`);
    console.log(`  BrainTrust WebSocket Server`);
    console.log(`  WebSocket:    ws://0.0.0.0:${WS_PORT}`);
    console.log(`  Notify HTTP:  http://127.0.0.1:${HTTP_PORT}/notify`);
    console.log(`========================================\n`);
});
