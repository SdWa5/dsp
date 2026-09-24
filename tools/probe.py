"""Ein Test = Aktion im Editor + Rückgängig, mit USB-Mitschnitt und Konfigurationsvergleich.

Ergebnis je Test: gesendete Rahmen, geänderte Konfigurationsbytes nach der Aktion,
und ob nach dem Rückgängigmachen alles wieder byte-genau wie vorher ist.
"""

from __future__ import annotations

import json

import snapshot
from runner import RESULTS, Editor, run

e = Editor()
LOG = RESULTS.parent / "probes.jsonl"


def probe(name: str, do, undo, note: str = "", snap: bool = False) -> dict:
    if "Connected" not in e.win.window_text():
        raise RuntimeError("Editor ist offline – Test abgebrochen")
    before = snapshot.read() if snap else None
    changed = []

    def action():
        do()
        if snap:
            mid = snapshot.read()
            changed.extend((snapshot.where(i), a, b) for i, a, b in snapshot.diff(before, mid))
        if undo:
            undo()

    frames = run(name, action, note)
    if "Connected" not in e.win.window_text():
        print("   WARNUNG: Editor während des Tests offline gegangen")
    after = snapshot.read() if snap else None
    restored = after == before if snap else None
    res = {"test": name, "note": note, "frames": frames, "config_changes": changed, "restored": restored}
    if snap and not restored:
        res["left_over"] = [(snapshot.where(i), a, b) for i, a, b in snapshot.diff(before, after)]
    with LOG.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(res, ensure_ascii=False) + "\n")
    if snap:
        print(f"   Konfig: {changed}")
        print(f"   wiederhergestellt: {restored}" + ("" if restored else f"  REST: {res['left_over']}"))
    return res
