"""Hilfsfunktionen für automatisierte Protokoll-Tests (GUI bedienen + mitschneiden).

Jeder Test: Mitschnitt starten -> Aktionen im Editor -> Mitschnitt stoppen ->
gesendete Rahmen (ohne Pegelabfrage/ACK) in captures/results.jsonl protokollieren.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

from pywinauto.controls.hwndwrapper import HwndWrapper
from pywinauto.keyboard import send_keys

import cap
import gui
from PIL import ImageGrab

RESULTS = cap.ROOT / "captures" / "results.jsonl"


class Editor:
    def __init__(self):
        self.win = gui.window()
        self.ox, self.oy = gui.origin(self.win)

    # --- Eingabe ------------------------------------------------------------------
    def _front(self):
        import ctypes
        if ctypes.windll.user32.GetForegroundWindow() != self.win.handle:
            self.win.set_focus()
            time.sleep(0.2)
            if ctypes.windll.user32.GetForegroundWindow() != self.win.handle:
                raise RuntimeError("Editor nicht im Vordergrund – abgebrochen")

    def click(self, x, y, pause=0.6):
        self._front()
        gui.mouse.click(coords=(x + self.ox, y + self.oy))
        time.sleep(pause)

    def focus(self, x, y):
        """Control an (x, y) fokussieren, ohne es zu verändern."""
        self._front()
        HwndWrapper(gui.control_at(x + self.ox, y + self.oy)).set_focus()
        time.sleep(0.2)

    def keys(self, seq, pause=0.6):
        # Sprungtasten sind gesperrt: HOME setzte den Out-Fader auf +12 dB (invertiert)
        if any(k in seq.upper() for k in ("{HOME", "{END", "{PGUP", "{PGDN")):
            raise ValueError(f"Gesperrte Taste in {seq!r}")
        self._front()
        send_keys(seq)
        time.sleep(pause)

    def combo(self, x, y, index=None):
        """Auswahlliste an (x, y): Einträge + Auswahl lesen; mit index einen Eintrag wählen
        (CB_SETCURSEL + CBN_SELCHANGE an das Elternfenster – löst OnChange wie ein Mausklick aus)."""
        import ctypes
        u = ctypes.windll.user32
        h = gui.control_at(x + self.ox, y + self.oy)
        n = u.SendMessageW(h, 0x0146, 0, 0)
        items = []
        for i in range(max(0, n)):
            buf = ctypes.create_unicode_buffer(u.SendMessageW(h, 0x0149, i, 0) + 1)
            u.SendMessageW(h, 0x0148, i, buf)
            items.append(buf.value)
        cur = u.SendMessageW(h, 0x0147, 0, 0)
        if index is not None:
            u.SendMessageW(h, 0x014E, index, 0)
            cid = u.GetDlgCtrlID(h)
            u.SendMessageW(u.GetParent(h), 0x0111, (1 << 16) | (cid & 0xFFFF), h)
            time.sleep(0.6)
        return items, cur

    def nav(self, title):
        """Reiter oben (Gain, Gate, …, Out8) per BM_CLICK öffnen – sendet nichts ans Gerät."""
        for c in self.win.descendants():
            if c.class_name() == "TRzBmpButton" and c.window_text() == title and 60 < c.rectangle().top - self.oy < 140:
                c.click()
                time.sleep(0.8)
                return
        raise ValueError(f"Reiter {title!r} nicht gefunden")

    def text(self, x, y) -> str:
        import ctypes
        h = gui.control_at(x + self.ox, y + self.oy)
        buf = ctypes.create_unicode_buffer(256)
        ctypes.windll.user32.GetWindowTextW(h, buf, 256)
        return buf.value

    def shot(self, name, box=None) -> Path:
        r = self.win.rectangle()
        img = ImageGrab.grab(bbox=(r.left, r.top, r.right, r.bottom), all_screens=True)
        if box:
            img = img.crop(box)
        gui.SHOTS.mkdir(exist_ok=True)
        path = gui.SHOTS / f"{name}.png"
        img.save(path)
        return path


def run(name: str, action, note: str = "") -> list[str]:
    """Test ausführen, gesendete Rahmen zurückgeben und protokollieren."""
    cap.start(name)
    try:
        action()
    finally:
        cap.stop()
    sent = []
    for t, label, f, raw in cap.frames(name):
        if f is None or f.cmd == cap.p.CMD_ACK:
            continue
        line = f"{label} {f.cmd:02X} {f.data.hex(' ')}"
        # Doppelte direkt aufeinanderfolgende Sendungen (Editor schickt alles 2x) zusammenfassen
        if not sent or sent[-1] != line:
            sent.append(line)
    with RESULTS.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps({"test": name, "note": note, "frames": sent}, ensure_ascii=False) + "\n")
    print(f"== {name}: {note}  ({len(sent)} Rahmen)")
    for line in (sent if len(sent) <= 8 else sent[:3] + [f"… {len(sent) - 5} weitere …"] + sent[-2:]):
        print("   ", line)
    return sent
