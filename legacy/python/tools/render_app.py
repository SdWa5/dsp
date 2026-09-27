"""Rendert die App-Reiter offscreen als PNG (für Sichtprüfung ohne Fenster auf dem Bildschirm)."""
import os
import sys
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
os.environ.setdefault("QT_QPA_FONTDIR", "C:/Windows/Fonts")
from PySide6.QtWidgets import QApplication  # noqa: E402
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from trackdsp.gui.main import MainWindow  # noqa: E402

want = sys.argv[1:] or ["Gain", "Gate", "Comp", "Delay", "Matrix", "Aux", "In1", "Out3", "Out4"]
app = QApplication([])
w = MainWindow(demo=True)
w.resize(1600, 980)
w.show()
app.processEvents()
os.makedirs("tools/shots/app", exist_ok=True)
for i in range(w.tabs.count()):
    name = w.tabs.tabText(i)
    if name in want:
        w.tabs.setCurrentIndex(i)
        app.processEvents()
        w.grab().save(f"tools/shots/app/{i:02d}_{name}.png")
        print("ok", name)
