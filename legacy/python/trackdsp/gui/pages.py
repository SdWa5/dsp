"""Die Reiter des Editors: Gain, Gate, Comp, Delay, Matrix, Aux, In1–8, Out1–8."""

from __future__ import annotations

from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtWidgets import (QButtonGroup, QComboBox, QDialog, QDialogButtonBox, QFormLayout, QFrame, QGridLayout,
                               QGroupBox, QHBoxLayout, QLabel, QPushButton, QRadioButton, QScrollArea, QSpinBox,
                               QVBoxLayout, QWidget)

from .. import commands as C
from .. import tables as T
from ..model import Device, Filter, PeqBand
from .widgets import MUTE_RED, EqCurve, Fader, LevelMeter, Toggle

AUX = C.AUX
DEFAULT_SLOPE = 20  # LK -48, Voreinstellung des Editors


class Ctx(QObject):
    """Gemeinsamer Zustand: Gerätemodell + Senden (online) bzw. nur lokal ändern (offline)."""
    refreshed = Signal()

    def __init__(self, controller):
        super().__init__()
        self.ctl = controller
        self.dev = Device()
        self.remembered_slope: dict = {}   # (ch, "hpf"/"lpf") -> Flanke bei Bypass

    def send(self, frame, key=None):
        self.ctl.send(frame, key)

    def set_device(self, dev: Device):
        """Neuen Gerätezustand übernehmen. Nur Werte, die das Gerät nicht liefert, bleiben gemerkt."""
        old = self.dev
        for a, b in zip(old.inputs, dev.inputs):
            if b.phantom is None:
                b.phantom = a.phantom
        if dev.working_status is None:
            dev.working_status = old.working_status
        if not dev.raw:   # Datei ohne Rohdaten: gemerkte Zusatzwerte behalten
            dev.sub, dev.groups = old.sub, old.groups
        self.dev = dev
        self.refreshed.emit()

    # Kanal-Hilfen
    def chan(self, ch: int):
        if ch < 8:
            return self.dev.inputs[ch]
        if ch < 16:
            return self.dev.outputs[ch - 8]
        return self.dev.aux

    def linked(self, ch: int) -> list[int]:
        """Kanäle derselben Kopplungsgruppe (für Mute/Phase/Line, wie der Editor)."""
        if ch >= 16:
            return [ch]
        base, lst = (0, self.dev.inputs) if ch < 8 else (8, self.dev.outputs)
        bit = 1 << (ch - base)
        for c in lst:
            if c.link & bit and bin(c.link).count("1") > 1:
                return [base + i for i in range(8) if c.link >> i & 1]
        return [ch]


def gain_fmt(v: int) -> str:
    return f"{T.gain_to_db(v):+.1f}dB"


def fader_gain(ctx: Ctx, ch: int, title: str) -> Fader:
    f = Fader(title, 0, T.GAIN_MAX, gain_fmt, 280)
    f.changed.connect(lambda v: (setattr(ctx.chan(ch), "gain", v), ctx.send(C.gain(ch, v))))
    return f


def mute_button(ctx: Ctx, ch: int) -> Toggle:
    b = Toggle("Mute", "Mute", MUTE_RED)

    def on(v):
        for c in ctx.linked(ch):
            ctx.chan(c).mute = v
            ctx.send(C.mute(c, v), key=("mute", c))
        ctx.refreshed.emit()
    b.toggled_by_user.connect(on)
    return b


def phase_button(ctx: Ctx, ch: int) -> Toggle:
    b = Toggle("Normal", "Inverse", "#90caf9")

    def on(v):
        for c in ctx.linked(ch):
            ctx.chan(c).phase = v
            ctx.send(C.phase(c, v), key=("phase", c))
        ctx.refreshed.emit()
    b.toggled_by_user.connect(on)
    return b


