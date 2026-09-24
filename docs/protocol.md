# t.racks 8x8 Matrix control protocols

Device: Thomann *the t.racks 8x8 Matrix*, firmware `8x8Matrix V010`, OEM Musicrown.

1. **USB-HID / TCP protocol** of the vendor editor (reverse engineered, main part)
2. **RS232/485 protocol** (official, manual chapter 6.1)

Status: ✓ confirmed on the 8x8 by an editor capture or a configuration diff · ≈ taken from the DSP 4x4
documentation, not captured on the 8x8 · ? unknown

## Sources

- Own captures `captures/*.pcap`, test logs `captures/results.jsonl`, `captures/probes.jsonl`
- Manual 8x8, chapter 6.1, pages 52–71: <https://images.thomann.de/pics/atg/atgdata/document/manual/490507_c_490507_r1_en_online.pdf>
- DSP 4x4 Mini: <https://github.com/IMBArator/miniDSP-Linux>, <https://github.com/FlexiFlizz/t.racks-DSP-control>,
  <https://github.com/GlassOnTin/opendsp-4x4>

---

## 1. USB-HID / TCP

- **USB:** HID, VID `0x0168` / PID `0x0821` ("Musicrown / Dsp Process"), 64-byte reports, no report IDs.
  On Linux the device is a hidraw node. A write is the report number `00` followed by the 64-byte report,
  a read returns the 64-byte report.
- **LAN:** TCP port **9761**, identical frames without the 64-byte padding (✓). The session must start
  with `10` (init), otherwise the device does not answer (✓). `B0` (IP) is answered over USB only.

### Frame

```
10 02 | src | dst | len | cmd  data… | 10 03 | xor        xor = len ^ cmd ^ data…
```

src/dst: `00` PC, `01` DSP. There is no byte stuffing. Write commands are answered with `01` (ACK).
Multi-byte values are little-endian.

### Channels

| Channel | Index |
|---|---|
| In1 … In8 | `00` … `07` |
| Out1 … Out8 | `08` … `0F` |
| Aux | `12` |
| AutoMix / Camera (command `64`) | `12` / `13` |

Linked channels: the editor sends mute, phase, Line/Mic and +48V to every channel of the group.
Gain, EQ, filters and the rest go to the operated channel only (✓).

### Commands

