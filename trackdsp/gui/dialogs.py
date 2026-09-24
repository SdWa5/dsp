"""Dialoge der Menüleiste und der Preset-Leiste."""

from __future__ import annotations

import copy

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (QButtonGroup, QComboBox, QDialog, QDialogButtonBox, QFormLayout, QGridLayout,
                               QGroupBox, QHBoxLayout, QLabel, QLineEdit, QListWidget, QMessageBox, QPushButton,
                               QRadioButton, QSpinBox, QVBoxLayout)

from .. import commands as C
from .. import tables as T
from ..client import preset_label
from .widgets import MUTE_RED, Fader, Toggle


def _buttons(dlg, ok="OK"):
    bb = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
    bb.button(QDialogButtonBox.Ok).setText(ok)
    bb.accepted.connect(dlg.accept)
    bb.rejected.connect(dlg.reject)
    return bb


class ConnectDialog(QDialog):
    """Verbindung wählen: USB, Netzwerk (TCP 9761) oder Demo (offline)."""

    def __init__(self, last_target: str = "usb", parent=None):
        super().__init__(parent)
        self.setWindowTitle("Connect")
        v = QVBoxLayout(self)
        self.usb = QRadioButton("USB")
        self.net = QRadioButton("Netzwerk (TCP)")
        self.demo = QRadioButton("Demo (ohne Gerät)")
        self.host = QLineEdit("192.168.68.10:9761")
        if last_target and last_target != "usb":
            self.net.setChecked(True)
            self.host.setText(last_target)
        else:
            self.usb.setChecked(True)
        for w in (self.usb, self.net, self.host, self.demo):
            v.addWidget(w)
        scan = QPushButton("Scanning")
        scan.setToolTip("Nach USB-Geräten suchen")
        scan.clicked.connect(self._scan)
        self.info = QLabel("")
        v.addWidget(scan)
        v.addWidget(self.info)
        v.addWidget(_buttons(self, "Verbinden"))

    def _scan(self):
        try:
            from ..device import find_devices
            found = find_devices()
            self.info.setText(f"{len(found)} USB-Gerät(e) gefunden" if found else "Kein USB-Gerät gefunden")
        except Exception as e:  # noqa: BLE001
            self.info.setText(f"USB nicht verfügbar: {e}")

    def target(self):
        if self.demo.isChecked():
            return None
        return "usb" if self.usb.isChecked() else self.host.text().strip()


