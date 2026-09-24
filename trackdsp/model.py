"""Gerätezustand der 8x8 Matrix: Konfiguration (1277 Byte) lesen, bearbeiten, als JSON speichern.

Aufbau der Konfiguration (✓ am Gerät gegen die Editor-Anzeige geprüft):
    0     Kopf: FF FF + Preset-Name (14 Zeichen)
    16    8 × Eingang à 62 Byte
    512   Aux, 94 Byte
    606   8 × Ausgang à 76 Byte
    1214  Fußbereich, 63 Byte:
          0 Mute In · 2 Mute Aux · 4 Mute Out · 6+2k Band-Bypass In k · 22 Band-Bypass Aux ·
          24+2k Band-Bypass Out k · 40 Sub In (Gain, Mute, Maske, x) · 44 Sub Out · 48 Testton ·
          52 Delay-Einheit · 56 EQ-Bypass In · 58 EQ-Bypass Out · 60 EQ-Bypass Aux   (alle ✓)

Gruppensteuerung kommt aus Befehl 0x6C. Nicht in der Konfiguration kartiert: +48V (vermutlich Eingang
Byte 8) und Working Status – diese führt das Modell als "zuletzt gesetzt" (None = unbekannt).
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field
from typing import Optional

from . import tables as T

CONFIG_SIZE = 1277
IN_BASE, IN_SIZE = 16, 62
AUX_BASE, AUX_SIZE = 512, 94
OUT_BASE, OUT_SIZE = 606, 76
FOOT_BASE = 1214


def _u16(b: bytes, o: int) -> int:
    return b[o] | b[o + 1] << 8


def _name(b: bytes, o: int, n: int = 8) -> str:
    return b[o:o + n].split(b"\x00")[0].decode("ascii", "replace").rstrip()


@dataclass
class PeqBand:
    gain: int = 120        # Rohwert, 120 = 0 dB
    freq: int = 100        # Frequenzindex 0..300
    q: int = 25            # Q-Index, 25 = 1,70
    type: int = 0          # tables.PEQ_TYPES
    bypass: bool = False   # nicht in der Konfiguration (gemerkt)

    @classmethod
    def parse(cls, b: bytes, o: int) -> "PeqBand":
        return cls(_u16(b, o), _u16(b, o + 2), b[o + 4], b[o + 5])


@dataclass
class Filter:
    freq: int = 0
    slope: int = 0         # 0 = Bypass, 1..20 = tables.SLOPES


@dataclass
class Gate:
    attack: int = 0        # ms = raw + 1
    release: int = 2999
    threshold: int = 0     # dB = raw/2 - 90


@dataclass
class Comp:
    ratio: int = 0
    knee: int = 0
    attack: int = 49
    release: int = 199
    threshold: int = 220   # dB = raw/2 - 90


@dataclass
class Input:
    name: str = ""
    gate: Gate = field(default_factory=Gate)
    peq: list[PeqBand] = field(default_factory=lambda: [PeqBand() for _ in range(5)])
    hpf: Filter = field(default_factory=Filter)
    lpf: Filter = field(default_factory=lambda: Filter(300, 0))
    gain: int = 280
    phase: bool = False
    link: int = 0
    mute: bool = False
    mode: Optional[int] = None      # 0 Line, 1..3 Mic (gemerkt)
    phantom: Optional[bool] = None  # gemerkt
    fbq: Optional[int] = None       # gemerkt
    eq_bypass: Optional[bool] = None

    @classmethod
    def parse(cls, b: bytes, o: int) -> "Input":
        return cls(
            name=_name(b, o),
            gate=Gate(_u16(b, o + 12), _u16(b, o + 14), _u16(b, o + 16)),
            peq=[PeqBand.parse(b, o + 18 + 6 * i) for i in range(5)],
            hpf=Filter(_u16(b, o + 50), b[o + 48]),
            lpf=Filter(_u16(b, o + 54), b[o + 52]),
            gain=_u16(b, o + 56),
            phase=bool(b[o + 58]),
            link=b[o + 60],
            mode=b[o + 9],          # ✓ 0 Line, 1..3 Mic
            fbq=b[o + 10],          # ✓
        )


@dataclass
class Output:
    name: str = ""
    route: int = 0                  # Bit n = Eingang n+1, Bit 8 = Aux (vermutet)
    hpf: Filter = field(default_factory=Filter)
    lpf: Filter = field(default_factory=lambda: Filter(300, 0))
    peq: list[PeqBand] = field(default_factory=lambda: [PeqBand() for _ in range(7)])
    comp: Comp = field(default_factory=Comp)
    gain: int = 280
    phase: bool = False
    delay: int = 0                  # Samples @ 48 kHz
    link: int = 0
    mute: bool = False
    eq_bypass: Optional[bool] = None

    @classmethod
    def parse(cls, b: bytes, o: int) -> "Output":
        return cls(
            name=_name(b, o),
            route=_u16(b, o + 8),
            hpf=Filter(_u16(b, o + 12), b[o + 10]),
            lpf=Filter(_u16(b, o + 16), b[o + 14]),
            peq=[PeqBand.parse(b, o + 18 + 6 * i) for i in range(7)],
            comp=Comp(b[o + 60], b[o + 61], _u16(b, o + 62), _u16(b, o + 64), _u16(b, o + 66)),
            gain=_u16(b, o + 68),
            phase=bool(b[o + 70]),
            delay=_u16(b, o + 72),
            link=b[o + 74],
        )


@dataclass
class MixFunc:
    """AutoMix bzw. Kamera (Befehl 0x64, ✓ gegen Editor-Dialog geprüft)."""
    attack: int = 99       # ms = raw + 1 (99 = 100 ms)
    release: int = 2999    # ms = raw + 1
    threshold: int = 180   # dB = raw/2 - 90 (180 = 0 dB)
    select: int = 0        # Eingangsmaske
    on: bool = False

    @classmethod
    def parse(cls, b: bytes, o: int) -> "MixFunc":
        return cls(_u16(b, o), _u16(b, o + 2), _u16(b, o + 4), _u16(b, o + 6), bool(_u16(b, o + 8)))


@dataclass
class Effect:
    """Echo (0x62) + Hall (0x60) + Gain/Schalter (0x63). ✓ Werte = Editor-Dialog "Effect Setting"."""
    echo_predelay: int = 10    # ms
    echo_delay: int = 200      # ms
    echo_repeat: int = 60
    echo: int = 80
    rev_predelay: int = 50     # ms
    reflect: int = 32
    time: int = 139            # ≙ 1,96 s
    reverb: int = 30
    gain: int = 55
    on: Optional[bool] = None


@dataclass
class Aux:
    name: str = "Aux"
    select: int = 0                 # Quellen-Bitmaske (Befehl 3A 12)
    effect: Effect = field(default_factory=Effect)
    automix: MixFunc = field(default_factory=MixFunc)
    automix_slope: Optional[int] = None
    camera: MixFunc = field(default_factory=MixFunc)
    peq: list[PeqBand] = field(default_factory=lambda: [PeqBand() for _ in range(5)])
    comp: Comp = field(default_factory=Comp)
    gain: int = 280
    mute: Optional[bool] = None
    eq_bypass: Optional[bool] = None

    @classmethod
    def parse(cls, b: bytes, o: int) -> "Aux":
        return cls(
            name=_name(b, o),
            select=_u16(b, o + 8),
            effect=Effect(*[_u16(b, o + 68 + 2 * i) for i in range(8)], gain=b[o + 12], on=bool(b[o + 10])),
            automix_slope=b[o + 16],
            automix=MixFunc.parse(b, o + 18),
            camera=MixFunc.parse(b, o + 28),
            peq=[PeqBand.parse(b, o + 38 + 6 * i) for i in range(5)],
            comp=Comp(b[o + 84], b[o + 85], _u16(b, o + 86), _u16(b, o + 88), _u16(b, o + 90)),
            gain=_u16(b, o + 92),
        )


@dataclass
class Group:
    gain: int = 100                 # %
    mute: bool = False
    mask: int = 0


@dataclass
class Device:
    preset_name: str = ""
    inputs: list[Input] = field(default_factory=lambda: [Input(name=f"In{i + 1}") for i in range(8)])
    outputs: list[Output] = field(default_factory=lambda: [Output(name=f"Out{i + 1}") for i in range(8)])
    aux: Aux = field(default_factory=Aux)
    footer: list[int] = field(default_factory=list)
    # nicht (sicher) aus der Konfiguration lesbar – zuletzt gesetzte Werte:
    sub: list[Group] = field(default_factory=lambda: [Group(), Group()])        # [Eingänge, Ausgänge]
    groups: list[Group] = field(default_factory=lambda: [Group() for _ in range(8)])  # 0-3 In, 4-7 Out
    sub_extra: list[int] = field(default_factory=lambda: [0, 0])
    tone_mode: Optional[int] = None
    tone_freq: int = 17
    delay_unit: int = 0
    working_status: Optional[int] = None
    device_id: int = 1
    ip: str = ""
    active_preset: Optional[int] = None
    preset_names: list[str] = field(default_factory=list)
    raw: bytes = b""

    @classmethod
    def parse(cls, b: bytes) -> "Device":
        if len(b) < CONFIG_SIZE:
            raise ValueError(f"Konfiguration zu kurz: {len(b)} statt {CONFIG_SIZE} Byte")
        d = cls(
            preset_name=b[2:16].decode("ascii", "replace").rstrip(" \x00"),
            inputs=[Input.parse(b, IN_BASE + IN_SIZE * i) for i in range(8)],
            outputs=[Output.parse(b, OUT_BASE + OUT_SIZE * i) for i in range(8)],
            aux=Aux.parse(b, AUX_BASE),
            footer=list(b[FOOT_BASE:CONFIG_SIZE]),
            raw=bytes(b[:CONFIG_SIZE]),
        )
        f = FOOT_BASE
        in_mute, out_mute = _u16(b, f), _u16(b, f + 4)
        in_eqb, out_eqb = _u16(b, f + 56), _u16(b, f + 58)
        for i in range(8):
            d.inputs[i].mute = bool(in_mute >> i & 1)
            d.outputs[i].mute = bool(out_mute >> i & 1)
            d.inputs[i].eq_bypass = bool(in_eqb >> i & 1)
            d.outputs[i].eq_bypass = bool(out_eqb >> i & 1)
            for j, band in enumerate(d.inputs[i].peq):
                band.bypass = bool(_u16(b, f + 6 + 2 * i) >> j & 1)
            for j, band in enumerate(d.outputs[i].peq):
                band.bypass = bool(_u16(b, f + 24 + 2 * i) >> j & 1)
        for j, band in enumerate(d.aux.peq):
            band.bypass = bool(_u16(b, f + 22) >> j & 1)
        d.aux.mute = bool(b[f + 2] & 1)
        d.aux.eq_bypass = bool(b[f + 60] & 1)
        d.sub = [Group(b[f + 40], bool(b[f + 41]), b[f + 42]), Group(b[f + 44], bool(b[f + 45]), b[f + 46])]
        d.sub_extra = [b[f + 43], b[f + 47]]
        d.tone_mode = b[f + 48] if b[f + 48] < 4 else None
        d.delay_unit = b[f + 52] if b[f + 52] < 3 else 0
        return d

    def set_groups(self, raw: bytes) -> None:
        """Antwort auf 0x6C übernehmen (8 × Gain %, Mute, Maske)."""
        self.groups = [Group(raw[3 * i], bool(raw[3 * i + 1]), raw[3 * i + 2]) for i in range(min(8, len(raw) // 3))]

    # --- Datei ------------------------------------------------------------------------
    def to_json(self) -> str:
        data = asdict(self)
        data["raw"] = self.raw.hex()
        return json.dumps({"format": "trackdsp-8x8", "version": 1, "device": data}, indent=1)

    @classmethod
    def from_json(cls, text: str) -> "Device":
        data = json.loads(text)
        if data.get("format") != "trackdsp-8x8":
            raise ValueError("Keine trackdsp-8x8-Datei")
        dd = data["device"]
        raw = bytes.fromhex(dd.get("raw", ""))
        d = cls.parse(raw) if len(raw) >= CONFIG_SIZE else cls()
        # gemerkte Zusatzwerte übernehmen
        for i, inp in enumerate(dd.get("inputs", [])):
            for k in ("mode", "phantom", "fbq", "eq_bypass"):
                setattr(d.inputs[i], k, inp.get(k))
            for j, bd in enumerate(inp.get("peq", [])):
                d.inputs[i].peq[j].bypass = bd.get("bypass", False)
        for i, out in enumerate(dd.get("outputs", [])):
            d.outputs[i].eq_bypass = out.get("eq_bypass")
            for j, bd in enumerate(out.get("peq", [])):
                d.outputs[i].peq[j].bypass = bd.get("bypass", False)
        for k in ("tone_mode", "tone_freq", "delay_unit", "working_status"):
            setattr(d, k, dd.get(k, getattr(d, k)))
        d.sub = [Group(**g) for g in dd.get("sub", [])] or d.sub
        d.groups = [Group(**g) for g in dd.get("groups", [])] or d.groups
        return d

    # --- Alle Einstellungen als Befehle (für "Upload" auf das Gerät) --------------------
    def all_frames(self) -> list:
        from . import commands as C
        fr = []
        for i, c in enumerate(self.inputs):
            fr += [C.channel_name(i, c.name), C.gain(i, c.gain), C.mute(i, c.mute), C.phase(i, c.phase),
                   C.gate(i, c.gate.attack, c.gate.release, c.gate.threshold),
                   C.hpf(i, c.hpf.freq, c.hpf.slope), C.lpf(i, c.lpf.freq, c.lpf.slope)]
            fr += [C.peq(i, j, b.gain, b.freq, b.q, b.type, b.bypass) for j, b in enumerate(c.peq)]
            fr += [C.link(i, c.link)]
            if c.mode is not None:
                fr.append(C.input_mode(i, c.mode))
            if c.fbq is not None:
                fr.append(C.fbq(i, c.fbq))
        for k, c in enumerate(self.outputs):
            ch = 8 + k
            fr += [C.channel_name(ch, c.name), C.routing(ch, c.route), C.gain(ch, c.gain), C.mute(ch, c.mute),
                   C.phase(ch, c.phase), C.delay(ch, c.delay),
                   C.hpf(ch, c.hpf.freq, c.hpf.slope), C.lpf(ch, c.lpf.freq, c.lpf.slope),
                   C.comp(ch, c.comp.ratio, c.comp.knee, c.comp.attack, c.comp.release, c.comp.threshold)]
            fr += [C.peq(ch, j, b.gain, b.freq, b.q, b.type, b.bypass) for j, b in enumerate(c.peq)]
            fr += [C.link(ch, c.link)]
        a = self.aux
        fr += [C.routing(C.AUX, a.select), C.gain(C.AUX, a.gain),
               C.comp(C.AUX, a.comp.ratio, a.comp.knee, a.comp.attack, a.comp.release, a.comp.threshold)]
        fr += [C.peq(C.AUX, j, b.gain, b.freq, b.q, b.type, b.bypass) for j, b in enumerate(a.peq)]
        for ch, m in ((C.AUX, a.automix), (C.CAMERA, a.camera)):
            fr.append(C.aux_mix(ch, m.attack, m.release, m.threshold, m.select, m.on))
        ef = a.effect
        fr += [C.echo(ef.echo_predelay, ef.echo_delay, ef.echo_repeat, ef.echo),
               C.reverb(ef.rev_predelay, ef.reflect, ef.time, ef.reverb)]
        if ef.on is not None:
            fr.append(C.effect(ef.on, ef.gain))
        if a.automix_slope is not None:
            fr.append(C.automix_slope(a.automix_slope))
        if a.mute is not None:
            fr.append(C.mute(C.AUX, a.mute))
        return fr


def channel_label(ch: int) -> str:
    if ch < 8:
        return f"In{ch + 1}"
    if ch < 16:
        return f"Out{ch - 7}"
    return "Aux" if ch == 0x12 else f"#{ch:02X}"
