"""Kompakte Zusammenfassung von captures/results.jsonl (lange Wertefolgen gekürzt)."""
import json, sys
from pathlib import Path
rows = [json.loads(l) for l in (Path(__file__).parent.parent / "captures/results.jsonl").read_text(encoding="utf-8").splitlines()]
flt = sys.argv[1:]
for r in rows:
    if flt and not any(f in r["test"] for f in flt): continue
    print(f"== {r['test']}: {r['note']}  ({len(r['frames'])} Rahmen)")
    fr = r["frames"]
    groups = []
    for line in fr:
        key = line.split()[1:3]
        if groups and groups[-1][0] == key: groups[-1][1].append(line)
        else: groups.append((key, [line]))
    for key, lines in groups:
        if len(lines) <= 4:
            for l in lines: print("   ", l)
        else:
            print("   ", lines[0]); print("   ", lines[1]); print(f"     … {len(lines)-3} weitere …"); print("   ", lines[-1])
