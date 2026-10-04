#!/usr/bin/env bash
# Echter Start unter Linux (WSLg): CLI + GUI mit LAN-Verbindung, nur lesend.
set -uo pipefail
export PATH="$HOME/.local/bin:$PATH"
PY="$HOME/.local/share/trackdsp/bin/python"
OUT="${WIN_HOME:-/mnt/c/Users/$USER}/Projekte/T.rack/tools/shots/app"
mkdir -p "$OUT"

echo "==> Kommandozeile: trackdsp -t 192.168.68.10 info"
trackdsp -t 192.168.68.10 info
echo
echo "==> Startbefehl trackdsp-gui --demo (5 s)"
timeout 5 trackdsp-gui --demo >/tmp/gui_demo.log 2>&1
rc=$?
[[ $rc -eq 124 ]] && echo "läuft (nach 5 s beendet)" || { echo "Exit-Code $rc"; cat /tmp/gui_demo.log; }
echo
echo "==> GUI live mit Gerät (Qt-Plattform: ${QT_QPA_PLATFORM:-automatisch})"
"$PY" - "$OUT" <<'EOF'
import sys, time
from PySide6.QtWidgets import QApplication
from trackdsp.gui.main import MainWindow
out = sys.argv[1]
app = QApplication(["trackdsp-gui"])
print("Qt-Plattform:", app.platformName())
w = MainWindow(target="192.168.68.10")
w.resize(1500, 950)
w.show()
got = {}
w.ctl.state_loaded.connect(lambda d: got.setdefault("dev", d))
w.ctl.meters.connect(lambda m: got.__setitem__("m", got.get("m", 0) + 1))
t0 = time.time()
while time.time() - t0 < 30 and not (got.get("dev") and got.get("m", 0) >= 5):
    app.processEvents()
    time.sleep(0.05)
d = got.get("dev")
print("Fenstertitel:", w.windowTitle())
if d:
    print(f"Preset: U{d.active_preset:02d} {d.preset_name} | Gruppen gelesen: {d.groups[0].mask:#x} | Testton-Modus: {d.tone_mode}")
print("Pegelabfragen:", got.get("m", 0))
for name in ("Gain", "Out4", "Aux"):
    for i in range(w.tabs.count()):
        if w.tabs.tabText(i) == name:
            w.tabs.setCurrentIndex(i)
            for _ in range(20):
                app.processEvents(); time.sleep(0.05)
            w.grab().save(f"{out}/linux_{name}.png")
print("Screenshots gespeichert")
for _ in range(40):          # Fenster noch 2 s sichtbar lassen
    app.processEvents(); time.sleep(0.05)
w.close()
app.processEvents()
EOF
