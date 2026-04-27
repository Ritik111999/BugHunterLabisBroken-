import fs from "node:fs";
import process from "node:process";
import WebSocket from "ws";

const wsUrl = process.argv[2];
const filePath = process.argv[3];

if (!wsUrl || !filePath) {
  console.error("Usage: node scripts/send_mp3_ws.mjs <wsUrl> <pathToMp3>");
  process.exit(1);
}

const file = fs.readFileSync(filePath);

const ws = new WebSocket(wsUrl);

ws.on("open", async () => {
  console.log("connected");

  // Send MP3 bytes as binary frames in chunks.
  const chunkSize = 32 * 1024;
  for (let i = 0; i < file.length; i += chunkSize) {
    const chunk = file.subarray(i, i + chunkSize);
    ws.send(chunk);
    await new Promise((r) => setTimeout(r, 30)); // small pacing
  }

  // Give Deepgram time to flush final results, then close.
  setTimeout(() => ws.close(1000, "done"), 1000);
});

ws.on("message", (data) => {
  try {
    const text = data.toString("utf8");
    console.log(text);
  } catch {
    // ignore
  }
});

ws.on("close", (code, reason) => {
  console.log(`closed ${code} ${reason?.toString?.() ?? ""}`);
});

ws.on("error", (err) => {
  console.error("ws error", err?.message ?? err);
});

