# t.racks 8x8 Matrix – Steuerprotokolle

Gerät: Thomann *the t.racks 8x8 Matrix*, Firmware `8x8Matrix V010`, OEM Musicrown.

1. **USB-HID / TCP-Protokoll** des Editors (reverse engineered, Hauptteil)
2. **RS232/485-Protokoll** (offiziell, Handbuch Kap. 6.1)

Status: ✓ am 8x8 per Editor-Mitschnitt bzw. Konfigurationsvergleich bestätigt · ≈ aus der DSP-4x4-Doku,
am 8x8 nicht mitgeschnitten · ? unbekannt

## Quellen

- Eigene Mitschnitte `captures/*.pcap`, Testprotokolle `captures/results.jsonl`, `captures/probes.jsonl`
- Handbuch 8x8, Kap. 6.1, S. 52–71: <https://images.thomann.de/pics/atg/atgdata/document/manual/490507_c_490507_r1_en_online.pdf>
- DSP 4x4 Mini: <https://github.com/IMBArator/miniDSP-Linux>, <https://github.com/FlexiFlizz/t.racks-DSP-control>,
  <https://github.com/GlassOnTin/opendsp-4x4>

---

## 1. USB-HID / TCP

- **USB:** HID, VID `0x0168` / PID `0x0821` („Musicrown / Dsp Process“), 64-Byte-Reports, Report-ID 0
- **LAN:** TCP-Port **9761**, identische Rahmen ohne 64-Byte-Auffüllung (✓). Die Sitzung muss mit `10`
  (Init) beginnen, sonst antwortet das Gerät nicht (✓). `B0` (IP) wird nur per USB beantwortet.

### Rahmen

```
10 02 | src | dst | len | cmd  data… | 10 03 | xor        xor = len ^ cmd ^ data…
```
src/dst: `00` PC, `01` DSP. Kein Byte-Stuffing. Schreibbefehle → Antwort `01` (ACK). Mehrbyte-Werte Little Endian.

### Kanäle

| Kanal | Index |
|---|---|
| In1 … In8 | `00` … `07` |
| Out1 … Out8 | `08` … `0F` |
| Aux | `12` |
| AutoMix / Kamera (Befehl `64`) | `12` / `13` |

Gekoppelte Kanäle: Der Editor schickt Mute, Phase, Line/Mic und +48V an alle Kanäle der Gruppe;
Gain, EQ, Filter usw. nur an den bedienten Kanal (✓).

### Befehle

