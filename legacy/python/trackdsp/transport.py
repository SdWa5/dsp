"""Transportschichten: USB-HID (hidapi/hidraw) und TCP (Port 9761).

Beide liefern und nehmen ganze Rahmen (protocol.Frame). Das Rahmenformat ist auf beiden
Wegen identisch; USB verpackt jeden Rahmen in einen 64-Byte-Report, TCP ist ein Bytestrom.
"""

from __future__ import annotations

import socket
import time
from abc import ABC, abstractmethod

from . import protocol as p

DEFAULT_TCP_PORT = 9761


class Transport(ABC):
    @abstractmethod
    def send(self, frame: p.Frame) -> None: ...

    @abstractmethod
    def recv(self, timeout: float) -> p.Frame | None:
        """Nächsten gültigen Rahmen lesen, None bei Timeout."""

    def close(self) -> None:
        pass

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()


class HidTransport(Transport):
    """USB-HID. Unter Linux über hidraw – siehe udev/99-trackdsp.rules für Zugriffsrechte."""

    def __init__(self, path: bytes | None = None):
        from .device import TrackDSP
        self._dev = TrackDSP(path).open()

    def send(self, frame: p.Frame) -> None:
        raw = frame.encode()
        if len(raw) > 64:
            raise ValueError(f"Rahmen zu lang für einen HID-Report ({len(raw)} Byte)")
        self._dev.write(raw)

    def recv(self, timeout: float) -> p.Frame | None:
        end = time.monotonic() + timeout
        while True:
            left = end - time.monotonic()
            if left <= 0:
                return None
            data = self._dev.read(timeout_ms=max(1, int(left * 1000)))
            if data is None:
                return None
            # Ohne Report-IDs liefert hidapi die Nutzdaten ohne führendes Report-ID-Byte
            frame = p.decode(data) or p.decode(data[1:])
            if frame is not None:
                return frame

    def close(self) -> None:
        self._dev.close()


class TcpTransport(Transport):
    """TCP-Verbindung zum Netzwerkanschluss des Geräts (Standard-Port 9761)."""

    def __init__(self, host: str, port: int = DEFAULT_TCP_PORT, connect_timeout: float = 3.0):
        self._sock = socket.create_connection((host, port), timeout=connect_timeout)
        self._buf = bytearray()

    def send(self, frame: p.Frame) -> None:
        self._sock.sendall(frame.encode())

    def recv(self, timeout: float) -> p.Frame | None:
        end = time.monotonic() + timeout
        while True:
            frame = self._take_frame()
            if frame is not None:
                return frame
            left = end - time.monotonic()
            if left <= 0:
                return None
            self._sock.settimeout(left)
            try:
                chunk = self._sock.recv(4096)
            except socket.timeout:
                return None
            if not chunk:
                raise ConnectionError("Verbindung vom Gerät geschlossen")
            self._buf += chunk

    def _take_frame(self) -> p.Frame | None:
        """Einen vollständigen Rahmen aus dem Puffer lösen; Müll davor verwerfen."""
        while True:
            start = self._buf.find(p.STX)
            if start < 0:
                del self._buf[:-1]  # evtl. halbes 0x10 am Ende behalten
                return None
            del self._buf[:start]
            if len(self._buf) < 5:
                return None
            total = 5 + self._buf[4] + 3
            if len(self._buf) < total:
                return None
            frame = p.decode(bytes(self._buf[:total]))
            if frame is None:
                del self._buf[:2]  # kein gültiger Rahmen an dieser Stelle, weitersuchen
                continue
            del self._buf[:total]
            return frame

    def close(self) -> None:
        self._sock.close()


def open_transport(target: str | None = None) -> Transport:
    """'usb' / None -> USB, sonst 'host' oder 'host:port' -> TCP."""
    if target in (None, "", "usb"):
        return HidTransport()
    host, _, port = target.partition(":")
    return TcpTransport(host, int(port) if port else DEFAULT_TCP_PORT)
