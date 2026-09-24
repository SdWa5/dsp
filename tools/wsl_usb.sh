#!/usr/bin/env bash
# USB-Test unter Linux (DSP per usbipd an WSL angehängt), nur lesend.
set -uo pipefail
export PATH="$HOME/.local/bin:$PATH"
PY="$HOME/.local/share/trackdsp/bin/python"
echo "==> hidapi-Geräteliste"
"$PY" -c "import hid; print([(hex(d['vendor_id']), hex(d['product_id']), d['product_string'], d['path']) for d in hid.enumerate(0x0168, 0x0821)])"
echo "==> trackdsp -t usb info"
trackdsp -t usb info
echo "==> trackdsp -t usb status"
trackdsp -t usb status
echo "==> GUI über USB"
"$PY" - <<'EOF'
import time
from PySide6.QtWidgets import QApplication
from trackdsp.gui.main import MainWindow
app = QApplication(["trackdsp-gui"])
w = MainWindow(target="usb")
w.show()
got = {}
w.ctl.state_loaded.connect(lambda d: got.setdefault("dev", d))
w.ctl.meters.connect(lambda m: got.__setitem__("m", got.get("m", 0) + 1))
t0 = time.time()
while time.time() - t0 < 30 and not (got.get("dev") and got.get("m", 0) >= 5):
    app.processEvents(); time.sleep(0.05)
d = got.get("dev")
print("Plattform:", app.platformName(), "| Titel:", w.windowTitle())
if d:
    print(f"Preset U{d.active_preset:02d} {d.preset_name} | IP (per USB gelesen): {d.ip} | Pegelabfragen: {got.get('m', 0)}")
w.grab().save("/mnt/c/Users/GitiGlitzer/Projekte/T.rack/tools/shots/app/linux_usb_Gain.png")
w.close(); app.processEvents()
EOF
