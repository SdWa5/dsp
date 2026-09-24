"""Mitschnitte über den Aufnahmedienst steuern und Rahmen dekodieren.

    python tools/cap.py start NAME     Mitschnitt captures/NAME.pcap starten
    python tools/cap.py stop           beenden
    python tools/cap.py show NAME      Nicht-Pegel-Rahmen von NAME ausgeben
    python tools/cap.py status
"""

from __future__ import annotations

import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from analyze_capture import load_reports  # noqa: E402
from trackdsp import protocol as p  # noqa: E402

CTL = ROOT / "captures" / "ctl"


def status() -> str:
    try:
        return (CTL / "status.txt").read_text(encoding="utf-8-sig").strip()
    except FileNotFoundError:
        return "Dienst läuft nicht"


def wait_status(prefix: str, timeout: float = 5) -> None:
    end = time.time() + timeout
    while time.time() < end:
        if status().startswith(prefix):
            return
        time.sleep(0.1)
    raise SystemExit(f"Timeout, Status: {status()}")


def start(name: str) -> None:
    (ROOT / "captures" / f"{name}.pcap").unlink(missing_ok=True)  # USBPcapCMD überschreibt nicht
    (CTL / "start.txt").write_text(name, encoding="utf-8")
    wait_status("recording")
    time.sleep(1.0)  # USBPcap braucht einen Moment bis zur ersten Aufzeichnung


def stop() -> None:
    time.sleep(0.8)  # letzte Antworten noch mitnehmen
    (CTL / "stop.txt").write_text("", encoding="utf-8")
    wait_status("idle")
    time.sleep(0.3)


def frames(name: str, skip=(p.CMD_METERS,)):
    for t, label, data in load_reports(ROOT / "captures" / f"{name}.pcap"):
        f = p.decode(data)
        if f is None:
            yield t, label, None, data
        elif f.cmd not in skip:
            yield t, label, f, data


def show(name: str) -> None:
    for t, label, f, raw in frames(name):
        if f is None:
            print(f"{t:8.3f} {label} RAW {raw[:16].hex(' ')}")
        elif f.cmd == p.CMD_ACK:
            continue
        else:
            print(f"{t:8.3f} {label} cmd={f.cmd:02X} {f.data.hex(' ')}")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "start":
        start(sys.argv[2])
    elif cmd == "stop":
        stop()
    elif cmd == "show":
        show(sys.argv[2])
    print(status())