# ---------------------------------------------------------------------------------------
class GainPage(QWidget):
    def __init__(self, ctx: Ctx):
        super().__init__()
        self.ctx = ctx
        top = QHBoxLayout(self)
        self.in_box, self.out_box = QWidget(), QWidget()
        self.strips_in, self.strips_out = [], []
        ib = QHBoxLayout(self.in_box)
        for i in range(8):
            ib.addWidget(self._input_strip(i))
        ob = QHBoxLayout(self.out_box)
        for i in range(8):
            ob.addWidget(self._output_strip(8 + i))
        stack = QVBoxLayout()
        stack.addWidget(self.in_box)
        stack.addWidget(self.out_box)
        self.out_box.hide()
        top.addLayout(stack, 1)
        sel = QGroupBox("Select")
        sl = QVBoxLayout(sel)
        self.b_in, self.b_out = QRadioButton("Input"), QRadioButton("Output")
        self.b_in.setChecked(True)
        sl.addWidget(self.b_in)
        sl.addWidget(self.b_out)
        sl.addStretch()
        self.b_in.toggled.connect(lambda on: (self.in_box.setVisible(on), self.out_box.setVisible(not on)))
        top.addWidget(sel)
        ctx.refreshed.connect(self.refresh)

    def _input_strip(self, ch):
        box = QGroupBox()
        v = QVBoxLayout(box)
        row = QHBoxLayout()
        fad = fader_gain(self.ctx, ch, "")
        meter = LevelMeter()
        row.addWidget(fad)
        row.addWidget(meter)
        v.addLayout(row)
        sens = QSpinBox()
        sens.setRange(0, 2)
        sens.setSuffix(" dB")
        sens.setToolTip("Mic-Empfindlichkeit (nur im Mic-Modus)")
        mode = Toggle("Line", "Mic", "#90caf9")
        ph = phase_button(self.ctx, ch)
        mu = mute_button(self.ctx, ch)
        p48 = Toggle("+48V", "+48V", "#ffb74d")

        def send_mode(_=None):
            m = (1 + sens.value()) if mode.isChecked() else 0
            for c in self.ctx.linked(ch):
                self.ctx.dev.inputs[c].mode = m
                self.ctx.send(C.input_mode(c, m), key=("mode", c))
            self.ctx.refreshed.emit()
        mode.toggled_by_user.connect(send_mode)
        sens.valueChanged.connect(lambda _: mode.isChecked() and send_mode())

        def send_48(v):
            for c in self.ctx.linked(ch):
                self.ctx.dev.inputs[c].phantom = v
                self.ctx.send(C.phantom(c, v), key=("48v", c))
            self.ctx.refreshed.emit()
        p48.toggled_by_user.connect(send_48)
        v.addWidget(sens)
        g = QGridLayout()
        g.addWidget(ph, 0, 0)
        g.addWidget(mode, 0, 1)
        g.addWidget(mu, 1, 0)
        g.addWidget(p48, 1, 1)
        v.addLayout(g)
        self.strips_in.append((box, fad, meter, sens, mode, ph, mu, p48))
        return box

    def _output_strip(self, ch):
        box = QGroupBox()
        v = QVBoxLayout(box)
        row = QHBoxLayout()
        fad = fader_gain(self.ctx, ch, "")
        meter = LevelMeter()
        row.addWidget(fad)
        row.addWidget(meter)
        v.addLayout(row)
        ph, mu = phase_button(self.ctx, ch), mute_button(self.ctx, ch)
        v.addWidget(ph)
        v.addWidget(mu)
        self.strips_out.append((box, fad, meter, ph, mu))
        return box

    def refresh(self):
        d = self.ctx.dev
        for i, (box, fad, meter, sens, mode, ph, mu, p48) in enumerate(self.strips_in):
            c = d.inputs[i]
            box.setTitle(c.name)
            fad.set_value(c.gain)
            mode.set_state(None if c.mode is None else c.mode > 0)
            sens.blockSignals(True)
            sens.setValue(max(0, (c.mode or 1) - 1))
            sens.setEnabled(bool(c.mode))
            sens.blockSignals(False)
            ph.set_state(c.phase)
            mu.set_state(c.mute)
            p48.set_state(c.phantom)
            p48.setEnabled(bool(c.mode))
        for i, (box, fad, meter, ph, mu) in enumerate(self.strips_out):
            c = d.outputs[i]
            box.setTitle(c.name)
            fad.set_value(c.gain)
            ph.set_state(c.phase)
            mu.set_state(c.mute)

    def show_meters(self, levels):
        for i, s in enumerate(self.strips_in):
            s[2].set_db(levels[i])
        for i, s in enumerate(self.strips_out):
            s[2].set_db(levels[8 + i])


