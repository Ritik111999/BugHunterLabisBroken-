#!/usr/bin/env bash
# Prepare Mac + iOS Simulator for Voice intro (HTTP enroll) when the WebView records
# "normal volume" but speech-to-text returns no words. Wired headphones do not fix
# Simulator capture the way they do on a real device.
set -euo pipefail

echo ""
echo "WeChirp — Simulator + mic setup for Voice intro"
echo "================================================="
echo ""

# Sound / Input (macOS): open the closest thing to an Input pane we can.
if [[ -d "/System/Applications/System Settings.app" ]]; then
  # Sonoma / Sequoia: Sound settings deep link (may fall through on some builds).
  open "x-apple.systempreferences:com.apple.Sound-Settings.extension" 2>/dev/null \
    || open -a "System Settings" 2>/dev/null \
    || true
else
  open "/System/Library/PreferencePanes/Sound.prefPane" 2>/dev/null || true
fi

open -a Simulator 2>/dev/null || true

echo "Do this on your Mac (once per session if audio is wrong):"
echo ""
echo "  Mac mini / Studio: there is NO built-in mic. Plug USB headset or USB mic into the"
echo "  Mac (not only into the iPhone). iOS Simulator always uses the Mac input device."
echo ""
echo "  1) System Settings → Sound → Input: select your USB headset/mic. Speak — the"
echo "     level meter must move. If it does not, the Mac is not receiving audio yet."
echo ""
echo "  2) Simulator menu bar: I/O → Audio Input → pick the SAME device (e.g. your"
echo "     USB headset name). Avoid \"None\" or silent virtual inputs."
echo ""
echo "  3) In WeChirp Studio, tap Try again and say clearly: \"My name is …\""
echo ""
echo "  4) Reliable path: run the app on a physical iPhone (see mobile/README.md)."
echo ""

# Best-effort: English Simulator UI only; harmless if it fails (locale / Xcode layout).
if command -v osascript >/dev/null 2>&1; then
  osascript <<'APPLESCRIPT' 2>/dev/null || true
tell application "Simulator" to activate
delay 0.8
tell application "System Events"
  if not (exists process "Simulator") then return
  tell process "Simulator"
    try
      click menu item "Mac Microphone" of menu 1 of menu item "Audio Input" of menu 1 of menu bar item "I/O" of menu bar 1
    end try
  end tell
end tell
APPLESCRIPT
  echo "(Optional) Attempted Simulator → I/O → Audio Input → Mac Microphone."
  echo "On Mac mini with USB headset, pick that device manually — menu names vary."
  echo ""
fi
