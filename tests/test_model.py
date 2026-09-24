"""Modell gegen die Editor-Anzeige prüfen (Konfiguration vom 24.09.2026, Preset U02 Bulgaria26)."""

from pathlib import Path

import pytest

from trackdsp import commands as C
from trackdsp import tables as T
from trackdsp.model import Device

CFG = (Path(__file__).parent / "fixture_config.bin").read_bytes()


@pytest.fixture(scope="module")
def dev():
    return Device.parse(CFG)


def test_header_and_names(dev):
    assert dev.preset_name == "Bulgaria26"
    assert [c.name for c in dev.inputs] == [f"In{i}" for i in range(1, 9)]
    assert [c.name for c in dev.outputs] == [f"Out{i}" for i in range(1, 9)]
    assert dev.aux.name == "Aux"


def test_gains(dev):   # Gain-Seite
    assert [T.gain_to_db(c.gain) for c in dev.inputs] == [12, 12, 8, 8, -60, -60, -60, -60]
    assert T.gain_to_db(dev.outputs[0].gain) == 0 and T.gain_to_db(dev.outputs[2].gain) == 5
    assert T.gain_to_db(dev.outputs[3].gain) == 10 and T.gain_to_db(dev.aux.gain) == 0


def test_mutes(dev):
    assert [c.mute for c in dev.inputs] == [True, True] + [False] * 6
    assert [c.mute for c in dev.outputs] == [False] * 7 + [True]


def test_routing(dev):  # Matrix-Seite
    assert dev.outputs[0].route == 0xFC and dev.outputs[2].route == 0x0F and dev.outputs[3].route == 0x03


def test_filters(dev):  # Out3: HPF 20,1 Hz LK-48, LPF 119,4 Hz LK-24
    o3 = dev.outputs[2]
    assert T.fmt_hz(T.idx_to_hz(o3.hpf.freq)) == "20.1Hz" and T.SLOPES[o3.hpf.slope] == "LK -48"
    assert T.fmt_hz(T.idx_to_hz(o3.lpf.freq)) == "119.4Hz" and T.SLOPES[o3.lpf.slope] == "LK -24"
    assert T.SLOPES[dev.outputs[0].hpf.slope] == "Bypass"


# Editor-Anzeigen aus den Screenshots (Index -> Text)
EDITOR_FREQS = {0: "19.7Hz", 1: "20.1Hz", 25: "35.1Hz", 31: "40.3Hz", 41: "50.8Hz", 49: "61.1Hz", 71: "101.5Hz",
                78: "119.4Hz", 101: "203.1Hz", 118: "300.8Hz", 140: "500.0Hz", 161: "812.3Hz", 200: "2.00KHz",
                240: "5.04KHz", 255: "7.13KHz", 270: "10.08KHz", 300: "20.16KHz"}


def test_fmt_hz_like_editor():
    assert {i: T.fmt_hz(T.idx_to_hz(i)) for i in EDITOR_FREQS} == EDITOR_FREQS


def test_peq(dev):  # Out4 Band 2: 122,1 Hz, Q 2,50, -6,2 dB; In1 Band 1: 61,1 Hz
    b = dev.outputs[3].peq[1]
    assert b.freq == 79 and round(T.q_from_idx(b.q), 1) == 2.5   # Editor: 122.1Hz, Formel: 122.2Hz
    assert T.peq_gain_to_db(b.gain) == -6.2 and T.PEQ_TYPES[b.type] == "Peak"
    assert round(T.idx_to_hz(dev.inputs[0].peq[0].freq), 1) == 61.1
    assert round(T.idx_to_hz(dev.aux.peq[0].freq), 1) == 50.8


def test_dynamics(dev):  # Gate/Comp-Seite
    g = dev.inputs[0].gate
    assert (T.ms_from_raw(g.attack), T.ms_from_raw(g.release), T.gate_thr_to_db(g.threshold)) == (1, 3000, -90)
    c1, c3 = dev.outputs[0].comp, dev.outputs[2].comp
    assert T.COMP_RATIOS[c1.ratio] == "Limit" and T.comp_thr_to_db(c1.threshold) == 0
    assert (T.ms_from_raw(c1.attack), T.ms_from_raw(c1.release)) == (1, 3000)
    assert T.COMP_RATIOS[c3.ratio] == "1:1.0" and T.comp_thr_to_db(c3.threshold) == 20
    assert T.comp_thr_to_db(dev.aux.comp.threshold) == 20