# ---------------------------------------------------------------------------------------
class GatePage(QWidget):
    def __init__(self, ctx: Ctx):
        super().__init__()
        self.ctx = ctx
        lay = QHBoxLayout(self)
        self.cols = []
        for i in range(8):
            box = QGroupBox(f"In{i + 1}")
            g = QHBoxLayout(box)
            thr = Fader("Threshold", 0, 180, lambda v: f"{T.gate_thr_to_db(v):+.1f}dB")
            att = Fader("Attack", 0, 998, lambda v: f"{T.ms_from_raw(v)}ms", height=90)
            rel = Fader("Release", 0, 2999, lambda v: f"{T.ms_from_raw(v)}ms", height=90)
            right = QVBoxLayout()
            right.addWidget(att)
            right.addWidget(rel)
            g.addWidget(thr)
            g.addLayout(right)
            for f in (thr, att, rel):
                f.changed.connect(lambda _v, ch=i: self._send(ch))
            self.cols.append((box, thr, att, rel))
            lay.addWidget(box)
        ctx.refreshed.connect(self.refresh)

    def _send(self, ch):
        box, thr, att, rel = self.cols[ch]
        g = self.ctx.dev.inputs[ch].gate
        g.threshold, g.attack, g.release = thr.value(), att.value(), rel.value()
        self.ctx.send(C.gate(ch, g.attack, g.release, g.threshold))

    def refresh(self):
        for i, (box, thr, att, rel) in enumerate(self.cols):
            c = self.ctx.dev.inputs[i]
            box.setTitle(c.name)
            thr.set_value(c.gate.threshold)
            att.set_value(c.gate.attack)
            rel.set_value(c.gate.release)


# ---------------------------------------------------------------------------------------
class CompWidget(QGroupBox):
    """Kompressor eines Kanals (Ausgänge und Aux)."""

    def __init__(self, ctx: Ctx, ch: int, title: str):
        super().__init__(title)
        self.ctx, self.ch = ctx, ch
        g = QGridLayout(self)
        self.thr = Fader("Threshold", 0, 220, lambda v: f"{T.comp_thr_to_db(v):+.1f}dB", height=90)
        self.ratio = Fader("Ratio", 0, 15, lambda v: T.COMP_RATIOS[v], height=90)
        self.att = Fader("Attack", 0, 998, lambda v: f"{T.ms_from_raw(v)}ms", height=90)
        self.rel = Fader("Release", 9, 2999, lambda v: f"{T.ms_from_raw(v)}ms", height=90)
        self.knee = Fader("Knee", 0, 12, lambda v: f"{v}dB", height=60)
        g.addWidget(self.thr, 0, 0)
        g.addWidget(self.ratio, 0, 1)
        g.addWidget(self.att, 1, 0)
        g.addWidget(self.rel, 1, 1)
        g.addWidget(self.knee, 2, 0, 1, 2)
        for f in (self.thr, self.ratio, self.att, self.rel, self.knee):
            f.changed.connect(self._send)

    def _send(self, _=None):
        c = self.ctx.chan(self.ch).comp
        c.threshold, c.ratio, c.attack = self.thr.value(), self.ratio.value(), self.att.value()
        c.release, c.knee = self.rel.value(), self.knee.value()
        self.ctx.send(C.comp(self.ch, c.ratio, c.knee, c.attack, c.release, c.threshold))

    def refresh(self):
        c = self.ctx.chan(self.ch).comp
        for f, v in ((self.thr, c.threshold), (self.ratio, c.ratio), (self.att, c.attack),
                     (self.rel, c.release), (self.knee, c.knee)):
            f.set_value(v)


class CompPage(QWidget):
    def __init__(self, ctx: Ctx):
        super().__init__()
        self.ctx = ctx
        lay = QHBoxLayout(self)
        self.comps = [CompWidget(ctx, 8 + i, f"Out{i + 1}") for i in range(8)]
        for c in self.comps:
            lay.addWidget(c)
        ctx.refreshed.connect(self.refresh)

    def refresh(self):
        for i, c in enumerate(self.comps):
            c.setTitle(self.ctx.dev.outputs[i].name)
            c.refresh()


# ---------------------------------------------------------------------------------------
class DelayPage(QWidget):
    MAX = 32640  # 680 ms (4x4-Doku); die 8x8 akzeptiert mindestens diesen Bereich

    def __init__(self, ctx: Ctx):
        super().__init__()
        self.ctx = ctx
        lay = QHBoxLayout(self)
        self.faders = []
        for i in range(8):
            f = Fader(f"Out{i + 1}", 0, self.MAX, lambda v: T.delay_label(v, self.ctx.dev.delay_unit), height=260)
            f.slider.setSingleStep(1)
            f.slider.setPageStep(48)
            f.changed.connect(lambda v, ch=8 + i: (setattr(self.ctx.chan(ch), "delay", v), self.ctx.send(C.delay(ch, v))))
            self.faders.append(f)
            lay.addWidget(f)
        unit = QGroupBox("Unit")
        ul = QVBoxLayout(unit)
        self.unit_group = QButtonGroup(self)
        for k, name in enumerate(T.DELAY_UNITS):
            rb = QRadioButton(name)
            self.unit_group.addButton(rb, k)
            ul.addWidget(rb)
        self.unit_group.idClicked.connect(self._unit)
        lay.addWidget(unit)
        ctx.refreshed.connect(self.refresh)

    def _unit(self, k):
        self.ctx.dev.delay_unit = k
        self.ctx.send(C.delay_unit(k))
        self.refresh()

    def refresh(self):
        self.unit_group.button(self.ctx.dev.delay_unit or 0).setChecked(True)
        for i, f in enumerate(self.faders):
            f.title.setText(self.ctx.dev.outputs[i].name)
            f.set_value(self.ctx.dev.outputs[i].delay)


