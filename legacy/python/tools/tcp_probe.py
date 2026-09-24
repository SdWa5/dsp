"""Prüft, ob der DSP per TCP (Standard: Port 9761) das USB-Rahmenformat versteht.

    python tools/tcp_probe.py [IP] [PORT]

Sendet nur lesende Befehle: 0x10 (Init) und 0x13 (Geräte-Info). Nichts wird verändert.
"""

from __future__ import annotations

import socket
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from trackdsp import protocol as p  # noqa: E402

ip = sys.argv[1] if len(sys.argv) > 1 else "192.168.68.10"
port = int(sys.argv[2]) if len(sys.argv) > 2 else 9761

try:
    s = socket.create_connection((ip, port), timeout=3)
except OSError as e:
    sys.exit(f"{ip}:{port} nicht erreichbar: {e}")

print(f"{ip}:{port} verbunden")
s.settimeout(2)
for cmd in (p.CMD_INIT, p.CMD_DEVICE_INFO):
    frame = p.request(cmd).encode()
    s.sendall(frame)
    try:
        data = s.recv(256)
    except socket.timeout:
        print(f"  cmd {cmd:02X}: keine Antwort")
        continue
    f = p.decode(data)
    if f:
        text = f.data.decode("ascii", "replace") if cmd == p.CMD_DEVICE_INFO else f.data.hex(" ")
        print(f"  cmd {cmd:02X}: Antwort cmd {f.cmd:02X} -> {text}")
    else:
        print(f"  cmd {cmd:02X}: {len(data)} Byte, kein USB-Rahmen: {data[:32].hex(' ')}")
s.close()
