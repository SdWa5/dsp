"""Tests ohne Gerät: Protokoll-Umrechnungen, TCP-Rahmenparser, Client gegen simulierten DSP."""

import socket
import threading

import pytest

from trackdsp import Matrix, channel, channel_name
from trackdsp import protocol as p
from trackdsp.transport import TcpTransport, Transport


# --- Protokoll ---------------------------------------------------------------------

def test_gain_scale():
    assert p.db_to_gain_raw(0) == 280
    assert p.db_to_gain_raw(-3) == 250
    assert p.db_to_gain_raw(12) == 400
    assert p.gain_raw_to_db(185) == -9.5
    assert p.gain_raw_to_db(20) == -50
    assert p.gain_raw_to_db(0) == -60
    assert all(p.db_to_gain_raw(p.gain_raw_to_db(r)) == r for r in range(401))
    with pytest.raises(ValueError):
        p.db_to_gain_raw(12.5)


def test_freq_index():
    assert p.hz_to_freq_index(19.7) == 0
    assert p.hz_to_freq_index(40.3) == 31
    assert p.hz_to_freq_index(20160) == 300
    assert round(p.freq_index_to_hz(290)) == 16001


# Rahmen, die der Original-Editor gesendet hat (aus den USB-Mitschnitten)
EDITOR_FRAMES = {
    "10 02 00 01 03 35 00 01 10 03 37": lambda: p.set_mute(0, True),
    "10 02 00 01 03 36 08 01 10 03 3c": lambda: p.set_phase(8, True),
    "10 02 00 01 04 34 08 18 01 10 03 21": lambda: p.set_gain_db(8, 0),
    "10 02 00 01 04 3a 0a 0f 00 10 03 3b": lambda: p.set_routing(3, 0x0F),
    "10 02 00 01 0a 33 08 00 6e 00 1f 00 19 00 00 10 03 59": lambda: p.set_peq(8, 0, -1.0, 40.3, 0x19),
    "10 02 00 01 05 32 08 00 00 12 10 03 2d": lambda: p.set_hpf(8, 19.7, 0x12),
    "10 02 00 01 01 40 10 03 41": lambda: p.request(p.CMD_METERS),
}


@pytest.mark.parametrize("expected", EDITOR_FRAMES)
def test_frames_match_editor(expected):
    frame = EDITOR_FRAMES[expected]()
    assert frame.encode().hex(" ") == expected
    assert p.decode(frame.encode()) == frame


def test_decode_rejects_bad_checksum():
    raw = bytearray(p.set_mute(0, True).encode())
    raw[-1] ^= 0xFF
    assert p.decode(bytes(raw)) is None


def test_channels():
    assert channel("in1") == 0 and channel("IN8") == 7
    assert channel("out1") == 8 and channel("out8") == 15
    assert channel_name(10) == "out3"
    with pytest.raises(ValueError):
        channel("out9")


# --- TCP-Rahmenparser --------------------------------------------------------------

def test_tcp_stream_split_and_garbage():
    server, client = socket.socketpair()
    t = TcpTransport.__new__(TcpTransport)
    t._sock, t._buf = client, bytearray()
    reply = p.Frame(p.ADDR_DSP, p.ADDR_PC, p.CMD_DEVICE_INFO, b"8x8Matrix V010").encode()
    ack = p.Frame(p.ADDR_DSP, p.ADDR_PC, p.CMD_ACK).encode()
    # Müll davor, Rahmen in Stücken, zwei Rahmen in einem Paket
    server.sendall(b"\x00\x10\xff" + reply[:5])
    server.sendall(reply[5:] + ack)
    assert t.recv(1).data == b"8x8Matrix V010"
    assert t.recv(1).cmd == p.CMD_ACK
    assert t.recv(0.1) is None
    server.close()
    client.close()


# --- Client gegen simulierten DSP ---------------------------------------------------