# ---------------------------------------------------------------------------------------
class MatrixPage(QWidget):
    def __init__(self, ctx: Ctx):
        super().__init__()
        self.ctx = ctx
        g = QGridLayout(self)
        self.btn = {}
        for o in range(8):
            head = QLabel(f"Out{o + 1}", alignment=Qt.AlignCenter)
            head.setStyleSheet("font-weight:bold")
            g.addWidget(head, 0, o)
            for i in range(9):
                b = Toggle("Aux" if i == 8 else f"In{i + 1}", color="#69f0ae")
                b.toggled_by_user.connect(lambda _v, o=o: self._send(o))
                g.addWidget(b, 1 + i, o)
                self.btn[(o, i)] = b
        g.setRowStretch(10, 1)
        ctx.refreshed.connect(self.refresh)

    def _send(self, o):
        mask = sum(1 << i for i in range(9) if self.btn[(o, i)].isChecked())
        self.ctx.dev.outputs[o].route = mask
        self.ctx.send(C.routing(8 + o, mask))

    def refresh(self):
        for (o, i), b in self.btn.items():
            b.set_state(bool(self.ctx.dev.outputs[o].route >> i & 1))
            if i < 8:
                b.setText(self.ctx.dev.inputs[i].name)


# ---------------------------------------------------------------------------------------
class PeqEditor(QWidget):
    """Frequenzgang + PEQ-Tabelle + Band-Fader + HPF/LPF (Eingänge, Ausgänge, Aux)."""

    def __init__(self, ctx: Ctx, ch: int, with_filters: bool = True):
        super().__init__()
        self.ctx, self.ch, self.with_filters = ctx, ch, with_filters
        self.nb = 7 if 8 <= ch < 16 else 5
        v = QVBoxLayout(self)
        v.setContentsMargins(0, 0, 0, 0)
        head = QHBoxLayout()
        self.mag, self.pha = QRadioButton("Mag"), QRadioButton("Phase")
        self.mag.setChecked(True)
        self.pha.toggled.connect(lambda on: (setattr(self.curve, "show_phase", on), self.curve.update()))
        self.eq_bypass = Toggle("EQ Bypass", color="#ffb74d")
        self.eq_bypass.toggled_by_user.connect(self._eq_bypass)
        reset = QPushButton("EQ Reset")
        reset.clicked.connect(self._reset)
        for w in (self.mag, self.pha):
            head.addWidget(w)
        head.addStretch()
        head.addWidget(self.eq_bypass)
        head.addWidget(reset)
        v.addLayout(head)
        self.curve = EqCurve()
        self.curve.band_moved.connect(self._moved)
        self.curve.band_selected.connect(self._select)
        v.addWidget(self.curve, 1)
        bottom = QHBoxLayout()
        v.addLayout(bottom)
        # Tabelle
        tab = QGroupBox("PEQ")
        tg = QGridLayout(tab)
        for col, t in enumerate(("", "Frequency", "Q", "Gain", "Type", "Bypass")):
            tg.addWidget(QLabel(t, alignment=Qt.AlignCenter), 0, col)
        self.rows = []
        for b in range(self.nb):
            sel = QPushButton(str(b + 1))
            sel.setFixedWidth(30)
            sel.clicked.connect(lambda _c, b=b: self._select(b))
            freq = QComboBox()
            freq.addItems([T.fmt_hz(T.idx_to_hz(i)) for i in range(301)])
            q = QComboBox()
            q.addItems([f"{T.q_from_idx(i):.2f}" for i in range(101)])
            gain = QComboBox()
            gain.addItems([f"{T.peq_gain_to_db(i):+.1f}dB" for i in range(241)])
            typ = QComboBox()
            typ.addItems(T.PEQ_TYPES)
            byp = Toggle("Bypass", color="#ffb74d")
            for w in (freq, q, gain, typ):
                w.activated.connect(lambda _i, b=b: self._from_row(b))
            byp.toggled_by_user.connect(lambda _v, b=b: self._from_row(b))
            for col, w in enumerate((sel, freq, q, gain, typ, byp)):
                tg.addWidget(w, 1 + b, col)
            self.rows.append((sel, freq, q, gain, typ, byp))
        bottom.addWidget(tab, 3)
        # Band-Fader
        par = QGroupBox("PEQ Parameter")
        pl = QHBoxLayout(par)
        self.f_freq = Fader("Freq", 0, 300, lambda i: T.fmt_hz(T.idx_to_hz(i)), height=140)
        self.f_q = Fader("Q", 0, 100, lambda i: f"{T.q_from_idx(i):.2f}", height=140)
        self.f_gain = Fader("Gain", 0, 240, lambda i: f"{T.peq_gain_to_db(i):+.1f}dB", height=140)
        for f in (self.f_freq, self.f_q, self.f_gain):
            f.changed.connect(self._from_faders)
            pl.addWidget(f)
        bottom.addWidget(par, 2)
        self.sel = 0
        # Filter
        self.filters = {}
        if with_filters:
            for kind, title in (("hpf", "HighPass"), ("lpf", "LowPass")):
                box = QGroupBox(title)
                bl = QVBoxLayout(box)
                fad = Fader("", 0, 300, lambda i: T.fmt_hz(T.idx_to_hz(i)), height=120)
                slope = QComboBox()
                slope.addItems(T.SLOPES[1:])
                byp = Toggle("Bypass", color="#e5484d")
                fad.changed.connect(lambda _v, k=kind: self._send_filter(k))
                slope.activated.connect(lambda _i, k=kind: self._send_filter(k))
                byp.toggled_by_user.connect(lambda _v, k=kind: self._send_filter(k))
                bl.addWidget(fad)
                bl.addWidget(QLabel("Slope", alignment=Qt.AlignCenter))
                bl.addWidget(slope)
                bl.addWidget(byp)
                bottom.addWidget(box)
                self.filters[kind] = (fad, slope, byp)

    def chan(self):
        return self.ctx.chan(self.ch)

    # --- PEQ ---------------------------------------------------------------------
    def _send_band(self, b):
        band = self.chan().peq[b]
        self.ctx.send(C.peq(self.ch, b, band.gain, band.freq, band.q, band.type, band.bypass), key=("peq", self.ch, b))
        self.refresh()

    def _from_row(self, b):
        sel, freq, q, gain, typ, byp = self.rows[b]
        band = self.chan().peq[b]
        band.freq, band.q, band.gain = freq.currentIndex(), q.currentIndex(), gain.currentIndex()
        band.type, band.bypass = typ.currentIndex(), byp.isChecked()
        self._send_band(b)

    def _from_faders(self, _=None):
        band = self.chan().peq[self.sel]
        band.freq, band.q, band.gain = self.f_freq.value(), self.f_q.value(), self.f_gain.value()
        self._send_band(self.sel)

    def _moved(self, b, freq, gain):
        self.sel = b
        self._send_band(b)

    def _select(self, b):
        self.sel = b
        self.curve.selected = b
        self.refresh()

    def _eq_bypass(self, on):
        self.chan().eq_bypass = on
        self.ctx.send(C.eq_bypass(self.ch, on))
        self.refresh()

    def _reset(self):
        defaults = [49, 101, 148, 200, 255] if self.nb == 5 else [31, 71, 118, 161, 200, 240, 270]
        for b, band in enumerate(self.chan().peq):
            band.gain, band.q, band.type, band.bypass, band.freq = 120, 25, 0, False, defaults[b]
            self.ctx.send(C.peq(self.ch, b, band.gain, band.freq, band.q, band.type, band.bypass))
        self.refresh()

    # --- Filter ------------------------------------------------------------------
    def _send_filter(self, kind):
        fad, slope, byp = self.filters[kind]
        flt: Filter = getattr(self.chan(), kind)
        flt.freq = fad.value()
        chosen = slope.currentIndex() + 1
        self.ctx.remembered_slope[(self.ch, kind)] = chosen
        flt.slope = 0 if byp.isChecked() else chosen
        mk = C.hpf if kind == "hpf" else C.lpf
        self.ctx.send(mk(self.ch, flt.freq, flt.slope))
        self.refresh()

    def refresh(self):
        c = self.chan()
        self.curve.set_data(c.peq, getattr(c, "hpf", None) if self.with_filters else None,
                            getattr(c, "lpf", None) if self.with_filters else None, c.eq_bypass)
        self.eq_bypass.set_state(c.eq_bypass)
        for b, (sel, freq, q, gain, typ, byp) in enumerate(self.rows):
            band = c.peq[b]
            for w, val in ((freq, band.freq), (q, band.q), (gain, band.gain), (typ, band.type)):
                w.blockSignals(True)
                w.setCurrentIndex(val)
                w.blockSignals(False)
            byp.set_state(band.bypass)
            sel.setStyleSheet("background:#3fcf5f;color:#000" if b == self.sel else "")
        band = c.peq[self.sel]
        self.f_freq.set_value(band.freq)
        self.f_q.set_value(band.q)
        self.f_gain.set_value(band.gain)
        for kind, (fad, slope, byp) in self.filters.items():
            flt = getattr(c, kind)
            fad.set_value(flt.freq)
            remembered = self.ctx.remembered_slope.get((self.ch, kind), DEFAULT_SLOPE)
            slope.setCurrentIndex((flt.slope or remembered) - 1)
            byp.set_state(flt.slope == 0)


