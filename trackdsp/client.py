"""High-Level-Client für die t.racks 8x8 Matrix – unabhängig vom Transport (USB oder TCP).

    from trackdsp import Matrix
    with Matrix.connect("192.168.68.10") as dsp:     # oder Matrix.connect("usb")
        print(dsp.info())
        dsp.mute("out1", True)
"""

from __future__ import annotations

import ipaddress
import re
import time

from . import protocol as p
from .transport import Transport, open_transport

N_CONFIG_PAGES = 26
# Mute-Bitmasken (Bit 0 = Kanal 1): Seite 0x18, Byte 14-15 Eingänge, Byte 18-19 Ausgänge
# ✓ Eingänge: In1+In2 gemutet -> 0x0003; ✓ Ausgänge: Out7 gemutet -> 0x0080 wurde 0x00C0
MUTE_PAGE, MUTE_IN_OFFSET, MUTE_OUT_OFFSET = 0x18, 14, 18
N_PRESET_SLOTS = 21  # Namensplätze 0x00-0x13 = U01-U20, 0x14 = F00 ("Default Preset")


def preset_label(slot: int) -> str:
    """Namensplatz -> Anzeige wie im Editor (0 -> U01, 19 -> U20, 20 -> F00)."""
    return "F00" if slot == 20 else f"U{slot + 1:02d}"


class DspError(RuntimeError):
    pass


def channel(name: str | int) -> int:
    """'in1'..'in8', 'out1'..'out8' (oder Kanalindex 0..15) -> Kanalindex."""
    if isinstance(name, int):
        if not 0 <= name <= 15:
            raise ValueError(f"Kanalindex außerhalb 0..15: {name}")
        return name
    m = re.fullmatch(r"(in|out)([1-8])", name.strip().lower())
    if not m:
        raise ValueError(f"Unbekannter Kanal {name!r} (erwartet in1..in8 oder out1..out8)")
    n = int(m.group(2))
    return p.input_channel(n) if m.group(1) == "in" else p.output_channel(n)


def channel_name(index: int) -> str:
    return f"in{index + 1}" if index < 8 else f"out{index - 7}"


