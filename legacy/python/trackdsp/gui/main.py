"""Hauptfenster: t.racks 8x8 Matrix Editor für Linux (und Windows).

Start:  trackdsp-gui            (oder: python -m trackdsp.gui)
        trackdsp-gui --target 192.168.68.10     direkt per Netzwerk verbinden
        trackdsp-gui --demo                     ohne Gerät
"""

from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

from PySide6.QtCore import QSettings, Qt, QTimer
from PySide6.QtGui import QAction, QActionGroup, QIcon, QKeySequence
from PySide6.QtWidgets import (QApplication, QFileDialog, QHBoxLayout, QLabel, QMainWindow, QMessageBox,
                               QPushButton, QTabWidget, QTextBrowser, QVBoxLayout, QWidget)

from .. import commands as C
from .. import tables as T
from ..model import Device
from .controller import Controller
from .dialogs import (ChannelNameDialog, ConnectDialog, CopyDialog, GroupControlDialog, IdIpDialog, LinkDialog,
                      LockDialog, PresetDialog, SubControlDialog, TestToneDialog)
from .pages import (AuxPage, ChannelPage, CompPage, Ctx, DelayPage, GainPage, GatePage, MatrixPage, scrollable)

APP_NAME = "t.racks 8x8 Matrix Editor (Linux)"
FIXTURE = Path(__file__).resolve().parent.parent / "data" / "demo_config.bin"

RS232_HELP = """<h3>RS232/485-Fernsteuerung (Handbuch Kap. 6.1)</h3>
<p>115200 Baud, 8N1, ≥ 200 ms Abstand, Pakete <code>7B 7D ID CMD D1 D2 D3 7D 7B</code>.</p>
<table border=1 cellpadding=3>
<tr><th>CMD</th><th>Funktion</th><th>Daten</th></tr>
<tr><td>40 / 43</td><td>Preset laden</td><td>F:0/U:1, Nummer, 0</td></tr>
<tr><td>41 / 54</td><td>Gain +/− (Schritt)</td><td>In:0/Out:1, Kanal, +:0/−:1</td></tr>
<tr><td>42</td><td>Mute</td><td>In/Out, Kanal, 0/1</td></tr>
<tr><td>44 / 45</td><td>Lautstärke In / Out</td><td>Kanal, HI, LO (280 = 0 dB)</td></tr>
<tr><td>46 / 47 / 4C</td><td>Sub-Lautstärke %, ±, Mute</td><td>In/Out, …</td></tr>
<tr><td>48 / 49 / 4A / 4B / 4D</td><td>Gain / Mute / Preset / Sub / Pegel lesen</td><td>…</td></tr>
<tr><td>4E / 4F</td><td>Matrix setzen / lesen</td><td>Ausgang, Eingang, 0/1</td></tr>
<tr><td>51 / 52 / 53</td><td>Aux Gain ± / Mute / Lautstärke</td><td>02, …</td></tr>
<tr><td>55</td><td>Effekt/Kamera/AutoMix ein/aus</td><td>02, 0/1/2, 0/1</td></tr>
<tr><td>56</td><td>Aux-Quellenwahl</td><td>0/1/2, Maske hi, Maske lo</td></tr>
<tr><td>57 / 5E</td><td>FBQ setzen / lesen</td><td>00, Kanal, 0–4</td></tr>
<tr><td>58 / 59 / 5B / 5C</td><td>Aux lesen</td><td>…</td></tr>
</table>"""


def parse_meters(data: bytes) -> list[float]:
    """Pegelantwort (0x40): 17 Kanäle × 3 Byte (In1–8, Out1–8, Aux) – Näherung, siehe tables.meter_db."""
    out = []
    for i in range(17):
        o = 3 * i
        out.append(T.meter_db(data[o + 1], data[o]) if len(data) >= o + 2 else -90.0)
    return out