| cmd | Data | Function | Status |
|---|---|---|---|
| `01` | – | ACK | ✓ |
| `10` | – | Init, starts the session, reply `15` | ✓ |
| `11` | – | Sent before the init, no reply seen | ? |
| `12` | – | Activate (the editor sends it after link changes) | ✓ |
| `13` | – | Device string `8x8Matrix V010` | ✓ |
| `14` | – | Active preset (editor number, `02` = U02) | ✓ |
| `15` | unit | Delay display 0 ms, 1 m, 2 ft | ✓ |
| `20` / `21` | number | Load / store preset (0 = F00) | ≈ |
| `22` | – | Preset occupancy. The editor sends it on connect. Reply with all 20 user slots in use: 20 × `FF` + `00` | ✓ format, ≈ meaning |
| `2A` | source, target | Copy a channel inside the device (all parameters including gain and mute) | ✓ |
| `26` | 14 chars | Preset name (before `21`) | ≈ |
| `27` → `24` | page 00–19 | Read the configuration (1277 bytes) | ✓ |
| `29` | slot 00–14 | Read a preset name (00 = U01 … 13 = U20, 14 = F00) | ✓ |
| `2C` | – | Device status. The editor sends it on connect. Reply `00 27 0F 00 00 00`, byte 6 lock (≈) | ✓ format, ≈ meaning |
| `2D` / `2F` | PIN | Unlock / set PIN and lock | ≈ |
| `30` | ch, ratio, knee, att u16, rel u16, thr u16 | Compressor (Out, Aux) | ✓ |
| `31` / `32` | ch, freq u16, slope | Low pass / high pass. slope 0 = bypass, 1–20 = BW/BL/LK −6…−48 | ✓ |
| `33` | ch, band, gain u16, freq u16, q, type, bypass | PEQ (In/Aux 5 bands, Out 7) | ✓ |
| `34` | ch, raw u16 | Gain | ✓ |
| `35` | ch, 0/1 | Mute | ✓ |
| `36` | ch, 0/1 | Phase inverted | ✓ |
| `38` | ch, samples u16 | Delay (48 kHz) | ✓ |
| `39` | mode, freq | Test tone: 0 analog, 1 pink, 2 white, 3 sine (31 third-octave bands) | ✓ |
| `3A` | ch, mask u16 | Matrix (Out) or Aux source selection (ch `12`) | ✓ |
| `3B` | ch, flags | Channel link (master = OR mask, slave = 0). The editor sends `2A` master→slave first, then `3B`, then `12`. Linked channels then follow mute, Line and FBQ inside the device | ✓ |
| `3C` | ch, 0/1 | EQ bypass of the whole channel | ✓ |
| `3D` | ch, 8 chars | Channel name | ✓ |
| `3E` | ch, att u16, rel u16, thr u16 | Noise gate (the 8x8 has no hold) | ✓ |
| `40` | – | Meters: 17 × 3 bytes (In1–8, Out1–8, Aux) + 4 bytes | ✓ format, scale approximated |
| `55` | ch, mode | Input: 0 Line, 1 Mic 0 dB, 2 Mic +1 dB, 3 Mic +2 dB | ✓ |
| `56` | ch, 0/1 | +48V phantom power | ✓ |
| `5A` | idx | AutoMix slope 0 = −3 dB … 3 = −6 dB | ✓ |
| `5B` | io, gain %, mute, mask, x | Sub control (0 = inputs, 1 = outputs) | ✓ format |
| `60` | predelay, reflect, time, reverb (u16 each) | Effect: reverb | ✓ |
| `61` | ch, level | FBQ 0 off, 1–4 | ✓ |
| `62` | predelay, delay, repeat, echo (u16 each) | Effect: echo | ✓ |
| `63` | on, gain | Effect on/off + gain | ✓ |
| `64` | ch, att u16, rel u16, thr u16, mask u16, on u16 | AutoMix (`12`) / Camera (`13`) | ✓ |
| `6A` | mode | Working status 0 = Memorize, 1 = Forget | ✓ (0) |
| `6B` | idx, gain %, mute, mask | Group control (0–3 In, 4–7 Out) | ✓ |
| `6C` | `00` | Read group control: 8 × (gain %, mute, mask) | ✓ |
| `B0` | – | IP address (USB only) | ✓ |
| `BB` | id | Set the device ID | ✓ |

Every frame the editor sent in the 143 captures (4830 frames) is rebuilt byte for byte by the builders in
`src/Protocol/Commands.php` (`tests/Protocol/CaptureReplayTest.php`).

### Scales

| Quantity | Formula | Status |
|---|---|---|
| Gain (0–400) | raw < 80: raw/2 − 60 dB, otherwise (raw − 80)/10 − 20 dB; 280 = 0 dB | ✓ |
| Frequency (0–300) | 19.6875 Hz · 2^(i/30) (1/30 octave, 300 = 20.16 kHz) | ✓ (display ±0.1 Hz) |
| PEQ gain (0–240) | (raw − 120)/10 dB | ✓ |
| Q (0–100) | 0.4 · 320^(i/100); 25 = 1.70, 32 = 2.50 | ✓ |
| PEQ type | 0 Peak, 1 Low Shelf, 2 High Shelf, 3 Low Pass, 4 High Pass, 5 Allpass1, 6 Allpass2 | ✓ |
| Times | ms = raw + 1 | ✓ |
| Gate threshold | raw/2 − 90 dB | ✓ |
| Compressor / AutoMix threshold | raw/2 − 90 dB (180 = 0 dB, 220 = +20 dB) | ✓ |
| Ratio | 0 = 1:1.0 … 14 = 1:20, 15 = Limit | ✓ |
| Delay | samples / 48 (ms) | ✓ |
| Reverb time | 139 = 1.96 s (no further data points) | approximated |
| Meter | (lo + 256 · hi) / 256 − 60 dB | approximated |