class Matrix:
    def __init__(self, transport: Transport, timeout: float = 1.0, retries: int = 2):
        # Wiederholen ist unkritisch: alle Schreibbefehle setzen absolute Werte
        self.t = transport
        self.timeout = timeout
        self.retries = retries

    @classmethod
    def connect(cls, target: str | None = None, **kw) -> "Matrix":
        dsp = cls(open_transport(target), **kw)
        dsp.handshake()
        return dsp

    def handshake(self) -> None:
        """Sitzung beginnen (0x10). Ohne Init beantwortet das Gerät über TCP keine Befehle (✓)."""
        self.request(p.CMD_INIT, reinit=False)

    def close(self) -> None:
        self.t.close()

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    # --- Grundfunktion ------------------------------------------------------------

    def request(self, cmd: int, data: bytes = b"", *, reply: int | None = None,
                match: bytes = b"", reinit: bool = True) -> p.Frame:
        """Befehl senden und passende Antwort abwarten.

        reply: erwarteter Antwort-Befehl (Standard: derselbe cmd); match: Präfix der Antwortdaten.
        Fremde Rahmen (z. B. Antworten an einen parallel laufenden Editor) werden übersprungen.
        """
        reply = cmd if reply is None else reply
        for attempt in range(self.retries + 1):
            if attempt and reinit:
                # Ausbleibende Antwort: evtl. ist die Sitzung abgelaufen – neu initialisieren
                self._send_init_quietly()
            self.t.send(p.request(cmd, data))
            # Feste Frist pro Versuch – fremde Rahmen dürfen das Warten nicht verlängern
            deadline = time.monotonic() + self.timeout
            while (left := deadline - time.monotonic()) > 0:
                f = self.t.recv(left)
                if f is None:
                    break
                if f.src == p.ADDR_DSP and f.cmd == reply and f.data.startswith(match):
                    return f
        raise DspError(f"Keine Antwort auf Befehl {cmd:02X}")

    def _send_init_quietly(self) -> None:
        self.t.send(p.request(p.CMD_INIT))
        deadline = time.monotonic() + self.timeout
        while (left := deadline - time.monotonic()) > 0:
            f = self.t.recv(left)
            if f is None or (f.src == p.ADDR_DSP and f.cmd == p.CMD_INIT):
                return

    def write(self, frame: p.Frame) -> None:
        """Schreibbefehl senden und auf ACK warten."""
        self.request(frame.cmd, frame.data, reply=p.CMD_ACK)

    # --- Lesen --------------------------------------------------------------------

    def info(self) -> str:
        return self.request(p.CMD_DEVICE_INFO).data.decode("ascii", "replace").strip("\x00 ")

    def ip_address(self) -> str:
        """IP des Netzwerkanschlusses. Nur per USB – über TCP beantwortet das Gerät 0xB0 nicht."""
        return str(ipaddress.IPv4Address(self.request(p.CMD_IP_ADDRESS).data[:4]))

    def active_preset(self) -> int:
        """Aktives Preset als Editor-Nummer (2 = U02, ✓ am Gerät). Namensplatz = Nummer - 1."""
        return self.request(p.CMD_ACTIVE_PRESET).data[0]

    def preset_name(self, slot: int) -> str:
        f = self.request(p.CMD_PRESET_NAME, bytes([slot]), match=bytes([slot]))
        return f.data[1:].decode("ascii", "replace").rstrip(" \x00")

    def preset_names(self) -> list[str]:
        return [self.preset_name(i) for i in range(N_PRESET_SLOTS)]

    def config_page(self, page: int) -> bytes:
        f = self.request(p.CMD_READ_PAGE, bytes([page]), reply=p.CMD_CONFIG_PAGE, match=bytes([page]))
        return f.data[1:]

    def config(self) -> bytes:
        """Komplette aktuelle Konfiguration (26 Seiten, Layout teilweise unbekannt)."""
        return b"".join(self.config_page(i) for i in range(N_CONFIG_PAGES))

    def mute_state(self, config: bytes | None = None) -> dict[str, bool]:
        """Stumm-Zustand aller Kanäle aus der Konfiguration (Seite 0x18, ✓ am Gerät)."""
        cfg = config if config is not None else self.config_page(MUTE_PAGE)
        base = MUTE_PAGE * p.CONFIG_PAGE_SIZE if config is not None else 0
        ins = int.from_bytes(cfg[base + MUTE_IN_OFFSET:base + MUTE_IN_OFFSET + 2], "little")
        outs = int.from_bytes(cfg[base + MUTE_OUT_OFFSET:base + MUTE_OUT_OFFSET + 2], "little")
        state = {f"in{i + 1}": bool(ins >> i & 1) for i in range(8)}
        state.update({f"out{i + 1}": bool(outs >> i & 1) for i in range(8)})
        return state

    def groups_raw(self) -> bytes:
        """Gruppensteuerung (0x6C 00, ✓): 8 × (Gain %, Mute, Kanalmaske); 0-3 Eingänge, 4-7 Ausgänge."""
        return self.request(p.CMD_GROUPS, b"\x00").data

    def meters_raw(self) -> bytes:
        """Pegelanzeigen als Rohdaten (Format noch nicht entschlüsselt)."""
        return self.request(p.CMD_METERS).data

    # --- Schreiben (alle bestätigt per Mitschnitt, siehe PROTOCOL.md) ------------------

    def set_gain(self, ch: str | int, db: float) -> None:
        self.write(p.set_gain_db(channel(ch), db))

    def mute(self, ch: str | int, on: bool = True) -> None:
        self.write(p.set_mute(channel(ch), on))

    def phase(self, ch: str | int, inverted: bool) -> None:
        self.write(p.set_phase(channel(ch), inverted))

    def route(self, output: int, inputs: list[int]) -> None:
        """Ausgang 1..8 bekommt genau die Eingänge 1..8 aus `inputs` (leer = keiner)."""
        mask = 0
        for i in inputs:
            p.input_channel(i)  # Bereichsprüfung
            mask |= 1 << (i - 1)
        self.write(p.set_routing(output, mask))

    def peq(self, ch: str | int, band: int, gain_db: float, freq_hz: float, q_index: int,
            peq_type: int = p.PEQ_PEAK, bypass: bool = False) -> None:
        self.write(p.set_peq(channel(ch), band, gain_db, freq_hz, q_index, peq_type, bypass))

    def hpf(self, ch: str | int, freq_hz: float, bypass: bool = False) -> None:
        self.write(p.set_hpf(channel(ch), freq_hz, 0x12 if bypass else 0))

    def lpf(self, ch: str | int, freq_hz: float, bypass: bool = False) -> None:
        self.write(p.set_lpf(channel(ch), freq_hz, 0x13 if bypass else 0))
