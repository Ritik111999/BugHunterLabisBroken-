#!/usr/bin/env node
/**
 * Optional WebSocket cluster proxy for WeChirp PHP relays.
 * Routes by meeting_id so each meeting sticks to one PHP relay worker.
 */

import http from 'node:http';
import { WebSocketServer, WebSocket } from 'ws';

const host = process.env.RELAY_CLUSTER_HOST || '127.0.0.1';
const listenPort = Number(process.env.RELAY_CLUSTER_PORT || 9100);
const ports = String(process.env.RELAY_CLUSTER_PORTS || '9001')
    .split(',')
    .map((p) => Number(p.trim()))
    .filter((p) => Number.isFinite(p) && p > 0);

if (ports.length === 0) {
    console.error('RELAY_CLUSTER_PORTS must list at least one relay port');
    process.exit(1);
}

function pickPort(meetingId) {
    const id = Number(meetingId) || 0;
    return ports[Math.abs(id) % ports.length];
}

function meetingIdFromUrl(url) {
    const m = String(url || '').match(/\/meetings\/(\d+)\/live/);
    return m ? m[1] : '0';
}

const server = http.createServer((req, res) => {
    if (req.url === '/up') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end('ok');
        return;
    }
    if (req.url === '/metrics') {
        res.writeHead(200, { 'content-type': 'text/plain' });
        res.end(
            `wechirp_cluster_proxy_upstreams ${ports.length}\nwechirp_cluster_proxy_listen_port ${listenPort}\n`,
        );
        return;
    }
    res.writeHead(404);
    res.end('not found');
});

const wss = new WebSocketServer({ noServer: true });

server.on('upgrade', (req, socket, head) => {
    const meetingId = meetingIdFromUrl(req.url);
    const targetPort = pickPort(meetingId);
    const targetUrl = `ws://${host}:${targetPort}${req.url || ''}`;

    wss.handleUpgrade(req, socket, head, (client) => {
        const upstream = new WebSocket(targetUrl, {
            headers: {
                authorization: req.headers.authorization || '',
            },
        });

        const closeBoth = () => {
            try {
                client.close();
            } catch {}
            try {
                upstream.close();
            } catch {}
        };

        upstream.on('open', () => {
            client.on('message', (data, isBinary) => {
                if (upstream.readyState === WebSocket.OPEN) {
                    upstream.send(data, { binary: isBinary });
                }
            });
            upstream.on('message', (data, isBinary) => {
                if (client.readyState === WebSocket.OPEN) {
                    client.send(data, { binary: isBinary });
                }
            });
            client.on('close', closeBoth);
            upstream.on('close', closeBoth);
            client.on('error', closeBoth);
            upstream.on('error', closeBoth);
        });

        upstream.on('error', (err) => {
            console.error('[cluster-proxy] upstream error', targetUrl, err?.message || err);
            closeBoth();
        });
    });
});

server.listen(listenPort, host, () => {
    console.log(`[cluster-proxy] ws://${host}:${listenPort} → [${ports.join(', ')}] (hash by meeting_id)`);
});
