"""Rahmenformat und bekannte Befehle des t.racks 8x8 Matrix (Musicrown-Protokoll).

Abgeleitet aus USB-Mitschnitt captures/dsp_session1.pcap (siehe PROTOCOL.md).

Rahmen:  10 02 | src | dst | len | cmd data... | 10 03 | xor
    src/dst  0x00 = PC, 0x01 = DSP
    len      Anzahl Bytes von cmd + data
    xor      len ^ cmd ^ data[0] ^ ... ^ data[n]
Kein Byte-Stuffing beobachtet (0x10 im Nutzdatenbereich wird nicht verdoppelt).
Der Rahmen steht am Anfang eines 64-Byte-HID-Reports, der Rest ist Pufferrest.
"""

from __future__ import annotations

from dataclasses import dataclass

STX = b"\x10\x02"
ETX = b"\x10\x03"
ADDR_PC = 0x00
ADDR_DSP = 0x01

# --- Befehle ---------------------------------------------------------------------
# ✓ = am 8x8 per Mitschnitt bestätigt, ≈ = aus der DSP-4x4-Mini-Doku übernommen
#     (github.com/IMBArator/miniDSP-Linux), am 8x8 noch zu prüfen, ? = unbekannt
CMD_ACK = 0x01               # ✓ DSP-Antwort "OK" auf Schreibbefehle
CMD_INIT = 0x10              # ✓ Handshake-Beginn, Antwort 1 Byte (0x15)
CMD_DISCONNECT = 0x11        # ? vor dem Handshake gesendet
CMD_ACTIVATE = 0x12          # ≈ nach dem Laden der Konfiguration, Antwort ACK
CMD_DEVICE_INFO = 0x13       # ✓ Antwort ASCII, z. B. "8x8Matrix V010"
CMD_ACTIVE_PRESET = 0x14     # ≈ Index des aktiven Presets (Antwort 0x02 = U02 ✓ passt)
CMD_LOAD_PRESET = 0x20       # ≈ Preset laden
CMD_STORE_PRESET = 0x21      # ≈ Preset speichern
CMD_PRESET_HEADER = 0x22     # ≈ Preset-Header / Belegung
CMD_CONFIG_PAGE = 0x24       # ✓ Antwort auf 0x27: Seite n + 50 Byte
CMD_STORE_NAME = 0x26        # ≈ Preset-Name speichern
CMD_READ_PAGE = 0x27         # ✓ Konfigurationsseite n lesen (8x8: 0x00-0x19)
CMD_PRESET_NAME = 0x29       # ✓ Name von Preset-Platz n lesen (0x00-0x14)
CMD_DEVICE_STATUS = 0x2C     # ≈ Geräteinfo, Byte 6 = Lock-Flag
CMD_COMPRESSOR = 0x30        # ≈ Kompressor
CMD_SET_LPF = 0x31           # ✓ Kanal, Freq-Index uint16 LE, Flag (Bypass: 0x13)
CMD_SET_HPF = 0x32           # ✓ Kanal, Freq-Index uint16 LE, Flag (Bypass: 0x12)
CMD_SET_PEQ = 0x33           # ✓ Kanal, Band, Gain u16, Freq-Index u16, Q-Index, Typ, Bypass
CMD_SET_GAIN = 0x34          # ✓ Kanal, Rohwert uint16 LE (siehe gain_raw_to_db)
CMD_SET_MUTE = 0x35          # ✓ Kanal, 1 = stumm / 0 = an
CMD_SET_PHASE = 0x36         # ✓ Kanal, 1 = invertiert / 0 = normal
CMD_SET_DELAY = 0x38         # ≈ Delay
CMD_TEST_TONE = 0x39         # ≈ Modus (0 aus, 1 Pink, 2 White, 3 Sinus), Frequenz-Index
CMD_SET_ROUTING = 0x3A       # ✓ Ausgang, Eingangs-Bitmaske uint16 LE
CMD_SET_LINK = 0x3B          # ≈ Kanalkopplung
CMD_PEQ_BYPASS = 0x3C        # ≈ beim 4x4; am 8x8 steckt der PEQ-Bypass im letzten Byte von 0x33
CMD_CHANNEL_NAME = 0x3D      # ≈ Kanalname
CMD_SET_GATE = 0x3E          # ≈ Noise Gate
CMD_METERS = 0x40            # ✓ Pegelanzeigen abfragen (Editor pollt alle ~300 ms)
CMD_GROUPS = 0x6C            # ✓ Gruppensteuerung lesen: 8 × (Gain %, Mute, Maske)
CMD_UNKNOWN_6C = CMD_GROUPS
CMD_IP_ADDRESS = 0xB0        # ✓ Antwort 4 Byte IPv4 (beim 4x4 nicht vorhanden)