| cmd | Daten | Funktion | Status |
|---|---|---|---|
| `01` | – | ACK | ✓ |
| `10` | – | Init / Sitzung beginnen, Antwort `15` | ✓ |
| `11` | – | vor dem Init | ? |
| `12` | – | Aktivieren (Editor sendet es nach Link-Änderungen) | ✓ |
| `13` | – | Geräte-String `8x8Matrix V010` | ✓ |
| `14` | – | aktives Preset (Editor-Nummer, `02` = U02) | ✓ |
| `15` | Einheit | Delay-Anzeige 0 ms, 1 m, 2 ft | ✓ |
| `20` / `21` | Nummer | Preset laden / speichern (0 = F00) | ≈ |
| `22` | – | Preset-Belegung | ≈ |
| `2A` | Quelle, Ziel | Kanal im Gerät kopieren (alle Parameter inkl. Gain/Mute) | ✓ |
| `26` | 14 Zeichen | Preset-Name (vor `21`) | ≈ |
| `27` → `24` | Seite 00–19 | Konfiguration lesen (1277 Byte) | ✓ |
| `29` | Platz 00–14 | Preset-Name lesen (00 = U01 … 13 = U20, 14 = F00) | ✓ |
| `2C` | – | Geräte-Status (Byte 6 Lock) | ≈ |
| `2D` / `2F` | PIN | Entsperren / PIN setzen + sperren | ≈ |
| `30` | ch, ratio, knee, att u16, rel u16, thr u16 | Kompressor (Out, Aux) | ✓ |
| `31` / `32` | ch, freq u16, slope | Tiefpass / Hochpass; slope 0 = Bypass, 1–20 = BW/BL/LK −6…−48 | ✓ |
| `33` | ch, band, gain u16, freq u16, q, type, bypass | PEQ (In/Aux 5, Out 7 Bänder) | ✓ |
| `34` | ch, raw u16 | Gain | ✓ |
| `35` | ch, 0/1 | Mute | ✓ |
| `36` | ch, 0/1 | Phase invertiert | ✓ |
| `38` | ch, samples u16 | Delay (48 kHz) | ✓ |
| `39` | mode, freq | Testton: 0 Analog, 1 Pink, 2 White, 3 Sinus (31 Terzen) | ✓ |
| `3A` | ch, mask u16 | Matrix (Out) bzw. Aux-Quellenwahl (ch `12`) | ✓ |
| `3B` | ch, flags | Kanalkopplung (Master = OR-Maske, Slave = 0); Editor: erst `2A` Master→Slave, dann `3B`, dann `12`. Gekoppelte Kanäle übernehmen danach Mute/Line/FBQ im Gerät | ✓ |
| `3C` | ch, 0/1 | EQ-Bypass des ganzen Kanals | ✓ |
| `3D` | ch, 8 Zeichen | Kanalname | ✓ |
| `3E` | ch, att u16, rel u16, thr u16 | Noise Gate (8x8 ohne Hold) | ✓ |
| `40` | – | Pegel: 17 × 3 Byte (In1–8, Out1–8, Aux) + 4 Byte | ✓ Format, Skala genähert |
| `55` | ch, mode | Eingang: 0 Line, 1 Mic 0 dB, 2 Mic +1 dB, 3 Mic +2 dB | ✓ |
| `56` | ch, 0/1 | +48V Phantomspeisung | ✓ |
| `5A` | idx | AutoMix-Slope 0 = −3 dB … 3 = −6 dB | ✓ |
| `5B` | io, gain %, mute, mask, x | Sub Control (0 = Eingänge, 1 = Ausgänge) | ✓ Format |
| `60` | predelay, reflect, time, reverb (je u16) | Effekt: Hall | ✓ |
| `61` | ch, level | FBQ 0 aus, 1–4 | ✓ |
| `62` | predelay, delay, repeat, echo (je u16) | Effekt: Echo | ✓ |
| `63` | on, gain | Effekt ein/aus + Gain | ✓ |
| `64` | ch, att u16, rel u16, thr u16, mask u16, on u16 | AutoMix (`12`) / Kamera (`13`) | ✓ |
| `6A` | mode | Working Status 0 = Memorize, 1 = Forget | ✓ (0) |
| `6B` | idx, gain %, mute, mask | Gruppensteuerung (0–3 In, 4–7 Out) | ✓ |
| `6C` | `00` | Gruppensteuerung lesen: 8 × (gain %, mute, mask) | ✓ |
| `B0` | – | IP-Adresse (nur USB) | ✓ |
| `BB` | id | Geräte-ID setzen | ✓ |

### Skalen

| Größe | Formel | Status |
|---|---|---|
| Gain (0–400) | raw < 80: raw/2 − 60 dB, sonst (raw − 80)/10 − 20 dB; 280 = 0 dB | ✓ |
| Frequenz (0–300) | 19,6875 Hz · 2^(i/30) (1/30 Oktave, 300 = 20,16 kHz) | ✓ (Anzeige ±0,1 Hz) |
| PEQ-Gain (0–240) | (raw − 120)/10 dB | ✓ |
| Q (0–100) | 0,4 · 320^(i/100); 25 = 1,70, 32 = 2,50 | ✓ |
| PEQ-Typ | 0 Peak, 1 Low Shelf, 2 High Shelf, 3 Low Pass, 4 High Pass, 5 Allpass1, 6 Allpass2 | ✓ |
| Zeiten | ms = raw + 1 | ✓ |
| Gate-Threshold | raw/2 − 90 dB | ✓ |
| Kompressor-/AutoMix-Threshold | raw/2 − 90 dB (180 = 0 dB, 220 = +20 dB) | ✓ |
| Ratio | 0 = 1:1.0 … 14 = 1:20, 15 = Limit | ✓ |
| Delay | Samples / 48 (ms) | ✓ |
| Hallzeit | 139 ≙ 1,96 s (weitere Stützpunkte fehlen) | genähert |

### Konfiguration (1277 Byte über `27`)