class FakeDsp(Transport):
    """Antwortet wie die echte 8x8; streut fremde Pegel-Antworten ein (wie bei parallelem Editor)."""

    def __init__(self):
        self.sent: list[p.Frame] = []
        self.queue: list[p.Frame] = []

    def _reply(self, cmd, data=b""):
        self.queue.append(p.Frame(p.ADDR_DSP, p.ADDR_PC, cmd, data))

    def send(self, frame):
        self.sent.append(frame)
        self._reply(p.CMD_METERS, bytes(56))  # Störrahmen
        if frame.cmd == p.CMD_DEVICE_INFO:
            self._reply(frame.cmd, b"8x8Matrix V010")
        elif frame.cmd == p.CMD_IP_ADDRESS:
            self._reply(frame.cmd, bytes([192, 168, 68, 10]))
        elif frame.cmd == p.CMD_ACTIVE_PRESET:
            self._reply(frame.cmd, b"\x02")
        elif frame.cmd == p.CMD_PRESET_NAME:
            self._reply(frame.cmd, frame.data[:1] + b"Bulgaria26    ")
        elif frame.cmd == p.CMD_READ_PAGE:
            self._reply(p.CMD_CONFIG_PAGE, frame.data[:1] + bytes([frame.data[0]]) * 50)
        elif frame.cmd in (p.CMD_SET_GAIN, p.CMD_SET_MUTE, p.CMD_SET_PHASE, p.CMD_SET_ROUTING):
            self._reply(p.CMD_ACK)

    def recv(self, timeout):
        return self.queue.pop(0) if self.queue else None


def test_client_reads():
    dsp = Matrix(FakeDsp(), timeout=0.01)
    assert dsp.info() == "8x8Matrix V010"
    assert dsp.ip_address() == "192.168.68.10"
    assert dsp.active_preset() == 2
    assert dsp.preset_name(1) == "Bulgaria26"
    cfg = dsp.config()
    assert len(cfg) == 26 * 50 and cfg[50] == 1


def test_client_writes():
    fake = FakeDsp()
    dsp = Matrix(fake, timeout=0.01)
    dsp.set_gain("in1", -6)
    dsp.mute("out1")
    dsp.route(3, [1, 2])
    assert [f.encode().hex(" ") for f in fake.sent] == [
        p.set_gain(0, 220).encode().hex(" "),
        p.set_mute(8, True).encode().hex(" "),
        p.set_routing(3, 0b11).encode().hex(" "),
    ]


def test_client_timeout():
    class Silent(FakeDsp):
        def send(self, frame):
            self.sent.append(frame)

    silent = Silent()
    with pytest.raises(Exception, match="Keine Antwort"):
        Matrix(silent, timeout=0.01, retries=1).info()
    assert [f.cmd for f in silent.sent] == [p.CMD_DEVICE_INFO, p.CMD_INIT, p.CMD_DEVICE_INFO]  # Retry mit Re-Init


def test_client_timeout_despite_foreign_traffic():
    """Dauerhafte fremde Rahmen (z. B. Editor-Pegelabfragen) dürfen nicht endlos blockieren."""
    class Chatty(FakeDsp):
        def send(self, frame):
            self.sent.append(frame)

        def recv(self, timeout):
            return p.Frame(p.ADDR_DSP, p.ADDR_PC, p.CMD_METERS, bytes(56))

    with pytest.raises(Exception, match="Keine Antwort"):
        Matrix(Chatty(), timeout=0.05, retries=0).info()


def test_mute_state_from_config():
    cfg = bytearray(26 * 50 - 23)
    base = 0x18 * 50
    cfg[base + 14:base + 16] = (0x0003).to_bytes(2, "little")  # In1, In2 (wie am Gerät beobachtet)
    cfg[base + 18:base + 20] = (0x00C0).to_bytes(2, "little")  # Out7, Out8
    state = Matrix(FakeDsp()).mute_state(bytes(cfg))
    assert [c for c, on in state.items() if on] == ["in1", "in2", "out7", "out8"]
