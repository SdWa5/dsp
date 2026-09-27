"""Befehlsbausteine für alle Editor-Funktionen der 8x8 Matrix.

Kanalindizes: In1..In8 = 0..7, Out1..Out8 = 8..15, Aux = 0x12 (✓), Kamera/AutoMix-Zweig 0x13 (✓).
✓ = am 8x8 per Editor-Mitschnitt bestätigt, ≈ = aus der 4x4-Doku, am 8x8 nicht mitgeschnitten.
"""

from __future__ import annotations

from .protocol import Frame, request

AUX = 0x12
CAMERA = 0x13


def u16(v: int) -> bytes:
    return int(v).to_bytes(2, "little")


def gain(ch: int, raw: int) -> Frame:                      # ✓ 34
    return request(0x34, bytes([ch]) + u16(raw))


def mute(ch: int, on: bool) -> Frame:                       # ✓ 35
    return request(0x35, bytes([ch, int(on)]))


def phase(ch: int, inverted: bool) -> Frame:                # ✓ 36
    return request(0x36, bytes([ch, int(inverted)]))


def routing(ch: int, mask: int) -> Frame:                   # ✓ 3A (Ausgang 8..15 oder AUX = Quellenwahl)
    return request(0x3A, bytes([ch]) + u16(mask))


def hpf(ch: int, freq_idx: int, slope: int) -> Frame:        # ✓ 32, slope 0 = Bypass
    return request(0x32, bytes([ch]) + u16(freq_idx) + bytes([slope]))


def lpf(ch: int, freq_idx: int, slope: int) -> Frame:        # ✓ 31
    return request(0x31, bytes([ch]) + u16(freq_idx) + bytes([slope]))


def peq(ch: int, band: int, gain_raw: int, freq_idx: int, q_idx: int, ptype: int, bypass: bool) -> Frame:  # ✓ 33
    return request(0x33, bytes([ch, band]) + u16(gain_raw) + u16(freq_idx) + bytes([q_idx, ptype, int(bypass)]))


def eq_bypass(ch: int, on: bool) -> Frame:                  # ✓ 3C (ganzer Kanal-EQ)
    return request(0x3C, bytes([ch, int(on)]))


def gate(ch: int, attack: int, release: int, threshold: int) -> Frame:   # ✓ 3E (8x8: ohne Hold)
    return request(0x3E, bytes([ch]) + u16(attack) + u16(release) + u16(threshold))


def comp(ch: int, ratio: int, knee: int, attack: int, release: int, threshold: int) -> Frame:  # ✓ 30
    return request(0x30, bytes([ch, ratio, knee]) + u16(attack) + u16(release) + u16(threshold))


def delay(ch: int, samples: int) -> Frame:                  # ✓ 38
    return request(0x38, bytes([ch]) + u16(samples))


def delay_unit(unit: int) -> Frame:                         # ✓ 15 (0 ms, 1 m, 2 ft)
    return request(0x15, bytes([unit]))


def link(ch: int, flags: int) -> Frame:                     # ✓ 3B (Master = OR der Kanäle, Slave = 0)
    return request(0x3B, bytes([ch, flags]))


def copy_channel(src: int, dst: int) -> Frame:              # ✓ 2A: Gerät kopiert alle Parameter src -> dst
    return request(0x2A, bytes([src, dst]))


def channel_name(ch: int, name: str) -> Frame:              # ✓ 3D (8 Zeichen, mit 0 aufgefüllt)
    raw = name.encode("ascii", "replace")[:8].ljust(8, b"\x00")
    return request(0x3D, bytes([ch]) + raw)


def input_mode(ch: int, mode: int) -> Frame:                # ✓ 55: 0 Line, 1..3 Mic 0/+1/+2 dB
    return request(0x55, bytes([ch, mode]))


def phantom(ch: int, on: bool) -> Frame:                    # ✓ 56 (+48V)
    return request(0x56, bytes([ch, int(on)]))


def fbq(ch: int, level: int) -> Frame:                      # ✓ 61: 0 aus, 1..4
    return request(0x61, bytes([ch, level]))


def aux_mix(ch: int, attack: int, release: int, threshold: int, select: int, on: bool) -> Frame:
    """✓ 64: AutoMix (ch = AUX) bzw. Kamera (ch = CAMERA). attack/release: ms-1, threshold: dB = raw/2-90."""
    return request(0x64, bytes([ch]) + u16(attack) + u16(release) + u16(threshold) + u16(select) + u16(int(on)))


def automix_slope(idx: int) -> Frame:                       # ✓ 5A: 0 = -3 dB … 3 = -6 dB
    return request(0x5A, bytes([idx]))


def effect(on: bool, gain: int) -> Frame:                   # ✓ 63: Effekt ein/aus + Effekt-Gain
    return request(0x63, bytes([int(on), gain]))


def echo(predelay: int, delay: int, repeat: int, level: int) -> Frame:     # ✓ 62
    return request(0x62, u16(predelay) + u16(delay) + u16(repeat) + u16(level))


def reverb(predelay: int, reflect: int, time: int, level: int) -> Frame:   # ✓ 60
    return request(0x60, u16(predelay) + u16(reflect) + u16(time) + u16(level))


def sub_control(io: int, gain_pct: int, muted: bool, mask: int, extra: int = 0) -> Frame:  # ✓ 5B (0 = In, 1 = Out)
    return request(0x5B, bytes([io, gain_pct, int(muted), mask & 0xFF, extra]))


def group(idx: int, gain_pct: int, muted: bool, mask: int) -> Frame:  # ✓ 6B (Gruppe, Gain %, Mute, Kanalmaske)
    return request(0x6B, bytes([idx, gain_pct, int(muted), mask & 0xFF]))


def test_tone(mode: int, freq_idx: int = 0) -> Frame:       # ✓ 39 (Mitschnitt: 39 02 00 = White Noise)
    return request(0x39, bytes([mode, freq_idx]))


def working_status(mode: int) -> Frame:                     # ✓ 6A (0 = Memorize)
    return request(0x6A, bytes([mode]))


def device_id(dev_id: int) -> Frame:                        # ✓ BB
    return request(0xBB, bytes([dev_id]))


def load_preset(slot: int) -> Frame:                        # ≈ 20 (Editor-Nummer: 0 = F00, 1 = U01 …)
    return request(0x20, bytes([slot]))


def store_preset(slot: int) -> Frame:                       # ≈ 21 (nie 0 = F00)
    if slot < 1:
        raise ValueError("F00 (Werkseinstellung) kann nicht überschrieben werden")
    return request(0x21, bytes([slot]))


def preset_name(name: str) -> Frame:                        # ≈ 26 (14 Zeichen, vor 0x21 senden)
    return request(0x26, name.encode("ascii", "replace")[:14].ljust(14, b" "))


def unlock(pin: str) -> Frame:                              # ≈ 2D
    return request(0x2D, b"\x00" + pin.encode("ascii")[:4].ljust(4, b"0"))


def set_pin(pin: str) -> Frame:                             # ≈ 2F – sperrt sofort!
    return request(0x2F, pin.encode("ascii")[:4].ljust(4, b"0"))


def activate() -> Frame:                                    # ✓ 12 (Editor sendet es nach Link-Änderungen)
    return request(0x12)