class ChannelPage(QWidget):
    """In- bzw. Out-Seite eines Kanals."""

    def __init__(self, ctx: Ctx, ch: int):
        super().__init__()
        self.ctx, self.ch = ctx, ch
        lay = QHBoxLayout(self)
        self.peq = PeqEditor(ctx, ch)
        lay.addWidget(self.peq, 1)
        side = QVBoxLayout()
        lay.addLayout(side)
        if ch < 8:
            fb = QGroupBox("FBQ")
            fl = QVBoxLayout(fb)
            self.fbq = QButtonGroup(self)
            for k, name in enumerate(T.FBQ_LEVELS):
                b = QPushButton(name)
                b.setCheckable(True)
                b.setStyleSheet("QPushButton:checked{background:#3fcf5f;color:#000}")
                self.fbq.addButton(b, k)
                fl.addWidget(b)
            self.fbq.idClicked.connect(self._fbq)
            side.addWidget(fb)
        gb = QGroupBox("Gain")
        gl = QVBoxLayout(gb)
        row = QHBoxLayout()
        self.gain = fader_gain(ctx, ch, "")
        self.meter = LevelMeter()
        row.addWidget(self.gain)
        row.addWidget(self.meter)
        gl.addLayout(row)
        self.phase = phase_button(ctx, ch)
        self.mute = mute_button(ctx, ch)
        gl.addWidget(self.phase)
        gl.addWidget(self.mute)
        if ch < 8:
            self.mode = Toggle("Line", "Mic", "#90caf9")
            self.sens = QSpinBox()
            self.sens.setRange(0, 2)
            self.sens.setSuffix(" dB")
            self.p48 = Toggle("+48V", "+48V", "#ffb74d")
            self.mode.toggled_by_user.connect(lambda _v: self._mode())
            self.sens.valueChanged.connect(lambda _v: self.mode.isChecked() and self._mode())
            self.p48.toggled_by_user.connect(self._48v)
            for w in (self.mode, self.sens, self.p48):
                gl.addWidget(w)
        side.addWidget(gb)
        side.addStretch()
        ctx.refreshed.connect(self.refresh)

    def _fbq(self, k):
        self.ctx.dev.inputs[self.ch].fbq = k
        self.ctx.send(C.fbq(self.ch, k))

    def _mode(self):
        m = (1 + self.sens.value()) if self.mode.isChecked() else 0
        for c in self.ctx.linked(self.ch):
            self.ctx.dev.inputs[c].mode = m
            self.ctx.send(C.input_mode(c, m), key=("mode", c))
        self.ctx.refreshed.emit()

    def _48v(self, v):
        for c in self.ctx.linked(self.ch):
            self.ctx.dev.inputs[c].phantom = v
            self.ctx.send(C.phantom(c, v), key=("48v", c))
        self.ctx.refreshed.emit()

    def refresh(self):
        c = self.ctx.chan(self.ch)
        self.peq.refresh()
        self.gain.set_value(c.gain)
        self.gain.title.setText(c.name)
        self.phase.set_state(c.phase)
        self.mute.set_state(c.mute)
        if self.ch < 8:
            if c.fbq is not None:
                self.fbq.button(c.fbq).setChecked(True)
            self.mode.set_state(None if c.mode is None else c.mode > 0)
            self.sens.blockSignals(True)
            self.sens.setValue(max(0, (c.mode or 1) - 1))
            self.sens.setEnabled(bool(c.mode))
            self.sens.blockSignals(False)
            self.p48.set_state(c.phantom)
            self.p48.setEnabled(bool(c.mode))


