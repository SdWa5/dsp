# trackdsp – t.racks 8x8 Matrix Editor für Linux

Freier Nachbau der Windows-Software „the t.racks 8x8 Matrix Digital Processor Editor“ für **Linux**
(läuft auch unter Windows). Steuert die Thomann *t.racks 8x8 Matrix* per **USB** oder **LAN (TCP 9761)**.
Das Protokoll ist in [PROTOCOL.md](PROTOCOL.md) beschrieben.

## Installation (Linux)

Voraussetzung: Python ≥ 3.10 mit venv (Debian/Ubuntu: `sudo apt install python3 python3-venv`).

```bash
./install-linux.sh
```

Das Skript legt eine eigene Python-Umgebung unter `~/.local/share/trackdsp` an, installiert die Startbefehle
`trackdsp-gui` und `trackdsp` nach `~/.local/bin`, einen Eintrag „t.racks 8x8 Matrix Editor“ im
Anwendungsmenü und (mit sudo) die udev-Regel für USB-Zugriff ohne root. Entfernen: `./install-linux.sh --uninstall`.

Manuell: `pip install .` und für USB `sudo cp udev/99-trackdsp.rules /etc/udev/rules.d/`.

## Editor starten

```bash
trackdsp-gui                         # Verbindungsdialog: USB, Netzwerk oder Demo
trackdsp-gui --target 192.168.68.10  # direkt per LAN
trackdsp-gui --target usb
trackdsp-gui --demo                  # ohne Gerät ausprobieren
```

Funktionsumfang wie im Original:

| Bereich | Funktionen |
|---|---|
| Gain | Fader, Pegelanzeige, Mute, Phase, Line/Mic + Mic-Empfindlichkeit, +48V – Ein- und Ausgänge |
| Gate / Comp / Delay | Threshold, Attack, Release · Ratio, Knee · Delay in ms/m/ft |
| Matrix | beliebige Zuordnung In1–8 (+Aux) → Out1–8 |
| In1–8 / Out1–8 | Frequenzgang mit verschiebbaren Punkten (Mag/Phase), PEQ 5/7 Bänder (7 Typen, Bypass), EQ-Bypass/Reset, Hoch-/Tiefpass mit 20 Flanken, FBQ (Eingänge) |
| Aux | PEQ, Kompressor, AutoMix (Slope, Threshold, Attack, Release, Auswahl), Effekt (Echo/Hall), Kamera, Quellenwahl, Gain, Mute |
| Menüs | File (Öffnen/Speichern als JSON, Upload, Download, Factory Default), Link, Copy, Lock, Working Status, Setting ID/IP, Test Tone, Channel Name, Sub Control, Group Control, Help (RS232-Tabelle), About |
| Presets | Number/Name-Anzeige, Store, Recall (U01–U20, F00) |

Hinweise:
- Einige Zustände liefert das Gerät nicht in der Konfiguration (z. B. Line/Mic, +48V, FBQ, Sub Control).
  Die App zeigt sie, sobald sie einmal gesetzt wurden, und speichert sie in der Preset-Datei mit.
- Preset speichern/laden, Lock und Link nutzen die Befehle des Schwestergeräts DSP 4x4 (siehe PROTOCOL.md, ≈).
- Die Pegelanzeige ist eine Näherung (nicht kalibriert).

## Kommandozeile

```bash
trackdsp -t 192.168.68.10 info       # Gerät, IP, aktives Preset
trackdsp presets
trackdsp status                      # Mute-Zustand aller Kanäle
trackdsp gain in1 -6                 # Anhebungen über 0 dB nur mit --allow-boost
trackdsp mute out1 | unmute out1
trackdsp phase out3 invert
trackdsp route out3 1,2
trackdsp dump config.bin
```

## Bibliothek

```python
from trackdsp import Matrix, commands as C
from trackdsp.model import Device

with Matrix.connect("192.168.68.10") as dsp:   # oder "usb"
    dev = Device.parse(dsp.config())
    print(dev.preset_name, [c.name for c in dev.outputs])
    dsp.write(C.mute(8, True))                  # Out1 stumm
```

## Entwicklung

```bash
pip install -e .[dev]
pytest tests        # 43 Tests: Protokoll, Modell gegen Editor-Anzeige, Oberfläche gegen Editor-Mitschnitte
```

`tools/` enthält die Windows-Werkzeuge, mit denen das Protokoll entschlüsselt wurde
(GUI-Automatisierung des Original-Editors, USBPcap-Mitschnitte).
