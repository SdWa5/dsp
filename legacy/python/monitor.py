"""Öffnet den DSP und zeigt alle eingehenden HID-Reports an. Sendet nichts.

    .venv\\Scripts\\python monitor.py            # Reports anzeigen
    .venv\\Scripts\\python monitor.py --log x.txt  # zusätzlich in Datei schreiben
    .venv\\Scripts\\python monitor.py --list     # nur Geräte auflisten
"""

from __future__ import annotations

import argparse
import sys
from datetime import datetime

from trackdsp import DeviceNotFound, TrackDSP, find_devices, hexdump


def list_devices() -> int:
    devices = find_devices()
    if not devices:
        print("Kein DSP gefunden.")
        return 1
    for d in devices:
        print(f"{d['manufacturer_string']} / {d['product_string']}  "
              f"VID {d['vendor_id']:04X} PID {d['product_id']:04X}  "
              f"UsagePage {d['usage_page']:04X}  Pfad {d['path'].decode(errors='replace')}")
    return 0


def monitor(log_path: str | None) -> int:
    log = open(log_path, "a", encoding="utf-8") if log_path else None
    try:
        with TrackDSP() as dsp:
            print(f"Verbunden: {dsp.manufacturer} / {dsp.product}")
            print("Warte auf Reports … (Strg+C zum Beenden)\n")
            count = 0
            while True:
                data = dsp.read(timeout_ms=500)
                if data is None:
                    continue
                count += 1
                stamp = datetime.now().strftime("%H:%M:%S.%f")[:-3]
                entry = f"[{stamp}] #{count}  {len(data)} Byte\n{hexdump(data)}\n"
                print(entry)
                if log:
                    log.write(entry + "\n")
                    log.flush()
    except DeviceNotFound as e:
        print(e, file=sys.stderr)
        return 1
    except OSError as e:
        print(f"Gerät konnte nicht geöffnet/gelesen werden: {e}\n"
              "Läuft evtl. die offizielle t.racks-Software und blockiert das Gerät?",
              file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("\nBeendet.")
        return 0
    finally:
        if log:
            log.close()


def main() -> int:
    ap = argparse.ArgumentParser(description="t.racks DSP – HID-Monitor (nur lesend)")
    ap.add_argument("--list", action="store_true", help="angeschlossene DSPs auflisten")
    ap.add_argument("--log", metavar="DATEI", help="Reports zusätzlich in Datei schreiben")
    args = ap.parse_args()
    return list_devices() if args.list else monitor(args.log)


if __name__ == "__main__":
    sys.exit(main())