| Offset | Größe | Inhalt |
|---|---|---|
| 0 | 16 | `FF FF` + Preset-Name (14) |
| 16 | 8 × 62 | Eingänge |
| 512 | 94 | Aux |
| 606 | 8 × 76 | Ausgänge |
| 1214 | 63 | Fußbereich |

**Eingang (62):** 0 Name (8) · 8 ? (vermutlich +48V) · 9 Line/Mic · 10 FBQ · 12 Gate-Attack · 14 Gate-Release · 16 Gate-Threshold ·
18 PEQ 5 × (gain u16, freq u16, q, type) · 48 HPF-Slope · 50 HPF-Freq · 52 LPF-Slope · 54 LPF-Freq ·
56 Gain · 58 Phase · 60 Link

**Ausgang (76):** 0 Name · 8 Routing-Maske · 10 HPF-Slope · 12 HPF-Freq · 14 LPF-Slope · 16 LPF-Freq ·
18 PEQ 7 × 6 · 60 Ratio · 61 Knee · 62 Attack · 64 Release · 66 Threshold · 68 Gain · 70 Phase · 72 Delay · 74 Link

**Aux (94):** 0 Name · 8 Quellenmaske · 10 Effekt ein/aus · 12 Effekt-Gain · 16 AutoMix-Slope · 18 AutoMix (att, rel, thr, mask, on) ·
28 Kamera (dito) · 38 PEQ 5 × 6 · 68 Echo (predelay, delay, repeat, echo) · 76 Hall (predelay, reflect, time,
reverb) · 84 Ratio · 85 Knee · 86 Attack · 88 Release · 90 Threshold · 92 Gain

**Fußbereich (63):** 0 Mute-Maske Eingänge · 2 Aux-Mute · 4 Mute-Maske Ausgänge · 6 + 2k Band-Bypass-Maske
Eingang k · 22 Band-Bypass Aux · 24 + 2k Band-Bypass Ausgang k · 40 Sub In (Gain %, Mute, Maske, x) ·
44 Sub Out (dito) · 48 Testton-Modus · 52 Delay-Einheit · 56 EQ-Bypass-Maske Eingänge · 58 EQ-Bypass-Maske
Ausgänge · 60 EQ-Bypass Aux

Alle Felder gegen die Anzeige des Original-Editors geprüft (`tests/test_model.py`).

Die Gruppensteuerung liefert `6C 00` (8 × Gain %, Mute, Maske).

### Noch offen

- +48V (vermutlich Eingangsblock Byte 8) und Working Status: Ort nicht bestätigt, die App merkt sich den gesetzten Wert
- `20`/`21`/`26` (Presets), `2D`/`2F` (PIN): am 8x8 nicht mitgeschnitten (≈ 4x4) – bewusst nicht getestet,
  weil Speichern Presets überschreibt und die PIN das Gerät sperrt
- IP-Adresse ändern: Befehl unbekannt (der Editor sendet ihn nur bei einer echten Änderung)
- Pegelanzeige: Format bekannt, dB-Skala nur genähert

---

## 2. RS232/485 (offiziell, Handbuch Kap. 6.1)

115200 Baud 8N1, ≥ 200 ms Abstand, `7B 7D | ID | cmd | d1 d2 d3 | 7D 7B` (9 Byte), ID 1–254.

| cmd | Funktion | cmd | Funktion |
|---|---|---|---|
| `40`/`43` | Preset laden (F:0/U:1, Nr.) | `4E`/`4F` | Matrix setzen/lesen |
| `41`/`54` | Gain ± | `51`/`52`/`53` | Aux Gain ±/Mute/Lautstärke |
| `42` | Mute | `55` | Aux Effekt/Kamera/AutoMix ein/aus |
| `44`/`45` | Lautstärke In/Out (280 = 0 dB) | `56` | Aux-Quellenwahl |
| `46`/`47`/`4C` | Sub-Lautstärke/±/Mute | `57`/`5E` | FBQ setzen/lesen |
| `48`/`49`/`4A`/`4B`/`4D` | Gain/Mute/Preset/Sub/Pegel lesen | `58`/`59`/`5B`/`5C` | Aux lesen |

Die RS232-Befehlsnummern gelten **nicht** im USB/TCP-Rahmen (dort haben z. B. `55`/`56` eine andere Bedeutung).
