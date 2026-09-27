"""Bedienelemente: Fader, Umschalter, Pegelanzeige, Frequenzgang-Kurve."""

from __future__ import annotations

import cmath
import math
from typing import Callable

from PySide6.QtCore import QPointF, QRectF, Qt, Signal
from PySide6.QtGui import QColor, QFont, QPainter, QPainterPath, QPen
from PySide6.QtWidgets import (QLabel, QPushButton, QSizePolicy, QSlider, QVBoxLayout, QWidget)

from .. import tables as T

ACCENT = QColor("#3fcf5f")
MUTE_RED = "#e5484d"
CURVE_COLORS = ["#ff5252", "#ffd740", "#e040fb", "#40c4ff", "#69f0ae", "#448aff", "#b0bec5", "#ffab40"]


class Fader(QWidget):
    """Vertikaler Fader mit Überschrift und Wertanzeige. value = Rohwert."""
    changed = Signal(int)

    def __init__(self, title: str, lo: int, hi: int, fmt: Callable[[int], str], value: int = 0,
                 height: int = 170, parent=None):
        super().__init__(parent)
        self.fmt = fmt
        lay = QVBoxLayout(self)
        lay.setContentsMargins(2, 2, 2, 2)
        lay.setSpacing(2)
        self.title = QLabel(title, alignment=Qt.AlignCenter)
        self.title.setStyleSheet("font-weight: bold")
        self.slider = QSlider(Qt.Vertical)
        self.slider.setRange(lo, hi)
        self.slider.setValue(value)
        self.slider.setMinimumHeight(height)
        self.slider.setPageStep(max(1, (hi - lo) // 20))
        self.label = QLabel(fmt(value), alignment=Qt.AlignCenter)
        self.label.setMinimumWidth(70)
        self.label.setStyleSheet("background:#e8fbff;color:#000;border-radius:4px;padding:2px")
        lay.addWidget(self.title)
        lay.addWidget(self.slider, 1, Qt.AlignHCenter)
        lay.addWidget(self.label)
        self.slider.valueChanged.connect(self._on_change)
        self._silent = False

    def _on_change(self, v: int) -> None:
        self.label.setText(self.fmt(v))
        if not self._silent:
            self.changed.emit(v)

    def set_value(self, v: int) -> None:
        """Wert setzen, ohne `changed` auszulösen (Gerätezustand anzeigen)."""
        self._silent = True
        self.slider.setValue(v)
        self.label.setText(self.fmt(v))
        self._silent = False

    def value(self) -> int:
        return self.slider.value()


class Toggle(QPushButton):
    """Umschaltknopf mit farbigem Aktiv-Zustand."""
    toggled_by_user = Signal(bool)

    def __init__(self, text_off: str, text_on: str | None = None, color: str = "#3fcf5f", parent=None):
        super().__init__(text_off, parent)
        self.text_off, self.text_on = text_off, text_on or text_off
        self.setCheckable(True)
        self.setStyleSheet(f"QPushButton:checked {{ background:{color}; color:#000; font-weight:bold }}")
        self.clicked.connect(lambda c: (self._relabel(), self.toggled_by_user.emit(c)))

    def _relabel(self) -> None:
        self.setText(self.text_on if self.isChecked() else self.text_off)

    def set_state(self, on: bool | None) -> None:
        self.blockSignals(True)
        self.setChecked(bool(on))
        self._relabel()
        self.blockSignals(False)
        self.setProperty("unknown", on is None)
        self.setToolTip("Zustand unbekannt (nicht aus dem Gerät lesbar)" if on is None else "")


class LevelMeter(QWidget):
    """Pegelanzeige -60 … +12 dB."""

    def __init__(self, parent=None):
        super().__init__(parent)
        self.db = -90.0
        self.setFixedWidth(14)
        self.setMinimumHeight(80)
        self.setSizePolicy(QSizePolicy.Fixed, QSizePolicy.Expanding)

    def set_db(self, db: float) -> None:
        self.db = db
        self.update()

    def paintEvent(self, _):
        p = QPainter(self)
        r = self.rect()
        p.fillRect(r, QColor("#1a1a1a"))
        frac = max(0.0, min(1.0, (self.db + 60) / 72))
        h = int(r.height() * frac)
        grad_top = r.height() - h
        for y in range(grad_top, r.height(), 3):
            level = 12 - (y / r.height()) * 72
            c = "#e53935" if level > 6 else "#fdd835" if level > 0 else "#43a047"
            p.fillRect(1, y, r.width() - 2, 2, QColor(c))


# --- Frequenzgang --------------------------------------------------------------------
FS = 96000.0  # Abtastrate laut Datenblatt


def _biquad(ptype: int, f0: float, gain_db: float, q: float):
    """RBJ-Cookbook-Koeffizienten (b0,b1,b2,a0,a1,a2)."""
    a = 10 ** (gain_db / 40)
    w0 = 2 * math.pi * f0 / FS
    cw, sw = math.cos(w0), math.sin(w0)
    alpha = sw / (2 * q)
    if ptype == 0:    # Peak
        return 1 + alpha * a, -2 * cw, 1 - alpha * a, 1 + alpha / a, -2 * cw, 1 - alpha / a
    if ptype in (1, 2):  # Shelf
        sq = 2 * math.sqrt(a) * alpha
        if ptype == 1:
            return (a * ((a + 1) - (a - 1) * cw + sq), 2 * a * ((a - 1) - (a + 1) * cw), a * ((a + 1) - (a - 1) * cw - sq),
                    (a + 1) + (a - 1) * cw + sq, -2 * ((a - 1) + (a + 1) * cw), (a + 1) + (a - 1) * cw - sq)
        return (a * ((a + 1) + (a - 1) * cw + sq), -2 * a * ((a - 1) + (a + 1) * cw), a * ((a + 1) + (a - 1) * cw - sq),
                (a + 1) - (a - 1) * cw + sq, 2 * ((a - 1) - (a + 1) * cw), (a + 1) - (a - 1) * cw - sq)
    if ptype == 3:    # Low Pass
        return (1 - cw) / 2, 1 - cw, (1 - cw) / 2, 1 + alpha, -2 * cw, 1 - alpha
    if ptype == 4:    # High Pass
        return (1 + cw) / 2, -(1 + cw), (1 + cw) / 2, 1 + alpha, -2 * cw, 1 - alpha
    if ptype == 5:    # Allpass 1. Ordnung
        k = math.tan(math.pi * f0 / FS)
        return (k - 1), (k + 1), 0.0, (k + 1), (k - 1), 0.0
    return 1 - alpha, -2 * cw, 1 + alpha, 1 + alpha, -2 * cw, 1 - alpha   # Allpass 2. Ordnung


def _biquad_resp(coef, f: float) -> complex:
    b0, b1, b2, a0, a1, a2 = coef
    z = cmath.exp(-1j * 2 * math.pi * f / FS)
    return (b0 + b1 * z + b2 * z * z) / (a0 + a1 * z + a2 * z * z)


def _xover_resp(slope: int, fc: float, f: float, high: bool) -> complex:
    """Näherung der Hoch-/Tiefpass-Flanken (Butterworth / Bessel / Linkwitz-Riley)."""
    if slope <= 0:
        return 1
    name = T.SLOPES[slope]
    order = int(name.split("-")[1]) // 6
    x = (f / fc) if not high else (fc / f)
    if name.startswith("LK"):
        mag = (1 / math.sqrt(1 + x ** order)) ** 2       # LR = zwei Butterworth halber Ordnung
    elif name.startswith("BL"):
        mag = 1 / math.sqrt(1 + (x * 0.8) ** (2 * order))  # Bessel: weicher Knick (Näherung)
    else:
        mag = 1 / math.sqrt(1 + x ** (2 * order))
    return mag


class EqCurve(QWidget):
    """Frequenzgang-Diagramm mit verschiebbaren PEQ-Punkten (wie im Editor)."""
    band_moved = Signal(int, int, int)   # Band, Frequenzindex, Gain-Rohwert
    band_selected = Signal(int)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.bands = []           # PeqBand-Liste
        self.hpf = self.lpf = None
        self.selected = 0
        self.eq_bypass = False
        self._drag = None
        self.setMinimumHeight(260)
        self.show_phase = False

    def set_data(self, bands, hpf, lpf, eq_bypass=False) -> None:
        self.bands, self.hpf, self.lpf, self.eq_bypass = bands, hpf, lpf, bool(eq_bypass)
        self.update()

    # Koordinaten
    def _plot(self) -> QRectF:
        return QRectF(48, 12, self.width() - 60, self.height() - 34)

    def _x(self, f: float) -> float:
        r = self._plot()
        return r.left() + r.width() * math.log(f / 20) / math.log(20000 / 20)

    def _f(self, x: float) -> float:
        r = self._plot()
        return 20 * (20000 / 20) ** ((x - r.left()) / r.width())

    def _y(self, db: float) -> float:
        r = self._plot()
        return r.top() + r.height() * (18 - db) / 36

    def _db(self, y: float) -> float:
        r = self._plot()
        return 18 - (y - r.top()) / r.height() * 36

    def response(self, f: float) -> complex:
        h = complex(1)
        if not self.eq_bypass:
            for b in self.bands:
                if b.bypass:
                    continue
                coef = _biquad(b.type, T.idx_to_hz(b.freq), T.peq_gain_to_db(b.gain), T.q_from_idx(b.q))
                h *= _biquad_resp(coef, f)
        if self.hpf is not None:
            h *= _xover_resp(self.hpf.slope, T.idx_to_hz(self.hpf.freq), f, True)
        if self.lpf is not None:
            h *= _xover_resp(self.lpf.slope, T.idx_to_hz(self.lpf.freq), f, False)
        return h

    def paintEvent(self, _):
        p = QPainter(self)
        p.setRenderHint(QPainter.Antialiasing)
        p.fillRect(self.rect(), QColor("#101010"))
        r = self._plot()
        grid = QPen(QColor("#1f6f1f"))
        p.setPen(grid)
        p.setFont(QFont("Sans", 8))
        for f in (20, 30, 40, 50, 60, 70, 80, 90, 100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 2000,
                  3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000, 20000):
            x = self._x(f)
            p.drawLine(QPointF(x, r.top()), QPointF(x, r.bottom()))
        for db in range(-18, 19, 6):
            y = self._y(db)
            p.drawLine(QPointF(r.left(), y), QPointF(r.right(), y))
            p.setPen(QColor("#cccccc"))
            p.drawText(QRectF(0, y - 8, 44, 16), Qt.AlignRight | Qt.AlignVCenter, f"{db:+d}dB" if db else "0dB")
            p.setPen(grid)
        p.setPen(QColor("#cccccc"))
        for f, t in ((20, "20Hz"), (100, "100Hz"), (1000, "1KHz"), (10000, "10KHz"), (20000, "20KHz")):
            p.drawText(QRectF(self._x(f) - 25, r.bottom() + 2, 50, 16), Qt.AlignCenter, t)
        # Kurve
        path = QPainterPath()
        n = max(200, int(r.width()))
        for i in range(n + 1):
            f = 20 * 1000 ** (i / n)
            h = self.response(f)
            val = math.degrees(cmath.phase(h)) / 10 if self.show_phase else 20 * math.log10(max(abs(h), 1e-6))
            pt = QPointF(self._x(f), self._y(max(-18.5, min(18.5, val))))
            path.moveTo(pt) if i == 0 else path.lineTo(pt)
        p.setPen(QPen(QColor("#ffee58"), 2))
        p.drawPath(path)
        # Bandpunkte
        for i, b in enumerate(self.bands):
            x, y = self._x(T.idx_to_hz(b.freq)), self._y(T.peq_gain_to_db(b.gain))
            p.setBrush(QColor(CURVE_COLORS[i % len(CURVE_COLORS)]))
            p.setPen(QPen(QColor("white"), 2 if i == self.selected else 0))
            p.drawEllipse(QPointF(x, y), 6, 6)
            p.setPen(QColor("white"))
            p.drawText(QRectF(x - 10, y - 24, 20, 14), Qt.AlignCenter, str(i + 1))
        for flt, label in ((self.hpf, "HPF"), (self.lpf, "LPF")):
            if flt is not None:
                x = self._x(T.idx_to_hz(flt.freq))
                p.setBrush(QColor("#ffffff" if label == "HPF" else "#9e9d24"))
                p.drawRect(QRectF(x - 5, self._y(0) - 5, 10, 10))
                p.drawText(QRectF(x - 15, self._y(0) - 24, 30, 14), Qt.AlignCenter, label)
        if self.bands:
            b = self.bands[self.selected]
            p.setPen(QColor("#ffee58"))
            p.drawText(QRectF(r.left() + 6, r.top() + 4, 260, 16), Qt.AlignLeft,
                       f"Band {self.selected + 1}: {T.fmt_hz(T.idx_to_hz(b.freq))}  Q {T.q_from_idx(b.q):.2f}  "
                       f"{T.peq_gain_to_db(b.gain):+.1f}dB")

    def mousePressEvent(self, e):
        for i, b in enumerate(self.bands):
            x, y = self._x(T.idx_to_hz(b.freq)), self._y(T.peq_gain_to_db(b.gain))
            if abs(e.position().x() - x) < 9 and abs(e.position().y() - y) < 9:
                self._drag = i
                self.selected = i
                self.band_selected.emit(i)
                self.update()
                return

    def mouseMoveEvent(self, e):
        if self._drag is None:
            return
        f = max(20.0, min(20000.0, self._f(e.position().x())))
        db = max(-12.0, min(12.0, self._db(e.position().y())))
        b = self.bands[self._drag]
        b.freq, b.gain = T.hz_to_idx(f), T.db_to_peq_gain(round(db, 1))
        self.band_moved.emit(self._drag, b.freq, b.gain)
        self.update()

    def mouseReleaseEvent(self, _):
        self._drag = None