def test_delay_phase_link(dev):
    assert T.delay_label(dev.outputs[4].delay) == "1.333 ms" and dev.outputs[0].delay == 0
    assert dev.outputs[0].phase is True     # Mitschnitt: erster Klick sendete 36 08 00
    assert [c.link for c in dev.inputs] == [3, 0, 12, 0, 48, 0, 192, 0]


def test_json_roundtrip():
    dev = Device.parse(CFG)
    dev.inputs[0].phantom = True
    d2 = Device.from_json(dev.to_json())
    assert d2.raw == dev.raw and d2.inputs[0].phantom is True and d2.outputs[3].peq[1] == dev.outputs[3].peq[1]


def test_all_frames_match_editor_format(dev):
    frames = {f.encode().hex(" ") for f in dev.all_frames()}
    # Rahmen, die der Editor in den Tests gesendet hat (Ausgangswerte):
    for f in (C.comp(0x0F, 0, 0, 0x31, 0xC7, 0xDC), C.gate(0, 0, 0xBB7, 0), C.peq(0x12, 0, 0x78, 0x29, 0x19, 0, False),
              C.lpf(0, 0x12C, 0), C.channel_name(0x0F, "Out8")):
        assert f.encode().hex(" ") in frames


def test_groups_and_tone(dev):
    raw = bytes.fromhex("6400c0640003" "64000c640000" "55000c640000" "640000640000")   # Antwort 0x6C
    dev.set_groups(raw)
    assert (dev.groups[0].gain, dev.groups[0].mask) == (100, 0xC0) and dev.groups[1].mask == 0x03
    assert (dev.groups[4].gain, dev.groups[4].mask) == (85, 0x0C)
    assert dev.tone_mode == 2   # White Noise (vom ersten Test des Nutzers)


def test_footer_states(dev):   # Stand 24.09.2026: alles aus, Sub In/Out 100 %, Delay in ms
    assert [c.mode for c in dev.inputs] == [0] * 8 and [c.fbq for c in dev.inputs] == [0] * 8
    assert not any(c.eq_bypass for c in dev.inputs + dev.outputs) and dev.aux.mute is False
    assert not any(b.bypass for c in dev.inputs + dev.outputs for b in c.peq)
    assert (dev.sub[0].gain, dev.sub[1].gain, dev.sub[1].mask) == (100, 100, 0)
    assert dev.delay_unit == 0 and dev.aux.effect.on is False and dev.aux.automix_slope == 0


def test_footer_offsets_from_mapping():
    """Offsets aus den Editor-Tests (captures/state_map.json) auf eine Kopie anwenden."""
    b = bytearray(CFG)
    f = 1214
    b[16 + 10] = 2            # In1 FBQ 2
    b[16 + 62 + 9] = 1        # In2 Mic
    b[f + 56] = 0b11          # EQ-Bypass In1+In2
    b[f + 8] = 0b10           # In2 Band 2 Bypass
    b[f + 58] = 0x80          # EQ-Bypass Out8
    b[f + 38] = 0b100         # Out8 Band 3 Bypass
    b[f + 2] = 1              # Aux Mute
    b[512 + 10] = 1           # Effekt an
    b[512 + 16] = 1           # AutoMix -4 dB
    b[f + 52] = 1             # Delay in m
    b[f + 45] = 1             # Sub Out Mute
    d = Device.parse(bytes(b))
    assert d.inputs[0].fbq == 2 and d.inputs[1].mode == 1
    assert d.inputs[0].eq_bypass and d.inputs[1].eq_bypass and d.outputs[7].eq_bypass
    assert d.inputs[1].peq[1].bypass and d.outputs[7].peq[2].bypass
    assert d.aux.mute and d.aux.effect.on and d.aux.automix_slope == 1 and d.delay_unit == 1 and d.sub[1].mute