### Configuration (1277 bytes via `27`)

| Offset | Size | Content |
|---|---|---|
| 0 | 16 | `FF FF` + preset name (14) |
| 16 | 8 × 62 | Inputs |
| 512 | 94 | Aux |
| 606 | 8 × 76 | Outputs |
| 1214 | 63 | Footer |

**Input (62):** 0 name (8) · 8 ? (probably +48V) · 9 Line/Mic · 10 FBQ · 12 gate attack · 14 gate release ·
16 gate threshold · 18 PEQ 5 × (gain u16, freq u16, q, type) · 48 HPF slope · 50 HPF freq · 52 LPF slope ·
54 LPF freq · 56 gain · 58 phase · 60 link

**Output (76):** 0 name · 8 routing mask · 10 HPF slope · 12 HPF freq · 14 LPF slope · 16 LPF freq ·
18 PEQ 7 × 6 · 60 ratio · 61 knee · 62 attack · 64 release · 66 threshold · 68 gain · 70 phase · 72 delay · 74 link

**Aux (94):** 0 name · 8 source mask · 10 effect on/off · 12 effect gain · 16 AutoMix slope · 18 AutoMix (att, rel,
thr, mask, on) · 28 Camera (same) · 38 PEQ 5 × 6 · 68 echo (predelay, delay, repeat, echo) · 76 reverb (predelay,
reflect, time, reverb) · 84 ratio · 85 knee · 86 attack · 88 release · 90 threshold · 92 gain

**Footer (63):** 0 mute mask inputs · 2 Aux mute · 4 mute mask outputs · 6 + 2k band bypass mask input k ·
22 band bypass Aux · 24 + 2k band bypass output k · 40 sub in (gain %, mute, mask, x) · 44 sub out (same) ·
48 test tone mode · 52 delay unit · 56 EQ bypass mask inputs · 58 EQ bypass mask outputs · 60 EQ bypass Aux

Every field is checked against the vendor editor display (`tests/Model/DeviceTest.php`). `DeviceState` writes
each command where `Device::parse()` reads it, checked by rebuilding the fixture from its upload frames
(`tests/Model/DeviceStateTest.php`).
Group control is read with `6C 00` (8 × gain %, mute, mask).

### Still open

- +48V (probably input block byte 8) and working status: the location is not confirmed, the app remembers the value it set.
- `20`/`21`/`26` (presets) and `2D`/`2F` (PIN) were not captured on the 8x8 (≈ 4x4). They are next in line for a live test
  with backups and a known recovery path, see `TODO.md`.
- Changing the IP address: the command is unknown (the editor sends it only on a real change).
- Meters: the format is known, the dB scale is approximated.

---

## 2. RS232/485 (official, manual chapter 6.1)

115200 baud 8N1, at least 200 ms between commands, `7B 7D | ID | cmd | d1 d2 d3 | 7D 7B` (9 bytes), ID 1–254.

| cmd | Function | cmd | Function |
|---|---|---|---|
| `40`/`43` | Load preset (F:0/U:1, number) | `4E`/`4F` | Set/read matrix |
| `41`/`54` | Gain ± | `51`/`52`/`53` | Aux gain ±/mute/volume |
| `42` | Mute | `55` | Aux effect/camera/AutoMix on/off |
| `44`/`45` | Volume In/Out (280 = 0 dB) | `56` | Aux source selection |
| `46`/`47`/`4C` | Sub volume/±/mute | `57`/`5E` | Set/read FBQ |
| `48`/`49`/`4A`/`4B`/`4D` | Read gain/mute/preset/sub/level | `58`/`59`/`5B`/`5C` | Read Aux |

The RS232 command numbers do **not** apply inside the USB/TCP frame (`55`/`56` mean something else there, for example).
