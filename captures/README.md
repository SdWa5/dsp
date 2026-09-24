# Captures and test data

Raw data from decoding the protocol (2026-09-24, device "8x8Matrix V010", preset U02 "Bulgaria26").

The PHP test suite reads every capture directly (`tests/Support/UsbPcapReader.php`,
`tests/Protocol/CaptureReplayTest.php`). The Python tools of the first implementation still work too:

```bash
python legacy/python/analyze_capture.py captures/<name>.pcap      # all HID reports
python legacy/python/tools/cap.py show <name>                     # commands only (no meter polling, no ACK)
```

## USB captures (`*.pcap`, USBPcap)

| Prefix | Content |
|---|---|
| `dsp_session1` | first manual capture: connect, gain In1, mute, routing Out3 (+ test tone `39 02 00`) |
| `t00`, `t99`, `t50`–`t62` | connection setup / full readout, page tour, idle |
| `t01`–`t04`, `a*` | Out1: mute, gain scale, phase, PEQ, HPF/LPF |
| `b*`, `c*` | Gain page: phase, Line/Mic + sensitivity, +48V |
| `d*` | In1 page: FBQ, input PEQ, input filters |
| `e*`, `l*`, `m*` | Aux: AutoMix, effect (echo/reverb), camera, source selection, compressor, dialogs |
| `f*`, `g*` | Gate, compressor, delay + unit, EQ bypass, PEQ types |
| `h*`, `i*`, `j*`, `k*` | Menu dialogs: test tone, channel name, sub/group control, link, ID/IP, working status, presets |
| `n*`, `o*` | Mapping state → configuration byte (with a TCP readout before and after) |
| `p*` | Channel link Out7+Out8 (`2A`, `3B`, `12`) |
| `fix_*` | Resets after tests |

## Other files

| File | Content |
|---|---|
| `config_tcp.bin`, `config_usb.bin` | complete configuration (1277 bytes) over TCP and USB, reference state |
| `results.jsonl` | per test: the commands sent (from `legacy/python/tools/runner.py`) |
| `probes.jsonl`, `state_map.json` | tests with a configuration diff: which byte changes, whether it was restored |
| `session1_decoded.txt` | readable hex dump of `dsp_session1` |
| `page_tour.json` | visible control values of every editor tab |
| `dialogs.json`, `dialogs_open.json`, `aux_dialogs.json`, `editor_hidden_windows.txt` | structure of the editor dialogs (controls, choice lists) |

Screenshots of the vendor editor and of the Python app are in `tools/shots/` (`page_*` = editor tabs,
`dlg_*` = editor dialogs, `app/` = Python app, `app/linux_*` = on Linux).

The manual is not included (copyrighted):
<https://images.thomann.de/pics/atg/atgdata/document/manual/490507_c_490507_r1_en_online.pdf>
