"""Wertetabellen und Umrechnungen (8x8 bestätigt bzw. aus der 4x4-Doku übernommen)."""

from __future__ import annotations

import math

# Filterflanken (HPF/LPF): Index im Befehl/der Konfiguration, 0 = Bypass (✓ 8x8: 0x12 = BW-48, 0x14 = LK-48)
SLOPES = ["Bypass", "BW -6", "BL -6", "BW -12", "BL -12", "LK -12", "BW -18", "BL -18", "BW -24", "BL -24",
          "LK -24", "BW -30", "BL -30", "BW -36", "BL -36", "LK -36", "BW -42", "BL -42", "BW -48", "BL -48",
          "LK -48"]

PEQ_TYPES = ["Peak", "Low Shelf", "High Shelf", "Low Pass", "High Pass", "Allpass1", "Allpass2"]  # ✓ Editor-Liste

COMP_RATIOS = ["1:1.0", "1:1.1", "1:1.3", "1:1.5", "1:1.7", "1:2.0", "1:2.5", "1:3.0", "1:3.5", "1:4.0",
               "1:5.0", "1:6.0", "1:8.0", "1:10.0", "1:20.0", "Limit"]  # ✓ 0 = 1:1.0, 15 = Limit

TONE_MODES = ["Analog Input", "Pink Noise", "White Noise", "Sine Wave"]  # 0x39 Modus
TONE_FREQS = ["20Hz", "25Hz", "31.5Hz", "40Hz", "50Hz", "63Hz", "80Hz", "100Hz", "125Hz", "160Hz", "200Hz",
              "250Hz", "315Hz", "400Hz", "500Hz", "630Hz", "800Hz", "1kHz", "1.25kHz", "1.6kHz", "2kHz",
              "2.5kHz", "3.15kHz", "4kHz", "5kHz", "6.3kHz", "8kHz", "10kHz", "12.5kHz", "16kHz", "20kHz"]

AUTOMIX_SLOPES = ["-3dB", "-4dB", "-5dB", "-6dB"]  # 0x5A (✓)


def reverb_time_s(raw: int) -> float:
    """Hallzeit – Näherung aus einem Messpunkt (139 ≙ 1,96 s im Editor)."""
    return raw * 1.96 / 139


INPUT_MODES = ["Line", "Mic 0 dB", "Mic +1 dB", "Mic +2 dB"]  # 0x55 Wert (✓ 0 = Line, 1..3 Mic-Stufen)
FBQ_LEVELS = ["Close", "Level 1", "Level 2", "Level 3", "Level 4"]  # 0x61 Wert (✓)
DELAY_UNITS = ["ms", "m", "ft"]  # 0x15 (✓)
WORKING_STATUS = ["Memorize", "Forget"]  # 0x6A (✓ 0 = Memorize)

SAMPLE_RATE = 48000  # Delay-Samples (✓ 64 Samples = 1,333 ms)
SPEED_OF_SOUND = 343.0

# --- Gain ---------------------------------------------------------------------------
GAIN_MAX = 400


def gain_to_db(raw: int) -> float:
    return round(raw / 2 - 60, 1) if raw < 80 else round((raw - 80) / 10 - 20, 1)


def db_to_gain(db: float) -> int:
    db = max(-60.0, min(12.0, db))
    return round((db + 60) * 2) if db < -20 else round((db + 20) * 10) + 80


# --- Frequenz (Index 0..300, 1/30 Oktave) -----------------------------------------------
# f = 19,6875 Hz · 2^(i/30): 0 = 19,7 Hz, 300 = 20160 Hz (= 19,6875 · 1024). Gegen 17 Editor-Anzeigen
# geprüft: 14 von 18 exakt, 4 um 0,1 Hz daneben (601.5/185.1/122.1/76.9 – Editor rundet dort anders).
F_BASE, F_STEPS = 19.6875, 300


def idx_to_hz(i: int) -> float:
    return F_BASE * 2 ** (i / 30)


def hz_to_idx(hz: float) -> int:
    i = round(30 * math.log2(max(hz, F_BASE) / F_BASE))
    return max(0, min(F_STEPS, i))


def fmt_hz(hz: float) -> str:
    """Anzeige wie im Editor: '40.3Hz', '2.00KHz'."""
    return f"{hz / 1000:.2f}KHz" if hz >= 999.95 else f"{hz:.1f}Hz"


# --- PEQ ----------------------------------------------------------------------------
def peq_gain_to_db(raw: int) -> float:
    return round((raw - 120) / 10, 1)


def db_to_peq_gain(db: float) -> int:
    return max(0, min(240, round(db * 10) + 120))


def q_from_idx(i: int) -> float:
    return 0.4 * 320 ** (i / 100)  # ✓ 25 = 1,70, 32 = 2,50


def q_to_idx(q: float) -> int:
    return 0 if q <= 0.4 else max(0, min(100, round(100 * math.log(q / 0.4) / math.log(320))))


# --- Dynamik ------------------------------------------------------------------------
def gate_thr_to_db(raw: int) -> float:
    return raw * 0.5 - 90  # ✓ 0 = -90 dB


def comp_thr_to_db(raw: int) -> float:
    return raw / 2 - 90  # ✓ 180 = 0 dB, 220 = +20 dB


def ms_from_raw(raw: int) -> int:
    return raw + 1  # ✓ 0 = 1 ms, 2999 = 3000 ms, 49 = 50 ms


def delay_to_ms(samples: int) -> float:
    return samples * 1000 / SAMPLE_RATE


def delay_label(samples: int, unit: int = 0) -> str:
    ms = delay_to_ms(samples)
    if unit == 1:
        return f"{ms / 1000 * SPEED_OF_SOUND:.3f} m"
    if unit == 2:
        return f"{ms / 1000 * SPEED_OF_SOUND * 3.2808:.3f} ft"
    return f"{ms:.3f} ms"


# --- Pegelanzeige -------------------------------------------------------------------
def meter_db(hi: int, lo: int) -> float:
    """Näherung für die 8x8-Pegelanzeige: 16-Bit-Wert/256 ≈ dB über dem Grundrauschen, -60 dB Versatz.

    Nicht kalibriert – ruhiger Kanal ≈ 0x05AD (-54 dB), Rauschen mit +12 dB Gain ≈ 0x3524 (-7 dB).
    """
    return (lo | hi << 8) / 256 - 60
