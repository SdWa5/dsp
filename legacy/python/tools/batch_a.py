"""Testreihe A: Out1-Kanalseite (Gain-Skala, Phase, PEQ, HPF/LPF). Alles wird zurückgesetzt."""

import sys
import time

from PIL import Image

from runner import Editor, run

e = Editor()
crops = []


def snap(label, box):
    crops.append((label, e.shot("tmp", box)))
    crops[-1] = (label, Image.open(crops[-1][1]).copy())


GAIN_FADER, GAIN_BOX = (1484, 700), (1440, 895, 1535, 940)
only = set(sys.argv[1:])


def want(n):
    return not only or n in only


if want("gain"):
    def gain_scale():
        e.focus(*GAIN_FADER)
        e.keys("{DOWN 200}", 1.5); snap("raw 80", GAIN_BOX)
        e.keys("{DOWN 40}", 1.0); snap("raw 40", GAIN_BOX)
        e.keys("{DOWN 20}", 1.0); snap("raw 20", GAIN_BOX)
        e.keys("{HOME}", 1.0); snap("HOME", GAIN_BOX)
        e.keys("{UP 280}", 3.0); snap("zurück", GAIN_BOX)
    run("a01_gain_scale", gain_scale, "Out1 Fader: -200,-40,-20,HOME, dann +280")

if want("phase"):
    run("a02_inverse", lambda: (e.click(1484, 966, 1.0), e.click(1484, 966, 1.0)), "Out1 Inverse an/aus")

PEQ_GAIN, PEQ_FREQ, PEQ_Q = (1150, 800), (933, 880), (1040, 855)
if want("peq"):
    run("a03_peq1_gain", lambda: (e.focus(*PEQ_GAIN), e.keys("{DOWN}"), e.keys("{DOWN 9}", 1.0),
                                  snap("PEQ1 gain -10 Schritte", (1100, 930, 1200, 975)),
                                  e.keys("{UP 10}", 1.0)), "PEQ1 Gain -1, -9, +10")
    run("a04_peq1_freq", lambda: (e.focus(*PEQ_FREQ), e.keys("{UP}"), e.keys("{UP 9}", 1.0),
                                  snap("PEQ1 freq +10 Schritte", (880, 930, 985, 975)),
                                  e.keys("{DOWN 10}", 1.0)), "PEQ1 Freq +1, +9, -10")
    run("a05_peq1_q", lambda: (e.focus(*PEQ_Q), e.keys("{UP}"), e.keys("{UP 9}", 1.0),
                               snap("PEQ1 Q +10 Schritte", (990, 930, 1090, 975)),
                               e.keys("{DOWN 10}", 1.0)), "PEQ1 Q +1, +9, -10")
    run("a06_peq1_type", lambda: (e.focus(635, 658), e.keys("{DOWN}", 1.0),
                                  snap("PEQ1 Typ +1", (560, 640, 715, 678)),
                                  e.keys("{DOWN}", 1.0), snap("PEQ1 Typ +2", (560, 640, 715, 678)),
                                  e.keys("{UP 2}", 1.0)), "PEQ1 Typ +1, +1, -2")
    run("a07_peq1_bypass", lambda: (e.click(780, 660, 1.0), e.click(780, 660, 1.0)), "PEQ1 Bypass an/aus")
    run("a08_peq2_gain", lambda: (e.click(82, 705, 0.8), e.focus(*PEQ_GAIN), e.keys("{DOWN}"),
                                  e.keys("{UP}"), e.click(82, 655, 0.8)), "Band 2 wählen, Gain -1/+1")

if want("filter"):
    run("a09_hpf_freq", lambda: (e.focus(1268, 700), e.keys("{UP}"), e.keys("{UP 9}", 1.0),
                                 snap("HPF +10", (1222, 805, 1315, 842)),
                                 e.keys("{DOWN 10}", 1.0)), "HPF Freq +1, +9, -10")
    run("a10_hpf_slope", lambda: (e.focus(1268, 918), e.keys("{DOWN}", 1.0), snap("HPF slope +1", (1222, 900, 1318, 938)),
                                  e.keys("{DOWN}", 1.0), snap("HPF slope +2", (1222, 900, 1318, 938)),
                                  e.keys("{UP 2}", 1.0)), "HPF Slope +1, +1, -2")
    run("a11_hpf_bypass", lambda: (e.click(1268, 963, 1.0), e.click(1268, 963, 1.0)), "HPF Bypass 2x")
    run("a12_lpf_freq", lambda: (e.focus(1377, 700), e.keys("{DOWN}"), e.keys("{DOWN 9}", 1.0),
                                 snap("LPF -10", (1332, 800, 1425, 840)),
                                 e.keys("{UP 10}", 1.0)), "LPF Freq -1, -9, +10")
    run("a13_lpf_slope", lambda: (e.focus(1377, 918), e.keys("{DOWN}", 1.0), e.keys("{UP}", 1.0)), "LPF Slope +1, -1")
    run("a14_lpf_bypass", lambda: (e.click(1377, 963, 1.0), e.click(1377, 963, 1.0)), "LPF Bypass 2x")

if crops:
    w = max(c.width for _, c in crops) + 180
    sheet = Image.new("RGB", (w, sum(c.height + 6 for _, c in crops)), "white")
    from PIL import ImageDraw
    d = ImageDraw.Draw(sheet)
    y = 0
    for label, c in crops:
        sheet.paste(c, (180, y)); d.text((4, y + 8), label, fill="black"); y += c.height + 6
    sheet.save(gui_sheet := e.shot("tmp").parent / f"sheet_{'_'.join(sorted(only)) or 'all'}.png")
    print("Übersicht:", gui_sheet)
e.shot("after_batch_a")
