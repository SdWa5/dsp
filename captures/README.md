# Mitschnitte und Testdaten

Rohdaten aus der Entschlüsselung des Protokolls (24.09.2026, Gerät „8x8Matrix V010“, Preset U02 „Bulgaria26“).
Auswertung der Mitschnitte:

```bash
python analyze_capture.py captures/<name>.pcap      # alle HID-Reports
python tools/cap.py show <name>                     # nur Befehle (ohne Pegelabfrage/ACK)
```

## USB-Mitschnitte (`*.pcap`, USBPcap)

| Präfix | Inhalt |
|---|---|
| `dsp_session1` | erster manueller Mitschnitt: Verbinden, Gain In1, Mute, Routing Out3 (+ Testton `39 02 00`) |
| `t00`, `t99`, `t50`–`t62` | Verbindungsaufbau / komplettes Auslesen, Seitenrundgang, Leerlauf |
| `t01`–`t04`, `a*` | Out1: Mute, Gain-Skala, Phase, PEQ, HPF/LPF |
| `b*`, `c*` | Gain-Seite: Phase, Line/Mic + Empfindlichkeit, +48V |
| `d*` | In1-Seite: FBQ, Eingangs-PEQ, Eingangsfilter |
| `e*`, `l*`, `m*` | Aux: AutoMix, Effekt (Echo/Hall), Kamera, Quellenwahl, Kompressor, Dialoge |
| `f*`, `g*` | Gate, Kompressor, Delay + Einheit, EQ-Bypass, PEQ-Typen |
| `h*`, `i*`, `j*`, `k*` | Menüdialoge: Testton, Kanalname, Sub/Group Control, Link, ID/IP, Working Status, Presets |
| `n*`, `o*` | Zuordnung Zustand → Konfigurationsbyte (mit TCP-Auslesen vorher/nachher) |
| `p*` | Kanalkopplung Out7+Out8 (`2A`, `3B`, `12`) |
| `fix_*` | Rücksetzungen nach Tests |

## Weitere Dateien

| Datei | Inhalt |
|---|---|
| `config_tcp.bin`, `config_usb.bin` | komplette Konfiguration (1277 Byte) per TCP bzw. USB – Referenzstand |
| `results.jsonl` | je Test: gesendete Befehle (aus `tools/runner.py`) |
| `probes.jsonl`, `state_map.json` | Tests mit Konfigurationsvergleich: welches Byte sich ändert, ob wiederhergestellt |
| `session1_decoded.txt` | lesbarer Hexdump von `dsp_session1` |
| `page_tour.json` | sichtbare Control-Werte aller Editor-Reiter |
| `dialogs.json`, `dialogs_open.json`, `aux_dialogs.json`, `editor_hidden_windows.txt` | Aufbau der Editor-Dialoge (Controls, Auswahllisten) |

Screenshots des Original-Editors und der neuen App liegen in `tools/shots/` (`page_*` = Editor-Reiter,
`dlg_*` = Editor-Dialoge, `app/` = neue App, `app/linux_*` = unter Linux).

Das Handbuch ist nicht enthalten (urheberrechtlich geschützt):
<https://images.thomann.de/pics/atg/atgdata/document/manual/490507_c_490507_r1_en_online.pdf>
