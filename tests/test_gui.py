"""Oberflächentest ohne Gerät: Bedienung simulieren, gesendete Befehle mit Editor-Mitschnitten vergleichen."""

import os

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
QtWidgets = pytest.importorskip("PySide6.QtWidgets")

from trackdsp.gui import dialogs as D  # noqa: E402
from trackdsp.gui.main import MainWindow  # noqa: E402


@pytest.fixture(scope="module")
def app():
    return QtWidgets.QApplication.instance() or QtWidgets.QApplication([])


@pytest.fixture
def win(app):
    w = MainWindow(demo=True)
    sent = []
    w.ctx.send = lambda frame, key=None: sent.append(frame.encode()[5:-3].hex(" "))  # cmd + Daten
    w.ctl.send_many = lambda frames: sent.extend(f.encode()[5:-3].hex(" ") for f in frames)
    w.sent = sent
    yield w
    w.close()


def page(w, name):
    return w.pages[name]


def test_all_tabs_and_demo_state(win):
    assert win.tabs.count() == 22
    assert win.ctx.dev.preset_name == "Bulgaria26"
    assert page(win, "Gain").strips_in[0][1].value() == 400      # In1 +12 dB


def test_mute_linked_pair_like_editor(win):
    mute = page(win, "Gain").strips_in[0][6]                       # In1 Mute (In1/In2 gekoppelt)
    mute.click()                                                   # war stumm -> an
    assert win.sent == ["35 00 00", "35 01 00"]                    # Editor: 35 00 xx + 35 01 xx


def test_phase_output_pair(win):
    ph = page(win, "Gain").strips_out[0][3]                        # Out1 Phase (Out1/Out2 gekoppelt)
    ph.click()                                                     # war invertiert -> normal
    assert win.sent == ["36 08 00", "36 09 00"]                    # Editor-Mitschnitt a02


def test_gain_fader(win):
    page(win, "Out1").gain.slider.setValue(279)
    assert win.sent == ["34 08 17 01"]                             # Editor: 34 08 17 01 (-0,1 dB)


def test_peq_band_and_type(win):
    pe = page(win, "Out8").peq
    pe.rows[0][3].setCurrentIndex(119)                              # Gain-Auswahl
    pe.rows[0][3].activated.emit(119)
    pe.rows[0][4].setCurrentIndex(6)                                # Typ Allpass2
    pe.rows[0][4].activated.emit(6)
    assert win.sent == ["33 0f 00 77 00 1f 00 19 00 00", "33 0f 00 77 00 1f 00 19 06 00"]


def test_filter_bypass_uses_slope(win):
    fad, slope, byp = page(win, "Out1").peq.filters["hpf"]          # Out1 HPF ist Bypass (0)
    byp.click()                                                     # Bypass aus -> Flanke LK-48 (20)
    assert win.sent == ["32 08 00 00 14"]


def test_matrix_gate_comp_delay(win):
    page(win, "Matrix").btn[(2, 7)].click()                         # Out3 + In8
    gate = page(win, "Gate").cols[0]
    gate[1].slider.setValue(1)                                      # In1 Threshold +1
    comp = page(win, "Comp").comps[7]
    comp.ratio.slider.setValue(1)                                   # Out8 Ratio +1
    page(win, "Delay").faders[7].slider.setValue(1)                 # Out8 Delay +1
    assert win.sent == ["3a 0a 8f 00", "3e 00 00 00 b7 0b 01 00", "30 0f 01 00 31 00 c7 00 dc 00", "38 0f 01 00"]


def test_input_fbq_mode_48v(win):
    p = page(win, "In1")
    p.fbq.button(1).click()
    p.mode.click()                                                  # Line -> Mic 0 dB (beide gekoppelt)
    p.p48.click()
    assert win.sent == ["61 00 01", "55 00 01", "55 01 01", "56 00 01", "56 01 01"]


def test_aux_page(win):
    aux = page(win, "Aux")
    aux.sel_btn[7].click()                                          # Select In8
    aux.mute.click()
    aux.gain.slider.setValue(279)
    assert win.sent == ["3a 12 80 00", "35 12 01", "34 12 17 01"]


def test_aux_dialogs(win):
    d = D.__dict__  # noqa: F841
    from trackdsp.gui.pages import EffectDialog, MixDialog
    ef = EffectDialog(win.ctx, win)
    ef.faders["echo"].slider.setValue(79)
    ef.faders["reverb"].slider.setValue(29)
    ef.gain.slider.setValue(54)
    am = MixDialog(win.ctx, "automix", 0x12, "AutoMix", win)
    am.slope.setCurrentIndex(1)
    am.slope.activated.emit(1)
    am.thr.slider.setValue(179)
    assert win.sent == ["62 0a 00 c8 00 3c 00 4f 00", "60 32 00 20 00 8b 00 1d 00", "63 00 36", "5a 01",
                        "64 12 63 00 b7 0b b3 00 00 00 00 00"]


def test_menu_dialogs_build(win):
    for cls in (D.ChannelNameDialog, D.LinkDialog, D.CopyDialog, D.LockDialog, D.IdIpDialog, D.TestToneDialog,
                D.SubControlDialog, D.GroupControlDialog):
        dlg = cls(win.ctx, win)
        assert dlg.windowTitle()
    D.PresetDialog(win.ctx, store=True, parent=win)
    D.PresetDialog(win.ctx, store=False, parent=win)
    D.ConnectDialog("usb", win)


def test_channel_name_and_link(win):
    dlg = D.ChannelNameDialog(win.ctx, win)
    dlg.edits[15].setText("OUT8")
    dlg.apply()
    assert win.sent == ["3d 0f 4f 55 54 38 00 00 00 00"]            # Editor-Mitschnitt j01


def test_copy_channel(win):
    win.ctl.reload = lambda: win.sent.append("reload")
    dlg = D.CopyDialog(win.ctx, win)
    dlg.kind.setCurrentIndex(1)
    dlg.src.setCurrentIndex(2)                                      # Out3 -> Out8
    dlg.dst.setCurrentIndex(7)
    dlg.apply()
    assert win.sent == ["2a 0a 0f", "reload"]


def test_link_like_editor(win):
    dlg = D.LinkDialog(win.ctx, win)
    dlg.combos[14].setCurrentIndex(4)                               # Out7 + Out8 in Gruppe 4
    dlg.combos[15].setCurrentIndex(4)
    frames = [f.encode()[5:-3].hex(" ") for f in dlg.frames()]
    assert frames == ["2a 0e 0f", "3b 0e c0", "3b 0f 00", "12"]     # Editor-Mitschnitt p01


def test_json_save_load(win, tmp_path):
    f = tmp_path / "x.json"
    f.write_text(win.ctx.dev.to_json(), encoding="utf-8")
    from trackdsp.model import Device
    assert Device.from_json(f.read_text(encoding="utf-8")).raw == win.ctx.dev.raw