# PEQ-Typen (✓ aus Editor-Auswahl): 0 = Peak, 1 = Low Shelf, 2 = High Shelf
PEQ_PEAK, PEQ_LOW_SHELF, PEQ_HIGH_SHELF = 0, 1, 2
# Testton-Modi (≈)
TONE_OFF, TONE_PINK, TONE_WHITE, TONE_SINE = 0, 1, 2, 3

# Kanalindizes (aus Routing-Befehl abgeleitet): In1..In8 = 0..7, Out1..Out8 = 8..15
def input_channel(n: int) -> int:
    """Eingang 1..8 -> Kanalindex."""
    if not 1 <= n <= 8:
        raise ValueError("Eingang muss 1..8 sein")
    return n - 1


def output_channel(n: int) -> int:
    """Ausgang 1..8 -> Kanalindex."""
    if not 1 <= n <= 8:
        raise ValueError("Ausgang muss 1..8 sein")
    return 7 + n


@dataclass(frozen=True)
class Frame:
    src: int
    dst: int
    cmd: int
    data: bytes = b""

    def encode(self) -> bytes:
        body = bytes([self.cmd]) + self.data
        ln = len(body)
        if ln > 255:
            raise ValueError("Nutzdaten zu lang")
        return STX + bytes([self.src, self.dst, ln]) + body + ETX + bytes([checksum(ln, body)])


def checksum(length: int, body: bytes) -> int:
    x = length
    for b in body:
        x ^= b
    return x


def decode(report: bytes) -> Frame | None:
    """Rahmen am Anfang eines HID-Reports parsen. None, wenn kein gültiger Rahmen."""
    if len(report) < 8 or report[:2] != STX:
        return None
    src, dst, ln = report[2], report[3], report[4]
    body = report[5:5 + ln]
    tail = report[5 + ln:5 + ln + 3]
    if len(body) != ln or tail[:2] != ETX or tail[2] != checksum(ln, body):
        return None
    return Frame(src, dst, body[0], body[1:])


def request(cmd: int, data: bytes = b"") -> Frame:
    """Rahmen PC -> DSP."""
    return Frame(ADDR_PC, ADDR_DSP, cmd, data)


# --- Umrechnungen -----------------------------------------------------------------

CONFIG_PAGE_SIZE = 50  # Nutzbytes je Konfigurationsseite (letzte Seite 0x19: 27 Byte)
GAIN_RAW_MAX = 400  # +12 dB


def gain_raw_to_db(raw: int) -> float:
    """Kanal-Gain: 0..79 in 0,5-dB-Schritten ab -60 dB, ab 80 in 0,1-dB-Schritten ab -20 dB.

    ✓ am 8x8: 20 = -50, 40 = -40, 80 = -20, 275 = -0,5, 280 = 0, 400 = +12 dB.
    """
    if not 0 <= raw <= GAIN_RAW_MAX:
        raise ValueError(f"Gain-Rohwert außerhalb 0..{GAIN_RAW_MAX}: {raw}")
    return round(raw / 2 - 60, 1) if raw < 80 else round((raw - 80) / 10 - 20, 1)


def db_to_gain_raw(db: float) -> int:
    """Umkehrung von gain_raw_to_db, auf die nächste Stufe gerundet."""
    if not -60 <= db <= 12:
        raise ValueError(f"Gain außerhalb -60..+12 dB: {db}")
    return round((db + 60) * 2) if db < -20 else round((db + 20) * 10) + 80


