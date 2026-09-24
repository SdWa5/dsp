"""USB-HID-Zugriff auf den t.racks 8x8 DSP (Musicrown "Dsp Process")."""

from __future__ import annotations

import sys

if sys.platform.startswith("linux"):
    # hidraw-Backend: nutzt den Kerneltreiber und die udev-Rechte (/dev/hidrawN).
    # Das Standardmodul "hid" nutzt unter Linux libusb und scheitert ohne Zugriff auf /dev/bus/usb.
    try:
        import hidraw as hid
    except ImportError:  # ältere hidapi-Pakete ohne hidraw-Modul
        import hid
else:
    import hid

VENDOR_ID = 0x0168
PRODUCT_ID = 0x0821
REPORT_SIZE = 64  # Nutzdaten pro Report; unter Windows kommt 1 Byte Report-ID davor


class DeviceNotFound(RuntimeError):
    pass


def find_devices() -> list[dict]:
    """Alle angeschlossenen DSPs mit dieser VID/PID."""
    return hid.enumerate(VENDOR_ID, PRODUCT_ID)


class TrackDSP:
    """Dünne Hülle um hid.device. Nutzung als Context-Manager:

        with TrackDSP() as dsp:
            data = dsp.read(timeout_ms=500)
    """

    def __init__(self, path: bytes | None = None):
        self._path = path
        self._dev: hid.device | None = None

    def open(self) -> "TrackDSP":
        if self._path is None:
            devices = find_devices()
            if not devices:
                raise DeviceNotFound(
                    f"Kein DSP gefunden (VID {VENDOR_ID:04X}, PID {PRODUCT_ID:04X}). "
                    "USB-Kabel und Stromversorgung prüfen."
                )
            self._path = devices[0]["path"]
        self._dev = hid.device()
        self._dev.open_path(self._path)
        return self

    def close(self) -> None:
        if self._dev is not None:
            self._dev.close()
            self._dev = None

    def __enter__(self) -> "TrackDSP":
        return self.open()

    def __exit__(self, *exc) -> None:
        self.close()

    @property
    def product(self) -> str:
        return self._require().get_product_string() or ""

    @property
    def manufacturer(self) -> str:
        return self._require().get_manufacturer_string() or ""

    def read(self, timeout_ms: int = 1000) -> bytes | None:
        """Einen Input-Report lesen. None bei Timeout."""
        data = self._require().read(REPORT_SIZE + 1, timeout_ms)
        return bytes(data) if data else None

    def write(self, payload: bytes, report_id: int = 0) -> int:
        """Einen Output-Report senden (auf 64 Byte aufgefüllt).

        Achtung: Solange das Protokoll unbekannt ist, nichts Unbekanntes senden –
        falsche Befehle könnten Presets oder Einstellungen verändern.
        """
        if len(payload) > REPORT_SIZE:
            raise ValueError(f"Payload zu lang ({len(payload)} > {REPORT_SIZE})")
        report = bytes([report_id]) + payload.ljust(REPORT_SIZE, b"\x00")
        return self._require().write(report)

    def _require(self) -> hid.device:
        if self._dev is None:
            raise RuntimeError("Gerät nicht geöffnet – open() aufrufen")
        return self._dev


def hexdump(data: bytes) -> str:
    """Kompakte Hex-Darstellung, 16 Byte pro Zeile."""
    lines = []
    for off in range(0, len(data), 16):
        chunk = data[off:off + 16]
        hexpart = " ".join(f"{b:02X}" for b in chunk)
        asciipart = "".join(chr(b) if 32 <= b < 127 else "." for b in chunk)
        lines.append(f"{off:04X}  {hexpart:<47}  {asciipart}")
    return "\n".join(lines)
