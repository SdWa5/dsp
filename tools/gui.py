"""GUI-Automatisierung des t.racks-Editors (Windows) für Protokoll-Mitschnitte.

    python tools/gui.py shot [name]          Screenshot des Editorfensters -> tools/shots/name.png
    python tools/gui.py tree                 Control-Struktur ausgeben
    python tools/gui.py click X Y            Klick relativ zur Fensterecke
    python tools/gui.py dclick X Y           Doppelklick
    python tools/gui.py drag X1 Y1 X2 Y2     Ziehen (Fader)
    python tools/gui.py scroll X Y N         Mausrad (N>0 hoch, N<0 runter)
    python tools/gui.py keys "{TAB}..."      Tastatureingabe (pywinauto-Syntax)
"""

from __future__ import annotations

import ctypes
import sys
import time
import warnings
from pathlib import Path

# Physische Pixel verwenden, sonst passen Screenshot- und Klickkoordinaten nicht zusammen
ctypes.windll.shcore.SetProcessDpiAwareness(2)
warnings.filterwarnings("ignore", message="32-bit application")

from PIL import ImageGrab
from pywinauto import Application, mouse
from pywinauto.keyboard import send_keys

TITLE_RE = r"the t\.racks 8x8 Matrix Digital Processor Editor.*"
SHOTS = Path(__file__).parent / "shots"


def window():
    app = Application(backend="win32").connect(class_name="TfmMain", timeout=5)
    win = app.window(class_name="TfmMain")
    if win.is_minimized():
        win.restore()
    win.set_focus()
    time.sleep(0.3)
    return win


WM_SETFOCUS, WM_KILLFOCUS, WM_SETTEXT = 0x0007, 0x0008, 0x000C
WM_KEYDOWN, WM_KEYUP, WM_CHAR = 0x0100, 0x0101, 0x0102
VK_RETURN = 0x0D
VK = {"UP": 0x26, "DOWN": 0x28, "LEFT": 0x25, "RIGHT": 0x27, "PGUP": 0x21,
      "PGDN": 0x22, "HOME": 0x24, "END": 0x23, "RETURN": VK_RETURN}


class POINT(ctypes.Structure):
    _fields_ = [("x", ctypes.c_long), ("y", ctypes.c_long)]


def control_at(x: int, y: int) -> int:
    """Tiefstes sichtbares Kind-Control an Bildschirmposition (x, y), Z-Reihenfolge beachtet."""
    u = ctypes.windll.user32
    h = window().handle
    while True:
        pt = POINT(x, y)
        u.ScreenToClient(h, ctypes.byref(pt))
        child = u.ChildWindowFromPointEx(h, pt, 0x0001 | 0x0004)  # SKIPINVISIBLE|SKIPTRANSPARENT
        if not child or child == h:
            break
        h = child
    if h == window().handle:
        raise SystemExit("Kein Control an dieser Position gefunden")
    return h


def post_key(h: int, vk: int) -> None:
    u = ctypes.windll.user32
    u.PostMessageW(h, WM_KEYDOWN, vk, 1)
    if vk == VK_RETURN:
        u.PostMessageW(h, WM_CHAR, 0x0D, 1)
    u.PostMessageW(h, WM_KEYUP, vk, 0xC0000001)


def origin(win) -> tuple[int, int]:
    r = win.rectangle()
    return r.left, r.top


def shot(name: str = "current") -> Path:
    win = window()
    r = win.rectangle()
    SHOTS.mkdir(exist_ok=True)
    path = SHOTS / f"{name}.png"
    ImageGrab.grab(bbox=(r.left, r.top, r.right, r.bottom), all_screens=True).save(path)
    print(f"{path}  ({r.width()}x{r.height()} @ {r.left},{r.top})")
    return path


def tree() -> None:
    win = window()
    ox, oy = origin(win)
    for c in win.descendants():
        r = c.rectangle()
        text = (c.window_text() or "").replace("\n", " ")[:40]
        print(f"{c.friendly_class_name():18} {c.class_name():22} "
              f"({r.left - ox:4},{r.top - oy:4},{r.width():4}x{r.height():3}) {text!r}")


def main(argv: list[str]) -> None:
    cmd, args = argv[0], argv[1:]
    if cmd == "shot":
        shot(*(args or ["current"]))
        return
    if cmd == "tree":
        tree()
        return
    win = window()
    ox, oy = origin(win)
    if cmd in ("click", "dclick", "rclick"):
        x, y = int(args[0]) + ox, int(args[1]) + oy
        {"click": mouse.click, "dclick": mouse.double_click,
         "rclick": mouse.right_click}[cmd](coords=(x, y))
    elif cmd == "drag":
        x1, y1, x2, y2 = (int(a) for a in args)
        mouse.press(coords=(x1 + ox, y1 + oy))
        steps = 15
        for i in range(1, steps + 1):
            mouse.move(coords=(x1 + ox + (x2 - x1) * i // steps, y1 + oy + (y2 - y1) * i // steps))
            time.sleep(0.03)
        mouse.release(coords=(x2 + ox, y2 + oy))
    elif cmd == "scroll":
        mouse.scroll(coords=(int(args[0]) + ox, int(args[1]) + oy), wheel_dist=int(args[2]))
    elif cmd == "keys":
        send_keys(args[0], with_spaces=True)
    elif cmd == "settext":
        # Text direkt ins Control an (X,Y) schreiben und mit Enter bestätigen
        h = control_at(int(args[0]) + ox, int(args[1]) + oy)
        u = ctypes.windll.user32
        u.SendMessageW(h, WM_SETFOCUS, 0, 0)
        u.SendMessageW(h, WM_SETTEXT, 0, ctypes.c_wchar_p(args[2]))
        post_key(h, VK_RETURN)
        u.SendMessageW(h, WM_KILLFOCUS, 0, 0)
    elif cmd == "type":
        # Echter Klick ins Feld, Vordergrund prüfen, Inhalt löschen, Text + Enter tippen
        mouse.click(coords=(int(args[0]) + ox, int(args[1]) + oy))
        time.sleep(0.2)
        if ctypes.windll.user32.GetForegroundWindow() != win.handle:
            raise SystemExit("Editor nicht im Vordergrund – Eingabe abgebrochen")
        send_keys("{END}" + "{BACKSPACE}" * 14 + args[2].replace("-", "{-}").replace("+", "{+}") + "{ENTER}{TAB}")
    elif cmd == "fkeys":
        # Echte Tasten ans fokussierte Control, nur wenn der Editor vorne ist
        if ctypes.windll.user32.GetForegroundWindow() != win.handle:
            raise SystemExit("Editor nicht im Vordergrund – Eingabe abgebrochen")
        send_keys(args[0])
    elif cmd == "key":
        # Taste an Control an (X,Y) schicken, z. B. key 1484 742 UP 3
        h = control_at(int(args[0]) + ox, int(args[1]) + oy)
        vk = VK[args[2].upper()]
        for _ in range(int(args[3]) if len(args) > 3 else 1):
            post_key(h, vk)
            time.sleep(0.08)
    elif cmd == "what":
        h = control_at(int(args[0]) + ox, int(args[1]) + oy)
        buf = ctypes.create_unicode_buffer(256)
        ctypes.windll.user32.GetClassNameW(h, buf, 256)
        cls = buf.value
        ctypes.windll.user32.GetWindowTextW(h, buf, 256)
        print(hex(h), cls, repr(buf.value))
    else:
        raise SystemExit(__doc__)
    time.sleep(0.4)


if __name__ == "__main__":
    main(sys.argv[1:] or ["shot"])
