"""Kartiert, wo Parameter in der 1277-Byte-Konfiguration stehen (über TCP, Editor muss offline sein).

Je Experiment: Testwert setzen -> Konfiguration vergleichen -> Originalwert setzen -> prüfen.
Nur an unhörbaren Kanälen: In1 (stumm), Out8 (stumm), Aux (nicht geroutet).
"""

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
import snapshot  # noqa: E402
from trackdsp import Matrix  # noqa: E402
from trackdsp import protocol as p  # noqa: E402


def f(hexstr):
    b = bytes.fromhex(hexstr)
    return p.request(b[0], b[1:])


EXPERIMENTS = [
    # Name, Testwert(e), Original(e)
    ("gate In1 (att=5 rel=2998 thr=3)", ["3E 00 05 00 b6 0b 03 00"], ["3E 00 00 00 b7 0b 00 00"]),
    ("phase In1", ["36 00 01"], ["36 00 00"]),
    ("line/mic In1 = 2", ["55 00 02"], ["55 00 00"]),
    ("fbq In1 = 2", ["61 00 02"], ["61 00 00"]),
    ("hpf In1 freq 5 slope 3", ["32 00 05 00 03"], ["32 00 00 00 00"]),
    ("lpf In1 freq 299 slope 4", ["31 00 2b 01 04"], ["31 00 2c 01 00"]),
    ("peq In1 band1 gain119 bypass", ["33 00 00 77 00 31 00 19 00 01"], ["33 00 00 78 00 31 00 19 00 00"]),
    ("peq In1 band5 type1", ["33 00 04 78 00 ff 00 19 01 00"], ["33 00 04 78 00 ff 00 19 00 00"]),
    ("eq-bypass In1", ["3C 00 01"], ["3C 00 00"]),
    ("eq-bypass Out8", ["3C 0f 01"], ["3C 0f 00"]),
    ("peq Out8 band3 bypass", ["33 0f 02 78 00 76 00 19 00 01"], ["33 0f 02 78 00 76 00 19 00 00"]),
    ("comp Out8 ratio2 knee1", ["30 0f 02 01 31 00 c7 00 dc 00"], ["30 0f 00 00 31 00 c7 00 dc 00"]),
    ("delay Out8 = 5", ["38 0f 05 00"], ["38 0f 00 00"]),
    ("phase Out8", ["36 0f 01"], ["36 0f 00"]),
    ("name Out8 OUT8", ["3D 0f 4f 55 54 38 00 00 00 00"], ["3D 0f 4f 75 74 38 00 00 00 00"]),
    ("link Out7 -> 0", ["3B 0e 00"], ["3B 0e 40"]),
    ("aux select In8", ["3A 12 80 00"], ["3A 12 00 00"]),
    ("aux mute", ["35 12 01"], ["35 12 00"]),
    ("aux gain 279", ["34 12 17 01"], ["34 12 18 01"]),
    ("aux comp ratio1", ["30 12 01 00 31 00 c7 00 dc 00"], ["30 12 00 00 31 00 c7 00 dc 00"]),
    ("automix params+on", ["64 12 62 00 b6 0b b3 00 01 00 01 00"], ["64 12 63 00 b7 0b b4 00 00 00 00 00"]),
    ("camera params+on", ["64 13 62 00 b6 0b b3 00 01 00 01 00"], ["64 13 63 00 b7 0b b4 00 00 00 00 00"]),
    ("effect on", ["63 01 37"], ["63 00 37"]),
    ("effect param 36", ["63 00 36"], ["63 00 37"]),
    ("delay unit m", ["15 01"], ["15 00"]),
    ("sub input 99%", ["5B 00 63 00 00 00"], ["5B 00 64 00 00 00"]),
    ("sub output mute", ["5B 01 01 01 10 03"], ["5B 01 01 00 10 03"]),
    ("group in0 99%", ["6B 00 63 00 c0"], ["6B 00 64 00 c0"]),
    ("group out(5) 99%", ["6B 05 63 00 00"], ["6B 05 64 00 00"]),
]


def main():
    only = sys.argv[1:]
    dsp = Matrix.connect(snapshot.TARGET, timeout=1.5)
    base = dsp.config()
    results = []
    for name, test, orig in EXPERIMENTS:
        if only and not any(o in name for o in only):
            continue
        acks = []
        for fr in test:
            try:
                dsp.write(f(fr)); acks.append(True)
            except Exception:
                acks.append(False)
        mid = dsp.config()
        changes = [(snapshot.where(i), a, b) for i, a, b in snapshot.diff(base, mid)]
        for fr in orig:
            try:
                dsp.write(f(fr))
            except Exception:
                pass
        after = dsp.config()
        ok = after == base
        rest = [] if ok else [(snapshot.where(i), a, b) for i, a, b in snapshot.diff(base, after)]
        results.append({"name": name, "ack": acks, "changes": changes, "restored": ok, "rest": rest})
        print(f"{name:34} ACK={acks} Änderungen={changes} {'OK' if ok else 'NICHT WIEDERHERGESTELLT: ' + str(rest)}")
        if not ok:
            base = after  # weiter vergleichen gegen neuen Stand, aber melden
    json.dump(results, open(Path(__file__).parent.parent / "captures" / "config_map.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)
    dsp.close()


if __name__ == "__main__":
    main()
