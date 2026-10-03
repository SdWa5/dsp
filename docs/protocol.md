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
  a read returns the 64-byte report. Replies come within about 10 ms. About one request per 0.2 to 0.3 s
  of continuous traffic gets no reply at all, neither late nor garbled, with or without an init before it.
  Sending it again works (✓, VIM3, 2026-09-28).
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

Linked channels: the vendor editor sends mute, phase, Line/Mic and +48V to every channel of the group, and
gain, EQ, filters and the rest to the operated channel only (✓). Whether the 8x8 then copies those to the
linked channels by itself is not measured. The trackdsp editor therefore sends every channel setting to every
channel of the group, the channel name excepted, so a link group works the same on the device and in demo mode.
Linking copies the master to every new slave (`2A`), and to a slave that moves to another group as well. The
demo copies the name with the rest, and whether the 8x8 does is not measured, so the editor writes each new
slave's own name back right after the copy.

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
| `20` / `21` | number | Load / store preset (0 = F00). The ACK comes after the flash work, about 1.2 s for a load and 0.7 s for a store over USB. A store also makes the slot the active preset and writes its name into the live header | ✓ U20 |
| `22` | – | Preset occupancy. The editor sends it on connect. Reply one byte per user slot U01..U20, `FF` stored, `00` empty, plus `00`. After a factory reset all 20 are empty | ✓ |
| `2A` | source, target | Copy a channel inside the device (all parameters including gain and mute) | ✓ |
| `26` | 14 chars | Preset name (before `21`) | ✓ |
| `27` → `24` | page 00–19 | Read the configuration (1277 bytes) | ✓ |
| `29` | slot 00–14 | Read a preset name (00 = U01 … 13 = U20, 14 = F00) | ✓ |
| `2C` | – | Device status. The editor sends it on connect. Reply `00 27 0F 00 00 00`, byte 6 lock (≈) | ✓ format, ≈ meaning |
| `2D` / `2F` | PIN | Unlock / set PIN and lock | ≈ |
| `30` | ch, ratio, knee, att u16, rel u16, thr u16 | Compressor (Out, Aux). The vendor editor has no control for the byte called knee and sends `00` in every capture. Its meaning is unmeasured, so the editor and the CLI send back what the device holds. At ratio 1:1.0 an output gain other than 0 dB misbehaves, and every compression mirrors the signal at 24 kHz, see [Signal chain](#signal-chain). Limit or 1:1.1 at +20 dB is off | ✓ |
| `31` / `32` | ch, freq u16, slope | Low pass / high pass. slope 0 = bypass, 1–20 = BW/BL/LK −6…−48 | ✓ |
| `33` | ch, band, gain u16, freq u16, q, type, bypass | PEQ (In/Aux 5 bands, Out 7) | ✓ |
| `34` | ch, raw u16 | Gain | ✓ |
| `35` | ch, 0/1 | Mute | ✓ |
| `36` | ch, 0/1 | Phase inverted | ✓ |
| `38` | ch, samples u16 | Delay (48 kHz) | ✓ |
| `39` | mode, freq | Test tone: 0 analog, 1 pink, 2 white, 3 sine (31 third-octave bands). The configuration keeps the mode at footer +48 and the frequency at +50 | ✓ |
| `3A` | ch, mask u16 | Matrix (Out) or Aux source selection (ch `12`) | ✓ |
| `3B` | ch, flags | Channel link (master = OR mask, slave = 0). The editor sends `2A` master→slave first, then `3B`, then `12`. Linked channels then follow mute, Line and FBQ inside the device | ✓ |
| `3C` | ch, 0/1 | EQ bypass of the whole channel | ✓ |
| `3D` | ch, 8 chars | Channel name | ✓ |
| `3E` | ch, att u16, rel u16, thr u16 | Noise gate (the 8x8 has no hold) | ✓ |
| `40` | – | Meters: 17 × 3 bytes (In1–8, Out1–8, Aux), then byte 51 and `FF FF 00`. Scale under Meter below. Byte 51 is `00` in 3335 of 3482 captured replies and `03` in the other 147. They all come from `a01_gain_scale`, `a02_inverse`, `a11_hpf_bypass`, `a14_lpf_bypass`, `fix_out1_gain` and `t03_gain_out1`, which hold Out1 and Out2 at Limit from 0 dB. It turns `03` once the Out1 gain reaches +12 dB and holds about 3.3 s after it drops. Read as a bitmask of the outputs whose limiter works (bit 0 = Out1), not yet confirmed on the device | ✓ |
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
| Times | ms = raw + 1. The vendor editor's faders run 1..999 ms attack and 1..3000 ms release on the gate and the output compressors, 1..200 ms and 1..999 ms on the Aux compressor, and 1..5000 ms both on AutoMix and Camera (`tools/shots/ranges/`, 2026-10-03). No capture sends an AutoMix or Camera time above 3000 ms, so this editor stops there until one does | ✓ |
| Gate threshold | raw/2 − 90 dB, 0..220 (−90..+20 dB). The vendor editor's fader ends at +20.0 dB (`tools/shots/gate_threshold_max.png`, 2026-10-02), like the compressor's | ✓ |
| Compressor / AutoMix threshold | raw/2 − 90 dB (180 = 0 dB, 220 = +20 dB). The limiter moves the output peak 1 dB per dB. The vendor editor's compressor faders run −90..+20 dB, its AutoMix and Camera faders −50..0 dB (80..180) | ✓, unit at the connector unconfirmed |
| Ratio | 0 = 1:1.0 … 14 = 1:20, 15 = Limit | ✓ |
| Delay | samples / 48 (ms). The vendor editor shows metres at 343.5 m/s and feet at 3.2808 per metre, 680 ms = 233.580 m = 766.329 ft (`tools/shots/ranges/delay_m.png`, `delay_ft.png`) | ✓ |
| Effect | Levels 0..100, echo delay 0..400 ms, both predelays 0..250 ms in the vendor editor (`tools/shots/ranges/effect_max.png`), raw = ms as the factory 200/10/50 show. No capture sends a predelay above 200, so this editor stops there until one does | ✓ (ranges from the vendor editor) |
| Reverb time | 139 = 1.96 s. The vendor editor shows 0.1 s at its minimum and 4.00 s at its maximum (`tools/shots/ranges/effect_min.png`, `effect_max.png`), which `raw × 1.96 / 139` misses at both ends | approximated |
| Meter | value = lo + 256 · hi + byte 3 / 256, dB = (value − 15906) · 20 · log10(2) / 128, which is 21.26 per dB. 0 dB lies on the threshold scale, a 1 kHz sine at unity reads −27.8 dB. 0x05AD is digital silence | ✓ |

### Signal chain

Measured with the loopbacks of a UMC1820 on 2026-09-29. The procedures and the numbers are in
[measurements.md](measurements.md).

- The test tone generator feeds every input before its gain and its mute, as one coherent source. Pink noise
  plays 15 dB louder than the sine.
- The input gain is exact.
- On an output the gain comes before the compressor.
- The limiter works on the peak and follows the threshold 1 dB per dB. It holds a 100 Hz signal about 2.2 dB
  higher than a 1 kHz signal of the same level. The output clips at about +12.5 dB on the threshold scale by
  the 100 Hz relation and at about +14.8 dB by the 1 kHz relation.
- At compressor ratio 1:1.0, the factory setting, every second sample gets the output gain twice. With the
  gain A as a factor the level becomes 10 · log10((A² + A⁴) / 2), so every gain other than 0 dB acts more
  strongly than set. -6 dB acts as -8.2 dB, -20 dB as -23.4 dB, +6 dB as +9.7 dB and +12 dB as +19.7 dB. The
  output then also carries the signal mirrored at 24 kHz, at (1 − A) / (1 + A) of it, which is about 10 dB
  below it at -6 dB. At any other ratio the output gain is exact as long as the compressor does not work, so
  Limit or 1:1.1 at +20 dB switches the compressor off.
- Every compressor applies its gain reduction the same way while it works, whatever its ratio and its times.
  The samples alternate between g and g², which mirrors the signal at 24 kHz, 15 dB below it at 3 dB of gain
  reduction and 10 dB below it at 6 dB.
- Gain raw 0 mutes the channel, while the vendor editor shows it as -60 dB. This was measured on an input (IN5,
  2026-09-29). trackdsp treats the outputs and the Aux the same, which is not measured yet.
- The meters tap after the gain and, on the outputs, after the compressor. They tap before the mute, so a muted
  channel keeps its reading. They read like an RMS meter.

### Configuration (1277 bytes via `27`)

| Offset | Size | Content |
|---|---|---|
| 0 | 16 | `00 00` after a factory reset, `FF FF` once a stored preset was loaded, + preset name (14) |
| 16 | 8 × 62 | Inputs |
| 512 | 94 | Aux |
| 606 | 8 × 76 | Outputs |
| 1214 | 63 | Footer |

**Input (62):** 0 name (8) · 8 ? (probably +48V) · 9 Line/Mic · 10 FBQ · 12 gate attack · 14 gate release ·
16 gate threshold · 18 PEQ 5 × (gain u16, freq u16, q, type) · 48 HPF slope · 50 HPF freq · 52 LPF slope ·
54 LPF freq · 56 gain · 58 phase · 60 link

**Output (76):** 0 name · 8 routing mask · 10 HPF slope · 12 HPF freq · 14 LPF slope · 16 LPF freq ·
18 PEQ 7 × 6 · 60 ratio · 61 knee (meaning unmeasured, see `30`) · 62 attack · 64 release · 66 threshold · 68 gain · 70 phase · 72 delay · 74 link

**Aux (94):** 0 name · 8 source mask · 10 effect on/off · 12 effect gain · 16 AutoMix slope · 18 AutoMix (att, rel,
thr, mask, on) · 28 Camera (same) · 38 PEQ 5 × 6 · 68 echo (predelay, delay, repeat, echo) · 76 reverb (predelay,
reflect, time, reverb) · 84 ratio · 85 knee (see `30`) · 86 attack · 88 release · 90 threshold · 92 gain

**Footer (63):** 0 mute mask inputs · 2 Aux mute · 4 mute mask outputs · 6 + 2k band bypass mask input k ·
22 band bypass Aux · 24 + 2k band bypass output k · 40 sub in (gain %, mute, mask, x) · 44 sub out (same) ·
48 test tone mode · 50 test tone frequency · 52 delay unit · 56 EQ bypass mask inputs · 58 EQ bypass mask outputs ·
60 EQ bypass Aux

The mute, band bypass and EQ bypass masks are 16 bit words with one bit per channel or band. Only the low bits
are used: 8 channels for inputs and outputs, bit 0 for the Aux, 5 bands per input and for the Aux, 7 per output.

Every field is checked against the vendor editor display (`tests/Model/DeviceTest.php`). `DeviceState` writes
each command where `Device::parse()` reads it. `tests/Model/DeviceStateTest.php` uploads the demo and the factory
configuration onto blank, `FF` and factory bases and compares every writable bit. An upload does not write these bytes:

- header 0..15 (`FF FF` and the preset name, written only by storing a preset)
- input +8, +11, +59, +61 (+8 is probably +48V)
- output +71, +75
- Aux +13..15, +17
- footer +48 and +50 (test tone mode and frequency, left out of an upload on purpose), +49, +51, +53..55, +62
  and the unused bits of the mask words

The factory reset read-out is `resources/factory_config.bin`.
Group control is read with `6C 00` (8 × gain %, mute, mask).

### Vendor preset file (`.prs`, 1278 bytes)

The vendor editor saves a preset as the configuration without its first two bytes and without footer bytes 48..62.
The layout was first measured on `tests/fixtures/vendor/SdWa5_Default_Preset_3_DSPs.prs`:

| File offset | Size | Content | Configuration offset |
|---|---|---|---|
| 0 | 16 | magic `**8x8MatrixV010*` | – |
| 16 | 14 | preset name | 2 |
| 30 | 8 × 62 | inputs | 16 |
| 526 | 94 | Aux | 512 |
| 620 | 8 × 76 | outputs | 606 |
| 1228 | 48 | footer 0..47 (mutes, band bypass, sub control) | 1214 |
| 1276 | 2 | CR LF | – |

So the file carries neither the test tone, nor the delay unit, nor the EQ bypass. `Device::fromVendorPreset()`
builds the configuration as `FF FF`, file bytes 16..1275 and the factory configuration for the rest, then sets those
three to unknown, so that an upload leaves them on the device as they are. The editor's "Open preset file" takes
such a file as well as a trackdsp JSON file, told apart by the magic. Opening inspects settings independently
of live state; applying them is a separate confirmed action. On 2026-10-03 the user supplied
`files/SdWa5_Default_Preset_3_DSPs_eqbypass_testtone_delayft.prs` after saving with EQ bypass, test tone and feet
selected. Both samples are byte-identical, including their 1278-byte length. This second sample adds no
representation of those settings. On the same day the user reported that reopening offline resets test tone
to its default while EQ bypass and delay unit stay unchanged. This observation concerns the vendor UI,
not device writes. Trackdsp keeps omitted values unknown and does not reset hardware tone during an upload.
Trackdsp does not write `.prs` files. The sample checksum is recorded in `captures/README.md`.

### Still open

- +48V (probably input block byte 8) and working status: the location is not confirmed, the app remembers the value it set.
- `2D`/`2F` (PIN) were not captured on the 8x8 (≈ 4x4). They need a live test with backups and a known recovery path,
  see `TODO.md`.
- Deleting a stored preset: no command is known, a slot can only be overwritten.
- Changing the IP address: the command is unknown (the editor sends it only on a real change).
- The unit of the compressor threshold at the output connector, which a voltmeter on a 100 Hz sine settles,
  and whether the output ceiling of about +12.5 dB on that scale is analog or digital.
- Why a limiter holds a 100 Hz signal about 2.2 dB higher than a 1 kHz signal of the same level.

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

The browser Help dialog builds these serial packets locally, as the vendor's RS232 Remote Control Code
dialog does. It never sends them through USB/TCP. The named builder follows the documented byte order; raw
CMD/data bytes allow inspection of undocumented commands without assigning them guessed meanings. Vendor
Get Full Matrix generates `7B7D01610000007D7B` at ID 1 with no editable parameters, captured in
`tools/shots/rs232_full_matrix.png`. Its response is not inferred from this local builder evidence. The
U21 preset option still needs capture evidence in the parity inventory.

The offline Group builder screenshots show these command and data bytes at ID 1.

| Command | Setup | CMD D1 D2 D3 |
|---|---|---|
| Get Group Parameter | Input1 / Output4 | `63 00 00 00` / `63 07 00 00` |
| Group volume | Input1 0% / Output4 100% | `66 00 00 00` / `66 07 64 00` |
| Group gain step | Input1 Increase / Output4 Decrease | `67 00 00 00` / `67 07 01 00` |
| Group mute | Input1 UnMute / Output4 Mute | `68 00 00 00` / `68 07 01 00` |
| Group matrix | Input1 empty / all | `69 00 00 00` / `69 00 00 FF` |
| Group matrix | Output4 empty / all | `69 07 00 00` / `69 07 00 FF` |
| Group matrix | Input1 only In1 / only In8 | `69 00 00 01` / `69 00 00 80` |
| Group matrix | Output4 only In1 / only In8 | `69 07 00 01` / `69 07 00 80` |

The group choices are Input1..4, then Output1..4. The Group Matrix mask occupies data byte three;
data byte two stays zero. Both group sides display In1..In8 captions. The second batch shows
In1 as bit 0 and In8 as bit 7 on both sides. The local builder offers the sequential eight-bit
checkbox mask and keeps its byte editable.
These describe locally generated serial packets, not measured device replies or USB/TCP commands.
