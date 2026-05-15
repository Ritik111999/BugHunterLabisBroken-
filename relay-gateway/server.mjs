#!/usr/bin/env node
/**
 * WeChirp Node Deepgram gateway
 *
 * WebSocket STT + live UI events in Node; auth, DB, voiceprints via Laravel internal API.
 *
 *   RELAY_INTERNAL_SECRET=... DEEPGRAM_API_KEY=... node relay-gateway/server.mjs
 *
 * Env: WC_RELAY_GATEWAY_PORT (default 9200), LARAVEL_URL / APP_URL
 * Client: WECHIRP_RELAY_ENGINE=node, WC_RELAY_WS_URL=ws://127.0.0.1:9200
 */

import http from 'node:http';
import { WebSocketServer, WebSocket } from 'ws';
import { loadConfig } from './lib/config.mjs';
import { LaravelBridge } from './lib/laravel-bridge.mjs';
import { MeetingSession } from './lib/meeting-session.mjs';

const config = loadConfig();

if (!config.secret) {
    console.error('[gateway] RELAY_INTERNAL_SECRET is required');
    process.exit(1);
}
if (!config.deepgramKey) {
    console.warn('[gateway] DEEPGRAM_API_KEY missing — sessions will fail until set');
}

const bridge = new LaravelBridge(config);
let activeConnections = 0;
const metrics = {
    total_connections: 0,
    rejected_connections: 0,
    started_at: Date.now(),
};

function prometheus() {
    const uptime = (Date.now() - metrics.started_at) / 1000;
    return [
        `wechirp_node_gateway_uptime_seconds ${uptime.toFixed(1)}`,
        `wechirp_node_gateway_active_connections ${activeConnections}`,
        `wechirp_node_gateway_total_connections ${metrics.total_connections}`,
        `wechirp_node_gateway_rejected_connections ${metrics.rejected_connections}`,
        '',
    ].join('\n');
}

const server = http.createServer((req, res) => {
    if (req.url === '/up') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end('ok');
        return;
    }
    if (req.url === '/metrics') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end(prometheus());
        return;
    }
    res.writeHead(404);
    res.end('not found');
});

const wss = new WebSocketServer({ noServer: true });

server.on('upgrade', (req, socket, head) => {
    const m = String(req.url || '').match(/\/meetings\/(\d+)\/live/);
    if (!m) {
        socket.destroy();
        return;
    }

    if (activeConnections >= config.maxConnections) {
        metrics.rejected_connections += 1;
        socket.write('HTTP/1.1 503 Service Unavailable\r\n\r\n');
        socket.destroy();
        return;
    }

    const meetingId = parseInt(m[1], 10);
    wss.handleUpgrade(req, socket, head, (clientWs) => {
        activeConnections += 1;
        metrics.total_connections += 1;

        const session = new MeetingSession({
            meetingId,
            clientWs,
            config,
            bridge,
        });

        let authed = false;

        clientWs.on('message', (data, isBinary) => {
            if (isBinary) {
                if (authed) session.onBinary(data);
                return;
            }
            try {
                const msg = JSON.parse(data.toString());
                if (!authed && msg?.type === 'auth' && msg.token) {
                    authed = true;
                    session.handleAuthMessage(String(msg.token));
                }
            } catch {}
        });

        clientWs.on('close', () => {
            session.close();
            activeConnections = Math.max(0, activeConnections - 1);
        });
    });
});

server.listen(config.port, config.host, () => {
    console.log(
        `[gateway] Node Deepgram gateway ws://${config.host}:${config.port} → Laravel ${config.laravelUrl}`,
    );
});