FREQ_MIN, FREQ_MAX, FREQ_STEPS = 19.7, 20160.0, 300


def freq_index_to_hz(index: int) -> float:
    """Frequenzindex 0..300 (1/30 Oktave) -> Hz: 19,6875 · 2^(i/30). ✓ 31 = 40,3 Hz, 300 = 20,16 kHz."""
    from .tables import idx_to_hz
    return idx_to_hz(index)


def hz_to_freq_index(hz: float) -> int:
    from .tables import hz_to_idx
    return hz_to_idx(hz)


def peq_gain_to_raw(db: float) -> int:
    """PEQ-Gain ±12 dB in 0,1-dB-Schritten, 120 = 0 dB (✓)."""
    if not -12 <= db <= 12:
        raise ValueError(f"PEQ-Gain außerhalb ±12 dB: {db}")
    return round(db * 10) + 120


# --- Bequeme Konstruktoren --------------------------------------------------------

def set_gain(channel: int, raw_value: int) -> Frame:
    """Gain als Rohwert setzen (0..400, siehe gain_raw_to_db)."""
    if not 0 <= raw_value <= GAIN_RAW_MAX:
        raise ValueError(f"Gain-Rohwert außerhalb 0..{GAIN_RAW_MAX}: {raw_value}")
    return request(CMD_SET_GAIN, bytes([channel]) + raw_value.to_bytes(2, "little"))


def set_gain_db(channel: int, db: float) -> Frame:
    return set_gain(channel, db_to_gain_raw(db))


def set_mute(channel: int, on: bool) -> Frame:
    return request(CMD_SET_MUTE, bytes([channel, 1 if on else 0]))


def set_phase(channel: int, inverted: bool) -> Frame:
    return request(CMD_SET_PHASE, bytes([channel, 1 if inverted else 0]))


def set_peq(channel: int, band: int, gain_db: float, freq_hz: float, q_index: int,
            peq_type: int = PEQ_PEAK, bypass: bool = False) -> Frame:
    """Parametrischer EQ. Band 0..6 (Ausgänge), q_index roh (0x19 = Q 1,70 ✓, Tabelle offen)."""
    return request(CMD_SET_PEQ, bytes([channel, band])
                   + peq_gain_to_raw(gain_db).to_bytes(2, "little")
                   + hz_to_freq_index(freq_hz).to_bytes(2, "little")
                   + bytes([q_index, peq_type, 1 if bypass else 0]))


def set_hpf(channel: int, freq_hz: float, flag: int = 0) -> Frame:
    """Hochpass. flag: 0 = aktiv, 0x12 = Bypass (✓). Flanke wird hierüber nicht übertragen."""
    return request(CMD_SET_HPF, bytes([channel]) + hz_to_freq_index(freq_hz).to_bytes(2, "little") + bytes([flag]))


def set_lpf(channel: int, freq_hz: float, flag: int = 0) -> Frame:
    """Tiefpass. flag: 0 = aktiv, 0x13 = Bypass (✓)."""
    return request(CMD_SET_LPF, bytes([channel]) + hz_to_freq_index(freq_hz).to_bytes(2, "little") + bytes([flag]))


def test_tone(mode: int, freq_index: int = 0) -> Frame:
    """Testton (≈). Vorsicht: gibt Signal auf die gerouteten Ausgänge!"""
    return request(CMD_TEST_TONE, bytes([mode, freq_index]))


def load_preset(slot: int) -> Frame:
    """Preset laden (≈, Format am 8x8 ungeprüft). Überschreibt den aktuellen Zustand!"""
    return request(CMD_LOAD_PRESET, bytes([slot]))


def set_routing(output: int, input_mask: int) -> Frame:
    """Matrix: welche Eingänge (Bit 0 = In1 … Bit 7 = In8) auf Ausgang 1..8 liegen."""
    return request(CMD_SET_ROUTING, bytes([output_channel(output)]) + input_mask.to_bytes(2, "little"))