# ---------------------------------------------------------------------------------------
class MixDialog(QDialog):
    """AutoMix Setting / Camera Setting – live wie im Editor (Threshold, Attack, Release, Select)."""

    def __init__(self, ctx: Ctx, attr: str, ch: int, title: str, parent=None):
        super().__init__(parent)
        self.ctx, self.attr, self.ch = ctx, attr, ch
        self.setWindowTitle(title)
        m = getattr(ctx.dev.aux, attr)
        v = QVBoxLayout(self)
        if attr == "automix":
            row = QHBoxLayout()
            row.addWidget(QLabel("Slope"))
            self.slope = QComboBox()
            self.slope.addItems(T.AUTOMIX_SLOPES)
            self.slope.setCurrentIndex(ctx.dev.aux.automix_slope or 0)
            self.slope.activated.connect(self._slope)
            row.addWidget(self.slope)
            row.addStretch()
            v.addLayout(row)
        h = QHBoxLayout()
        self.thr = Fader("Threshold", 0, 180, lambda r: f"{T.comp_thr_to_db(r):+.1f}dB", m.threshold)
        self.att = Fader("Attack", 0, 998, lambda r: f"{T.ms_from_raw(r)}ms", m.attack)
        self.rel = Fader("Release", 0, 2999, lambda r: f"{T.ms_from_raw(r)}ms", m.release)
        for f in (self.thr, self.att, self.rel):
            f.changed.connect(self._send)
            h.addWidget(f)
        sel = QGroupBox("Select")
        sl = QVBoxLayout(sel)
        self.sel = []
        for i in range(8):
            b = Toggle(ctx.dev.inputs[i].name, color="#69f0ae")
            b.set_state(bool(m.select >> i & 1))
            b.toggled_by_user.connect(self._send)
            sl.addWidget(b)
            self.sel.append(b)
        h.addWidget(sel)
        v.addLayout(h)
        close = QPushButton("Exit")
        close.clicked.connect(self.accept)
        v.addWidget(close)

    def _slope(self, i):
        self.ctx.dev.aux.automix_slope = i
        self.ctx.send(C.automix_slope(i))

    def _send(self, *_):
        m = getattr(self.ctx.dev.aux, self.attr)
        m.threshold, m.attack, m.release = self.thr.value(), self.att.value(), self.rel.value()
        m.select = sum(1 << i for i, b in enumerate(self.sel) if b.isChecked())
        self.ctx.send(C.aux_mix(self.ch, m.attack, m.release, m.threshold, m.select, m.on), key=("mix", self.ch))


