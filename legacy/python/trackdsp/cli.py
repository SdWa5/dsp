"""Kommandozeile für die t.racks 8x8 Matrix.

    trackdsp info                       Gerät, IP, aktives Preset
    trackdsp presets                    Preset-Namen
    trackdsp status                     Stumm-Zustand aller Kanäle
    trackdsp gain in1 -6                Gain in dB (>0 dB nur mit --allow-boost)
    trackdsp mute out1 | unmute out1
    trackdsp phase out3 invert|normal
    trackdsp route out3 1,2             Ausgang 3 bekommt In1+In2 ("none" = keiner)
    trackdsp dump [datei]               Konfiguration (26 Seiten) als Hex oder Binärdatei
    trackdsp meters                     Pegel-Rohdaten

Ziel: -t usb (Standard) oder -t 192.168.68.10[:9761], alternativ Umgebungsvariable TRACKDSP_TARGET.
"""

from __future__ import annotations

import argparse
import os
import sys

from .client import DspError, Matrix, preset_label


def _inputs(spec: str) -> list[int]:
    if spec.lower() in ("none", "-", ""):
        return []
    return [int(x.strip().lower().removeprefix("in")) for x in spec.split(",")]


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="trackdsp", description="t.racks 8x8 Matrix steuern (USB oder TCP)")
    ap.add_argument("-t", "--target", default=os.environ.get("TRACKDSP_TARGET", "usb"),
                    help="usb oder IP[:Port] (Standard: usb bzw. $TRACKDSP_TARGET)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("info")
    sub.add_parser("presets")
    sub.add_parser("status")
    g = sub.add_parser("gain")
    g.add_argument("channel")
    g.add_argument("db", type=float)
    g.add_argument("--allow-boost", action="store_true", help="Werte über 0 dB erlauben")
    for name in ("mute", "unmute"):
        sub.add_parser(name).add_argument("channel")
    ph = sub.add_parser("phase")
    ph.add_argument("channel")
    ph.add_argument("mode", choices=["normal", "invert"])
    r = sub.add_parser("route")
    r.add_argument("output", help="out1..out8 oder 1..8")
    r.add_argument("inputs", help="z. B. 1,2 oder in1,in2 oder none")
    d = sub.add_parser("dump")
    d.add_argument("file", nargs="?")
    sub.add_parser("meters")
    args = ap.parse_args(argv)

    if args.cmd == "gain" and args.db > 0 and not args.allow_boost:
        ap.error(f"{args.db:+} dB ist eine Anhebung – zur Sicherheit nur mit --allow-boost")

    try:
        with Matrix.connect(args.target) as dsp:
            if args.cmd == "info":
                slot = dsp.active_preset()
                print(f"Gerät:  {dsp.info()}")
                ip = dsp.ip_address() if args.target == "usb" else args.target.split(":")[0]
                print(f"IP:     {ip}")
                name = dsp.preset_name(slot - 1) if slot else "?"
                print(f"Preset: U{slot:02d} {name}")
            elif args.cmd == "presets":
                for i, name in enumerate(dsp.preset_names()):
                    print(f"{preset_label(i)}  {name}")
            elif args.cmd == "status":
                state = dsp.mute_state()
                for group in ("in", "out"):
                    print(" ".join(f"{ch}:{'STUMM' if on else 'an'}" for ch, on in state.items()
                                   if ch.startswith(group) and ch[len(group)].isdigit()))
            elif args.cmd == "gain":
                dsp.set_gain(args.channel, args.db)
            elif args.cmd in ("mute", "unmute"):
                dsp.mute(args.channel, args.cmd == "mute")
            elif args.cmd == "phase":
                dsp.phase(args.channel, args.mode == "invert")
            elif args.cmd == "route":
                dsp.route(int(args.output.lower().removeprefix("out")), _inputs(args.inputs))
            elif args.cmd == "dump":
                data = dsp.config()
                if args.file:
                    with open(args.file, "wb") as fh:
                        fh.write(data)
                    print(f"{len(data)} Byte -> {args.file}")
                else:
                    for off in range(0, len(data), 50):
                        print(f"{off // 50:02X}  {data[off:off + 50].hex(' ')}")
            elif args.cmd == "meters":
                print(dsp.meters_raw().hex(" "))
    except (DspError, OSError, ValueError) as e:
        print(f"Fehler: {e}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
