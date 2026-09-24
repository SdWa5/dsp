"""Wertet einen USBPcap-Mitschnitt des DSP aus (benötigt Wireshark/tshark).

    .venv\\Scripts\\python analyze_capture.py captures\\dsp_session1.pcap
    .venv\\Scripts\\python analyze_capture.py captures\\dsp_session1.pcap --all   # auch Wiederholungen zeigen

Zeigt jeden HID-Report mit Richtung (PC->DSP / DSP->PC), Zeit und Hexdump.
Aufeinanderfolgende identische Reports (z. B. Polling) werden zusammengefasst.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
from collections import Counter
from pathlib import Path

from trackdsp import hexdump

TSHARK = r"C:\Program Files\Wireshark\tshark.exe"


def load_reports(pcap: Path) -> list[tuple[float, str, bytes]]:
    cmd = [
        TSHARK, "-r", str(pcap),
        "-Y", "usb.capdata || usbhid.data",
        "-T", "fields", "-E", "separator=|",
        "-e", "frame.time_relative", "-e", "usb.endpoint_address.direction",
        "-e", "usb.capdata", "-e", "usbhid.data",
    ]
    out = subprocess.run(cmd, capture_output=True, text=True, check=True).stdout
    reports = []
    for line in out.splitlines():
        t, direction, capdata, hiddata = (line.split("|") + ["", "", "", ""])[:4]
        raw = (capdata or hiddata).replace(":", "")
        if not raw:
            continue
        # direction 1 = IN (Gerät -> Host), 0 = OUT (Host -> Gerät)
        label = "DSP->PC" if direction.strip() == "1" else "PC->DSP"
        reports.append((float(t), label, bytes.fromhex(raw)))
    return reports


def main() -> int:
    ap = argparse.ArgumentParser(description="DSP-USB-Mitschnitt auswerten")
    ap.add_argument("pcap", type=Path)
    ap.add_argument("--all", action="store_true", help="Wiederholungen nicht zusammenfassen")
    args = ap.parse_args()

    reports = load_reports(args.pcap)
    if not reports:
        print("Keine HID-Daten im Mitschnitt gefunden.")
        return 1

    counts = Counter(label for _, label, _ in reports)
    print(f"{len(reports)} Reports  ({counts['PC->DSP']} PC->DSP, {counts['DSP->PC']} DSP->PC)\n")

    prev = None
    repeat = 0
    for t, label, data in reports:
        key = (label, data)
        if not args.all and key == prev:
            repeat += 1
            continue
        if repeat:
            print(f"    … {repeat}x wiederholt\n")
            repeat = 0
        print(f"[{t:9.3f}s] {label}  {len(data)} Byte")
        print(hexdump(data.rstrip(b"\x00") or data[:1]))
        print()
        prev = key
    if repeat:
        print(f"    … {repeat}x wiederholt")
    return 0


if __name__ == "__main__":
    sys.exit(main())