class EffectDialog(QDialog):
    """Effect Setting – Echo, Hall und Gain (Befehle 0x62, 0x60, 0x63)."""

    FIELDS = [("echo", "Echo", 0, 100, "{}"), ("echo_repeat", "Repeat", 0, 100, "{}"),
              ("echo_delay", "Delay", 0, 1000, "{}ms"), ("echo_predelay", "PreDelay", 0, 200, "{}ms"),
              ("reverb", "Reverb", 0, 100, "{}"), ("reflect", "Reflect", 0, 100, "{}"),
              ("time", "Time", 0, 255, None), ("rev_predelay", "Predelay", 0, 200, "{}ms")]

    def __init__(self, ctx: Ctx, parent=None):
        super().__init__(parent)
        self.ctx = ctx
        self.setWindowTitle("Effect Setting")
        ef = ctx.dev.aux.effect
        g = QGridLayout(self)
        self.faders = {}
        for k, (attr, title, lo, hi, fmt) in enumerate(self.FIELDS):
            f = Fader(title, lo, hi, (lambda r: f"{T.reverb_time_s(r):.2f}s") if fmt is None
                      else (lambda r, fmt=fmt: fmt.format(r)), getattr(ef, attr), height=110)
            f.changed.connect(lambda _v, echo=k < 4: self._send(echo))
            g.addWidget(f, k // 4, k % 4)
            self.faders[attr] = f
        self.gain = Fader("Gain", 0, 100, str, ef.gain, height=260)
        self.gain.changed.connect(self._gain)
        g.addWidget(self.gain, 0, 4, 2, 1)
        close = QPushButton("Exit")
        close.clicked.connect(self.accept)
        g.addWidget(close, 2, 0, 1, 5)

    def _send(self, echo: bool):
        ef = self.ctx.dev.aux.effect
        for attr, f in self.faders.items():
            setattr(ef, attr, f.value())
        if echo:
            self.ctx.send(C.echo(ef.echo_predelay, ef.echo_delay, ef.echo_repeat, ef.echo), key=("echo",))
        else:
            self.ctx.send(C.reverb(ef.rev_predelay, ef.reflect, ef.time, ef.reverb), key=("reverb",))

    def _gain(self, v):
        ef = self.ctx.dev.aux.effect
        ef.gain = v
        self.ctx.send(C.effect(bool(ef.on), v), key=("effect",))


class AuxPage(QWidget):
    def __init__(self, ctx: Ctx):
        super().__init__()
        self.ctx = ctx
        lay = QHBoxLayout(self)
        self.peq = PeqEditor(ctx, AUX, with_filters=False)
        lay.addWidget(self.peq, 1)
        side = QGridLayout()
        lay.addLayout(side)
        self.comp = CompWidget(ctx, AUX, "Comp")
        side.addWidget(self.comp, 0, 0, 3, 1)
        # AutoMix / Effect / Camera
        self.automix_on = Toggle("Off", "On", "#3fcf5f")
        self.effect_on = Toggle("Off", "On", "#3fcf5f")
        self.camera_on = Toggle("Off", "On", "#3fcf5f")
        for row, (title, tog, setter) in enumerate((("AutoMix", self.automix_on, self._automix_set),
                                                   ("Effect", self.effect_on, self._effect_set),
                                                   ("Camera", self.camera_on, self._camera_set))):
            box = QGroupBox(title)
            bl = QVBoxLayout(box)
            bl.addWidget(tog)
            sb = QPushButton("Set")
            sb.clicked.connect(setter)
            bl.addWidget(sb)
            side.addWidget(box, row, 1)
        self.automix_on.toggled_by_user.connect(lambda v: self._mix("automix", C.AUX, v))
        self.camera_on.toggled_by_user.connect(lambda v: self._mix("camera", C.CAMERA, v))
        self.effect_on.toggled_by_user.connect(self._effect)
        # Select
        sel = QGroupBox("Select")
        sl = QVBoxLayout(sel)
        self.sel_btn = []
        for i in range(8):
            b = Toggle(f"In{i + 1}", color="#69f0ae")
            b.toggled_by_user.connect(lambda _v: self._select())
            sl.addWidget(b)
            self.sel_btn.append(b)
        side.addWidget(sel, 0, 2, 3, 1)
        # Gain
        gb = QGroupBox("Gain")
        gl = QVBoxLayout(gb)
        row = QHBoxLayout()
        self.gain = fader_gain(ctx, AUX, "Aux")
        self.meter = LevelMeter()
        row.addWidget(self.gain)
        row.addWidget(self.meter)
        gl.addLayout(row)
        self.mute = Toggle("Mute", color=MUTE_RED)
        self.mute.toggled_by_user.connect(lambda v: (setattr(ctx.dev.aux, "mute", v), ctx.send(C.mute(AUX, v))))
        gl.addWidget(self.mute)
        side.addWidget(gb, 0, 3, 3, 1)
        ctx.refreshed.connect(self.refresh)

    def _mix(self, attr, ch, on):
        m = getattr(self.ctx.dev.aux, attr)
        m.on = on
        self.ctx.send(C.aux_mix(ch, m.attack, m.release, m.threshold, m.select, m.on), key=("mix", ch))

    def _automix_set(self):
        MixDialog(self.ctx, "automix", C.AUX, "AutoMix Setting", self).exec()

    def _camera_set(self):
        MixDialog(self.ctx, "camera", C.CAMERA, "Camera Setting", self).exec()

    def _effect(self, on):
        ef = self.ctx.dev.aux.effect
        ef.on = on
        self.ctx.send(C.effect(on, ef.gain), key=("effect",))

    def _effect_set(self):
        EffectDialog(self.ctx, self).exec()

    def _select(self):
        mask = sum(1 << i for i, b in enumerate(self.sel_btn) if b.isChecked())
        self.ctx.dev.aux.select = mask
        self.ctx.send(C.routing(AUX, mask))

    def refresh(self):
        a = self.ctx.dev.aux
        self.peq.refresh()
        self.comp.refresh()
        self.gain.set_value(a.gain)
        self.mute.set_state(a.mute)
        self.automix_on.set_state(a.automix.on)
        self.camera_on.set_state(a.camera.on)
        self.effect_on.set_state(a.effect.on)
        for i, b in enumerate(self.sel_btn):
            b.set_state(bool(a.select >> i & 1))
            b.setText(self.ctx.dev.inputs[i].name)


def scrollable(w: QWidget) -> QScrollArea:
    s = QScrollArea()
    s.setWidget(w)
    s.setWidgetResizable(True)
    s.setFrameShape(QFrame.NoFrame)
    return s
