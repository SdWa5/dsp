"""Gerätekommunikation für die Oberfläche: eigener Thread, Befehlswarteschlange, Pegelabfrage."""

from __future__ import annotations

import queue
import threading
import time

from PySide6.QtCore import QObject, Signal

from .. import commands as C
from .. import protocol as p
from ..client import DspError, Matrix
from ..model import Device


class Controller(QObject):
    connected = Signal(str)          # Geräte-Info
    disconnected = Signal(str)       # Grund
    state_loaded = Signal(object)    # Device
    meters = Signal(bytes)           # Rohdaten der Pegelanzeige
    error = Signal(str)
    busy = Signal(str)               # Statusmeldung

    METER_INTERVAL = 0.3

    def __init__(self):
        super().__init__()
        self.dsp: Matrix | None = None
        self.target = ""
        self._q: queue.Queue = queue.Queue()
        self._pending: dict = {}          # coalesce: Schlüssel -> letzter Rahmen
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self.meters_enabled = True

    # --- öffentliche API (GUI-Thread) ---------------------------------------------------
    @property
    def online(self) -> bool:
        return self.dsp is not None

    def connect_to(self, target: str) -> None:
        self.disconnect()
        self.target = target
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, name="trackdsp-io", daemon=True)
        self._thread.start()

    def disconnect(self) -> None:
        if self._thread and self._thread.is_alive():
            self._stop.set()
            self._q.put(None)
            self._thread.join(timeout=5)
        self._thread = None

    def send(self, frame: p.Frame, key=None) -> None:
        """Schreibbefehl einreihen. Gleicher Schlüssel = nur der neueste Wert wird gesendet (Fader)."""
        if not self.online:
            return
        if key is None:
            key = (frame.cmd, frame.data[:1])
        with self._lock:
            fresh = key not in self._pending
            self._pending[key] = frame
        if fresh:
            self._q.put(("write", key))

    def send_many(self, frames) -> None:
        for fr in frames:
            self._q.put(("frame", fr))

    def call(self, fn, done=None) -> None:
        """Beliebige Funktion mit dem Matrix-Objekt im I/O-Thread ausführen."""
        self._q.put(("call", fn, done))

    def reload(self) -> None:
        self._q.put(("reload",))

    # --- I/O-Thread -------------------------------------------------------------------
    def _run(self) -> None:
        try:
            self.busy.emit(f"Verbinde mit {self.target} …")
            self.dsp = Matrix.connect(self.target, timeout=1.0)
            info = self.dsp.info()
            self.connected.emit(info)
            self._load()
        except Exception as e:  # noqa: BLE001
            self.dsp = None
            self.disconnected.emit(f"Verbindung fehlgeschlagen: {e}")
            return
        last_meter = 0.0
        failures = 0
        while not self._stop.is_set():
            try:
                item = self._q.get(timeout=0.05)
            except queue.Empty:
                item = False
            try:
                if item is None:
                    break
                if item:
                    self._handle(item)
                if self.meters_enabled and time.monotonic() - last_meter > self.METER_INTERVAL:
                    last_meter = time.monotonic()
                    self.meters.emit(self.dsp.request(p.CMD_METERS, reinit=True).data)
                failures = 0
            except (DspError, OSError) as e:
                failures += 1
                if failures >= 3:
                    self.disconnected.emit(f"Verbindung verloren: {e}")
                    break
        if self.dsp:
            self.dsp.close()
        self.dsp = None
        self.disconnected.emit("Getrennt")

    def _handle(self, item) -> None:
        kind = item[0]
        if kind == "write":
            with self._lock:
                frame = self._pending.pop(item[1], None)
            if frame is not None:
                self.dsp.write(frame)
        elif kind == "frame":
            self.dsp.write(item[1])
        elif kind == "call":
            result = item[1](self.dsp)
            if item[2]:
                item[2](result)
        elif kind == "reload":
            self._load()

    def _load(self) -> None:
        self.busy.emit("Lese Konfiguration …")
        dev = Device.parse(self.dsp.config())
        try:
            dev.set_groups(self.dsp.groups_raw())
        except DspError:
            pass
        try:
            dev.active_preset = self.dsp.active_preset()
            dev.preset_names = self.dsp.preset_names()
        except DspError:
            pass
        if self.target in ("usb", ""):
            try:
                dev.ip = self.dsp.ip_address()
            except DspError:
                pass
        else:
            dev.ip = self.target.split(":")[0]
        self.state_loaded.emit(dev)
        self.busy.emit("Bereit")


def upload_frames(dev: Device):
    """Alle Einstellungen eines Zustands als Befehlsliste (Datei -> Gerät)."""
    return dev.all_frames()


__all__ = ["Controller", "upload_frames", "C"]