class ChannelNameDialog(QDialog):
    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Channel Name")
        g = QGridLayout(self)
        self.edits = []
        for i in range(16):
            c = ctx.chan(i)
            e = QLineEdit(c.name)
            e.setMaxLength(8)
            g.addWidget(QLabel(f"In{i + 1}" if i < 8 else f"Out{i - 7}"), i % 8, (i // 8) * 2)
            g.addWidget(e, i % 8, (i // 8) * 2 + 1)
            self.edits.append(e)
        g.addWidget(_buttons(self, "Set"), 8, 0, 1, 4)

    def apply(self):
        for i, e in enumerate(self.edits):
            name = e.text().strip()[:8]
            if name and name != self.ctx.chan(i).name:
                self.ctx.chan(i).name = name
                self.ctx.send(C.channel_name(i, name), key=("name", i))
        self.ctx.refreshed.emit()


class LinkDialog(QDialog):
    """Kanalkopplung: Kanäle derselben Gruppe werden gemeinsam bedient (Master = OR-Maske)."""

    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Link Selection")
        v = QVBoxLayout(self)
        v.addWidget(QLabel("Gleiche Gruppennummer = gekoppelt. 0 = nicht gekoppelt."))
        self.combos = {}
        for side, base in (("In", 0), ("Out", 8)):
            box = QGroupBox(side)
            h = QHBoxLayout(box)
            groups = self._groups(base)
            for i in range(8):
                col = QVBoxLayout()
                col.addWidget(QLabel(f"{side}{i + 1}", alignment=Qt.AlignCenter))
                cb = QComboBox()
                cb.addItems([str(k) for k in range(5)])
                cb.setCurrentIndex(groups.get(i, 0))
                col.addWidget(cb)
                h.addLayout(col)
                self.combos[base + i] = cb
            v.addWidget(box)
        v.addWidget(_buttons(self, "Set"))

    def _groups(self, base):
        lst = self.ctx.dev.inputs if base == 0 else self.ctx.dev.outputs
        res, g = {}, 0
        for c in lst:
            if bin(c.link).count("1") > 1:
                g += 1
                for i in range(8):
                    if c.link >> i & 1:
                        res[i] = g
        return res

    def frames(self):
        """Wie der Editor: neue Slaves erst vom Master kopieren (2A), dann Link-Flags (3B), dann 12."""
        frames, links = [], {}
        for base in (0, 8):
            lst = self.ctx.dev.inputs if base == 0 else self.ctx.dev.outputs
            groups = {}
            for i in range(8):
                groups.setdefault(self.combos[base + i].currentIndex() or f"solo{i}", []).append(i)
            for members in groups.values():
                members = sorted(members)
                mask = sum(1 << i for i in members)
                master = members[0]
                for k, i in enumerate(members):
                    val = mask if k == 0 else 0
                    if k and lst[i].link != val:
                        frames.append(C.copy_channel(base + master, base + i))
                    links[base + i] = val
        for ch, val in links.items():
            if self.ctx.chan(ch).link != val:
                frames.append(C.link(ch, val))
        if frames:
            frames.append(C.activate())
        return frames

    def apply(self):
        frames = self.frames()
        if frames:
            self.ctx.ctl.send_many(frames)
            self.ctx.ctl.reload()      # Gerät hat Slaves überschrieben -> neu einlesen


class CopyDialog(QDialog):
    """Parameter eines Kanals auf einen anderen kopieren (wie Menü „Copy“)."""

    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Channel Copy")
        form = QFormLayout(self)
        self.kind = QComboBox()
        self.kind.addItems(["In", "Out"])
        self.src, self.dst = QComboBox(), QComboBox()
        self.kind.currentIndexChanged.connect(self._fill)
        form.addRow("Typ", self.kind)
        form.addRow("Von", self.src)
        form.addRow("Nach", self.dst)
        form.addRow(_buttons(self, "Copy"))
        self._fill()

    def _fill(self):
        pre = self.kind.currentText()
        for cb in (self.src, self.dst):
            cb.clear()
            cb.addItems([f"{pre}{i + 1}" for i in range(8)])
        self.dst.setCurrentIndex(1)

    def apply(self):
        base = 0 if self.kind.currentIndex() == 0 else 8
        s, d = base + self.src.currentIndex(), base + self.dst.currentIndex()
        if s == d:
            return
        self.ctx.ctl.send_many([C.copy_channel(s, d)])   # ✓ Gerät kopiert selbst (wie der Editor)
        self.ctx.ctl.reload()


class LockDialog(QDialog):
    """Geräte-PIN (≈ 4x4-Protokoll: 2F setzt die PIN und sperrt sofort, 2D entsperrt)."""

    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Lock")
        form = QFormLayout(self)
        self.pin = QLineEdit()
        self.pin.setMaxLength(4)
        self.pin.setInputMask("9999")
        form.addRow("PIN (4 Ziffern)", self.pin)
        warn = QLabel("Achtung: „Sperren“ setzt die PIN und sperrt das Gerät sofort.\n"
                      "Ohne die PIN lässt es sich danach nicht mehr bedienen.")
        warn.setStyleSheet("color:#e5484d")
        form.addRow(warn)
        h = QHBoxLayout()
        lock, unlock = QPushButton("Sperren (PIN setzen)"), QPushButton("Entsperren")
        lock.clicked.connect(self._lock)
        unlock.clicked.connect(self._unlock)
        h.addWidget(lock)
        h.addWidget(unlock)
        form.addRow(h)

    def _lock(self):
        if len(self.pin.text()) != 4:
            return
        if QMessageBox.warning(self, "Gerät sperren", f"PIN {self.pin.text()} setzen und Gerät sofort sperren?",
                               QMessageBox.Yes | QMessageBox.No) == QMessageBox.Yes:
            self.ctx.send(C.set_pin(self.pin.text()), key=("pin",))
            self.accept()

    def _unlock(self):
        if len(self.pin.text()) == 4:
            self.ctx.send(C.unlock(self.pin.text()), key=("unlock",))
            self.accept()


class IdIpDialog(QDialog):
    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Setting ID/IP")
        form = QFormLayout(self)
        self.id = QSpinBox()
        self.id.setRange(1, 254)
        self.id.setValue(ctx.dev.device_id or 1)
        form.addRow("ID Number", self.id)
        ip = QLineEdit(ctx.dev.ip or "")
        ip.setReadOnly(True)
        ip.setToolTip("Die IP-Adresse wird angezeigt; Ändern ist noch nicht entschlüsselt.")
        form.addRow("IP Address", ip)
        form.addRow(_buttons(self, "Set"))

    def apply(self):
        self.ctx.dev.device_id = self.id.value()
        self.ctx.send(C.device_id(self.id.value()), key=("id",))


class TestToneDialog(QDialog):
    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Input Signal Select")
        v = QVBoxLayout(self)
        self.group = QButtonGroup(self)
        for k, name in enumerate(T.TONE_MODES):
            rb = QRadioButton(name)
            self.group.addButton(rb, k)
            v.addWidget(rb)
        self.freq = QComboBox()
        self.freq.addItems(T.TONE_FREQS)
        self.freq.setCurrentIndex(ctx.dev.tone_freq or 17)
        v.addWidget(self.freq)
        if ctx.dev.tone_mode is not None:
            self.group.button(ctx.dev.tone_mode).setChecked(True)
        warn = QLabel("Testsignale ersetzen die Analogeingänge – Lautstärke der Anlage beachten!")
        warn.setStyleSheet("color:#e5484d")
        v.addWidget(warn)
        self.group.idClicked.connect(self._send)
        self.freq.activated.connect(lambda _i: self.group.checkedId() == 3 and self._send(3))
        close = QPushButton("Exit")
        close.clicked.connect(self.accept)
        v.addWidget(close)

    def _send(self, mode):
        self.ctx.dev.tone_mode = mode
        self.ctx.dev.tone_freq = self.freq.currentIndex()
        self.ctx.send(C.test_tone(mode, self.freq.currentIndex() if mode == 3 else 0), key=("tone",))


class GroupStrip(QGroupBox):
    """Gain-% / Mute / Kanalauswahl – für Sub Control und Group Control."""

    def __init__(self, title, prefix, grp, on_change):
        super().__init__(title)
        self.grp, self.on_change = grp, on_change
        h = QHBoxLayout(self)
        left = QVBoxLayout()
        self.fader = Fader("Gain", 0, 100, lambda v: f"{v}%", grp.gain, height=150)
        self.mute = Toggle("Mute", color=MUTE_RED)
        self.mute.set_state(grp.mute)
        left.addWidget(self.fader)
        left.addWidget(self.mute)
        h.addLayout(left)
        sel = QVBoxLayout()
        self.sel = []
        for i in range(8):
            b = Toggle(f"{prefix}{i + 1}", color="#69f0ae")
            b.set_state(bool(grp.mask >> i & 1))
            b.toggled_by_user.connect(lambda _v: self._changed())
            sel.addWidget(b)
            self.sel.append(b)
        h.addLayout(sel)
        self.fader.changed.connect(lambda _v: self._changed())
        self.mute.toggled_by_user.connect(lambda _v: self._changed())

    def _changed(self):
        self.grp.gain = self.fader.value()
        self.grp.mute = self.mute.isChecked()
        self.grp.mask = sum(1 << i for i, b in enumerate(self.sel) if b.isChecked())
        self.on_change(self.grp)


class SubControlDialog(QDialog):
    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Sub Control Setting")
        h = QHBoxLayout(self)
        extra = ctx.dev.sub_extra  # letztes Byte so zurückschreiben, wie es im Gerät steht
        for io, (title, prefix) in enumerate((("Input", "In"), ("Output", "Out"))):
            g = ctx.dev.sub[io]
            h.addWidget(GroupStrip(title, prefix, g, lambda grp, io=io: ctx.send(
                C.sub_control(io, grp.gain, grp.mute, grp.mask, extra[io]), key=("sub", io))))


class GroupControlDialog(QDialog):
    def __init__(self, ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Group Control Setting")
        v = QVBoxLayout(self)
        for side, (title, prefix) in enumerate((("Input", "In"), ("Output", "Out"))):
            box = QGroupBox(title)
            h = QHBoxLayout(box)
            for k in range(4):
                idx = side * 4 + k
                g = ctx.dev.groups[idx]
                h.addWidget(GroupStrip(f"Group{k + 1}", prefix, g, lambda grp, idx=idx: ctx.send(
                    C.group(idx, grp.gain, grp.mute, grp.mask), key=("group", idx))))
            v.addWidget(box)


class PresetDialog(QDialog):
    """Store/Recall: Liste U01–U20 wie im Editor."""

    def __init__(self, ctx, store: bool, parent=None):
        super().__init__(parent)
        self.ctx, self.store = ctx, store
        self.setWindowTitle("Store Preset" if store else "Recall Preset")
        v = QVBoxLayout(self)
        self.list = QListWidget()
        names = ctx.dev.preset_names or [""] * 21
        order = list(range(20)) + ([] if store else [20])   # F00 nur zum Laden
        for slot in order:
            self.list.addItem(f"{preset_label(slot)}   {names[slot] if slot < len(names) else ''}")
        self.slots = order
        v.addWidget(self.list)
        if store:
            self.name = QLineEdit(ctx.dev.preset_name)
            self.name.setMaxLength(14)
            v.addWidget(QLabel("Name"))
            v.addWidget(self.name)
            self.list.currentRowChanged.connect(
                lambda r: self.name.setText((names[self.slots[r]] or "").strip()) if r >= 0 else None)
        v.addWidget(_buttons(self, "Store" if store else "Recall"))

    def chosen(self):
        r = self.list.currentRow()
        if r < 0:
            return None
        slot = self.slots[r]
        return 0 if slot == 20 else slot + 1   # Editor-Nummer: 0 = F00, 1 = U01 …