class MainWindow(QMainWindow):
    def __init__(self, target: str | None = None, demo: bool = False):
        super().__init__()
        self.setWindowTitle(APP_NAME)
        self.settings = QSettings("trackdsp", "editor")
        self.ctl = Controller()
        self.ctx = Ctx(self.ctl)
        self._build()
        self.ctl.connected.connect(self._on_connected)
        self.ctl.disconnected.connect(self._on_disconnected)
        self.ctl.state_loaded.connect(self._on_state)
        self.ctl.meters.connect(self._on_meters)
        self.ctl.busy.connect(lambda s: self.statusBar().showMessage(s))
        self.ctx.refreshed.connect(self._refresh_bottom)
        self.resize(1500, 950)
        if demo:
            self._demo()
        elif target:
            QTimer.singleShot(100, lambda: self._connect(target))
        else:
            QTimer.singleShot(100, self._ask_connect)

    # --- Aufbau -----------------------------------------------------------------------
    def _build(self):
        central = QWidget()
        v = QVBoxLayout(central)
        top = QHBoxLayout()
        self.tabs = QTabWidget()
        self.pages = {}
        self.gain_page = GainPage(self.ctx)
        for name, page in (("Gain", self.gain_page), ("Gate", GatePage(self.ctx)), ("Comp", CompPage(self.ctx)),
                           ("Delay", DelayPage(self.ctx)), ("Matrix", MatrixPage(self.ctx)),
                           ("Aux", AuxPage(self.ctx))):
            self.pages[name] = page
            self.tabs.addTab(scrollable(page), name)
        for i in range(8):
            p = ChannelPage(self.ctx, i)
            self.pages[f"In{i + 1}"] = p
            self.tabs.addTab(scrollable(p), f"In{i + 1}")
        for i in range(8):
            p = ChannelPage(self.ctx, 8 + i)
            self.pages[f"Out{i + 1}"] = p
            self.tabs.addTab(scrollable(p), f"Out{i + 1}")
        self.id_label = QLabel("ID : 1")
        self.online_btn = QPushButton("OffLine")
        self.online_btn.setMinimumWidth(90)
        self.online_btn.clicked.connect(self._toggle_online)
        top.addWidget(self.tabs, 1)
        v.addLayout(top, 1)
        corner = QWidget()
        cl = QHBoxLayout(corner)
        cl.setContentsMargins(0, 0, 4, 0)
        cl.addWidget(self.id_label)
        cl.addWidget(self.online_btn)
        self.tabs.setCornerWidget(corner, Qt.TopRightCorner)
        # Preset-Leiste
        bottom = QHBoxLayout()
        self.number = QLabel("Number: –")
        self.name = QLabel("Name: –")
        for w in (self.number, self.name):
            w.setStyleSheet("font-size:15px;font-weight:bold;padding:4px 12px")
        store, recall = QPushButton("Store"), QPushButton("Recall")
        store.clicked.connect(self._store)
        recall.clicked.connect(self._recall)
        bottom.addWidget(self.number)
        bottom.addWidget(self.name)
        bottom.addStretch()
        bottom.addWidget(store)
        bottom.addWidget(recall)
        v.addLayout(bottom)
        self.setCentralWidget(central)
        self._menus()

    def _menus(self):
        mb = self.menuBar()
        m = mb.addMenu("&File")
        for text, fn, key in (("&Open …", self._open, QKeySequence.Open), ("&Save …", self._save, QKeySequence.Save),
                              (None, None, None),
                              ("&Upload (Datei → Gerät)", self._upload, None),
                              ("&Download (Gerät → Programm)", self._download, QKeySequence.Refresh),
                              (None, None, None), ("&Factory Default", self._factory, None),
                              (None, None, None), ("E&xit", self.close, QKeySequence.Quit)):
            if text is None:
                m.addSeparator()
                continue
            a = QAction(text, self)
            a.triggered.connect(fn)
            if key:
                a.setShortcut(key)
            m.addAction(a)
        self._action(mb, "L&ink", lambda: self._dialog(LinkDialog))
        self._action(mb, "&Copy", lambda: self._dialog(CopyDialog))
        self._action(mb, "&Lock", lambda: LockDialog(self.ctx, self).exec())
        ws = mb.addMenu("W&orking Status")
        self.ws_group = QActionGroup(self)
        for k, name in enumerate(T.WORKING_STATUS):
            a = QAction(name, self, checkable=True)
            a.triggered.connect(lambda _c, k=k: self._working_status(k))
            self.ws_group.addAction(a)
            ws.addAction(a)
        self._action(mb, "&Setting ID/IP", lambda: self._dialog(IdIpDialog))
        self._action(mb, "&Test Tone", lambda: TestToneDialog(self.ctx, self).exec())
        self._action(mb, "C&hannel Name", lambda: self._dialog(ChannelNameDialog))
        self._action(mb, "S&ub Control", lambda: SubControlDialog(self.ctx, self).exec())
        self._action(mb, "&Group Control", lambda: GroupControlDialog(self.ctx, self).exec())
        h = mb.addMenu("H&elp")
        a = QAction("&RS232 Code Table", self)
        a.triggered.connect(self._rs232)
        h.addAction(a)
        self._action(mb, "&About", self._about)

    def _action(self, mb, text, fn):
        a = QAction(text, self)
        a.triggered.connect(fn)
        mb.addAction(a)

    def _dialog(self, cls):
        d = cls(self.ctx, self)
        if d.exec():
            d.apply()

    # --- Verbindung ---------------------------------------------------------------
    def _ask_connect(self):
        d = ConnectDialog(self.settings.value("target", "usb"), self)
        if d.exec():
            t = d.target()
            if t is None:
                self._demo()
            else:
                self._connect(t)
        else:
            self._demo()

    def _connect(self, target):
        self.settings.setValue("target", target)
        self.online_btn.setText("…")
        self.ctl.connect_to(target)

    def _toggle_online(self):
        if self.ctl.online:
            self.ctl.disconnect()
        else:
            self._ask_connect()

    def _on_connected(self, info):
        self.online_btn.setText("OnLine")
        self.online_btn.setStyleSheet("background:#3fcf5f;color:#000;font-weight:bold")
        self.setWindowTitle(f"{APP_NAME}    Connected => {self.ctl.target}  ({info})")

    def _on_disconnected(self, reason):
        self.online_btn.setText("OffLine")
        self.online_btn.setStyleSheet("")
        self.setWindowTitle(APP_NAME)
        self.statusBar().showMessage(reason)

    def _on_state(self, dev: Device):
        self.ctx.set_device(dev)

    def _on_meters(self, data: bytes):
        levels = parse_meters(data)
        self.gain_page.show_meters(levels)
        for i in range(8):
            self.pages[f"In{i + 1}"].meter.set_db(levels[i])
            self.pages[f"Out{i + 1}"].meter.set_db(levels[8 + i])
        self.pages["Aux"].meter.set_db(levels[16])

    def _demo(self):
        dev = Device.parse(FIXTURE.read_bytes()) if FIXTURE.exists() else Device()
        dev.preset_names = [""] * 21
        self.ctx.set_device(dev)
        self.statusBar().showMessage("Demo-Modus – keine Verbindung zum Gerät")

    def _refresh_bottom(self):
        d = self.ctx.dev
        n = d.active_preset
        self.number.setText(f"Number: {'U%02d' % n if n else ('F00' if n == 0 else '–')}")
        self.name.setText(f"Name: {d.preset_name or '–'}")
        self.id_label.setText(f"ID : {d.device_id}")
        for a, k in zip(self.ws_group.actions(), range(2)):
            a.setChecked(d.working_status == k)

    # --- Datei ----------------------------------------------------------------------
    def _open(self):
        fn, _ = QFileDialog.getOpenFileName(self, "Preset-Datei öffnen", "", "trackdsp (*.json);;Rohdaten (*.bin)")
        if not fn:
            return
        data = Path(fn).read_bytes()
        dev = Device.parse(data) if fn.endswith(".bin") else Device.from_json(data.decode("utf-8"))
        self.ctx.set_device(dev)
        self.statusBar().showMessage(f"Geladen: {fn} – mit „Upload“ auf das Gerät übertragen")

    def _save(self):
        fn, _ = QFileDialog.getSaveFileName(self, "Preset-Datei speichern", f"{self.ctx.dev.preset_name or 'preset'}.json",
                                            "trackdsp (*.json)")
        if fn:
            Path(fn).write_text(self.ctx.dev.to_json(), encoding="utf-8")
            self.statusBar().showMessage(f"Gespeichert: {fn}")

    def _upload(self):
        if not self.ctl.online:
            QMessageBox.information(self, "Upload", "Keine Verbindung zum Gerät.")
            return
        frames = self.ctx.dev.all_frames()
        if QMessageBox.question(self, "Upload", f"{len(frames)} Einstellungen auf das Gerät schreiben?") \
                == QMessageBox.Yes:
            self.ctl.send_many(frames)
            self.ctl.reload()

    def _download(self):
        if self.ctl.online:
            self.ctl.reload()

    def _factory(self):
        if QMessageBox.warning(self, "Factory Default", "Werkseinstellung (F00) laden? Der aktuelle Zustand geht "
                               "verloren.", QMessageBox.Yes | QMessageBox.No) == QMessageBox.Yes:
            self._load_preset(0)

    # --- Presets --------------------------------------------------------------------
    def _load_preset(self, number):
        def job(dsp):
            dsp.write(C.load_preset(number))
            time.sleep(2)
        self.ctl.call(job)
        self.ctl.reload()

    def _recall(self):
        d = PresetDialog(self.ctx, store=False, parent=self)
        if d.exec() and d.chosen() is not None:
            self._load_preset(d.chosen())

    def _store(self):
        d = PresetDialog(self.ctx, store=True, parent=self)
        if d.exec() and d.chosen():
            n, name = d.chosen(), d.name.text().strip()[:14]
            if QMessageBox.question(self, "Store", f"Aktuellen Zustand als U{n:02d} „{name}“ speichern?") \
                    != QMessageBox.Yes:
                return

            def job(dsp):
                dsp.write(C.preset_name(name))
                dsp.write(C.store_preset(n))
                time.sleep(2)
            self.ctl.call(job)
            self.ctl.reload()

    def _working_status(self, k):
        self.ctx.dev.working_status = k
        self.ctx.send(C.working_status(k), key=("ws",))

    # --- Hilfe ----------------------------------------------------------------------
    def _rs232(self):
        tb = QTextBrowser()
        tb.setHtml(RS232_HELP)
        tb.setWindowTitle("RS232 Code Table")
        tb.resize(700, 500)
        tb.show()
        self._rs232_win = tb

    def _about(self):
        QMessageBox.about(self, "About", f"<b>{APP_NAME}</b><br>Freie Steuersoftware für die Thomann "
                          "t.racks 8x8 Matrix (USB-HID und TCP 9761).<br>Protokoll: siehe PROTOCOL.md")

    def closeEvent(self, e):
        self.ctl.disconnect()
        super().closeEvent(e)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(prog="trackdsp-gui", description=APP_NAME)
    ap.add_argument("--target", help="usb oder IP[:Port]")
    ap.add_argument("--demo", action="store_true", help="ohne Gerät starten")
    args = ap.parse_args(argv)
    app = QApplication(sys.argv[:1])
    app.setApplicationName("trackdsp")
    app.setDesktopFileName("trackdsp")
    app.setWindowIcon(QIcon(str(FIXTURE.parent / "trackdsp.svg")))
    w = MainWindow(args.target, args.demo)
    w.show()
    return app.exec()


if __name__ == "__main__":
    sys.exit(main())
