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

## Vendor preset sample comparison

On 2026-10-03 the user supplied `files/SdWa5_Default_Preset_3_DSPs_eqbypass_testtone_delayft.prs`
after saving with EQ bypass, test tone and feet selected. It is byte-identical to
`tests/fixtures/vendor/SdWa5_Default_Preset_3_DSPs.prs` and `files/SdWa5_Default_Preset_3_DSPs.prs`.
Each has 1278 bytes, the same vendor magic and CR LF terminator. Their SHA-256 is
`3c7a2662d19170063ec090183a01b1b58ef50cab0f31620a27b4c267906badac`.
The existing importer accepts the second sample and returns unknown tone mode, delay unit and EQ bypass
for all 17 channels. No duplicate test fixture is needed because the bytes match the existing fixture.
On 2026-10-03 the user reported that reopening offline resets test tone to its default while EQ bypass and
delay unit stay unchanged. This establishes vendor UI behavior, not device writes or the exact tone fields
reset. The user-owned files under `files/` remain unchanged.

## Parity survey

The observed vendor surfaces and their sdwa5-dsp counterparts are indexed in
[../docs/vendor-parity.md](../docs/vendor-parity.md). Original tab screenshots depict a working online preset,
not factory defaults. Range screenshots in `tools/shots/ranges/` use the user-controlled default/max/min
procedure. New `menu_*.png` and named-form shots were captured from the offline `win11-lightweight` VM
on 2026-10-03. The inventory records their entry paths and missing forms. A screenshot proves visible
controls and UI values, not device write acceptance.

### Documented vendor language selection

On 2026-10-03 the official [English manual, revision 21.09.2020](https://images.thomann.de/pics/atg/atgdata/document/manual/490507_c_490507_r1_en_online.pdf#page=33)
was checked. Page 33 describes a Language menu selecting English or Chinese. The captured VM header
in `tools/shots/menu_Help.png` has no such entry. The control dump contains both English and Chinese serial
forms, but their entry paths remain unknown. This establishes a discrepancy between documentation and the
observed build; it does not establish whether changing Windows language exposes those forms.
Thomann's product download listing advertises Software V1.0. No separately labelled Chinese edition was
found in the checked listing. No VM language, system locale or installed software was changed.

### Packaged menu visibility

The official V1.0 installer was downloaded and unpacked without running it on 2026-10-03. The selected
`TFMMAIN` menu properties, resource offsets, exact property bytes and checksums are recorded in
[vendor_menu_resources.json](vendor_menu_resources.json). `LanguageSel` has `Visible=false`; its Simplified
Chinese and English children bind to `ChineseSimplifiedClick` and `EnglishClick`. The Traditional Chinese
child is also hidden. `PtsSetup` and `MemoShow` have hidden default entries and named click handlers.
The user also reported that the installer offers no language selector. The package contains both language
variants, so their absence from the visible UI does not establish that a second installer is required.
Static code inspection found that `FormCreate` explicitly selects English, while both language handlers
implement caption switching. The main form has no serialized menu shortcuts or global keyboard/message
events. Its direct menu-visibility change enables `NetworkSetup`, not `LanguageSel`. No built-in language
override was found in the inspected startup and language paths; indirect overrides remain unverified.

The vendor-documented route is the main-menu Language entry followed by English or Chinese. That
entry is unavailable in the inspected V1.0 UI, and no supported alternative was found in the checked
code paths or documentation. Dormant translation handlers do not establish a usable vendor feature.
The audit does not treat them as a confirmed sdwa5-dsp gap or request Chinese screenshots without a
normal entry path. The earlier in-memory resource-change experiment remains historical evidence in
the JSON. Binary patching is outside the vendor-intended audit and is not a planned verification step.
The installed VM binary identity and indirect runtime paths remain unverified. A different conclusion
requires a vendor-documented procedure or a reproduced supported entry in an unmodified editor.
No executable was modified or run, no Windows language was changed and no device was accessed.

Further analysis found one language-neutral `TFMMAIN` resource and an application caption selector
that chooses translation memos from its own language field. Startup assigns English to that field.
The inspected direct locale/INI/registry calls belong to bundled runtime/component code; no supported
application language argument was found. This strengthens the current-build conclusion but does not
exclude indirect behavior. The user reported that Windows is now Chinese while the editor remains English. This supports the
static finding but is scoped to that setup; editor restart and binary identity were not independently
verified. The legacy non-Unicode system locale remains a separate optional test. Reinstalling the same
inspected package is unlikely to change its explicit startup English selection.

### Windows screenshot batch from 2026-10-03

The user captured 22 images with Windows + Print Screen and copied them to Downloads/tmp/shots.
The imported files below are byte-identical copies. All show Offline mode. File choosers were inspected
without completing their actions. The displayed F00 preset and deliberately changed comparison EQ are
UI setup, not device-default evidence. Screenshots 2 and 3 repeat 1; screenshot 19 repeats the Gain
view in 17. Those originals remain in the source folder and were not imported again.

| Original screenshot number | Repository filename |
|---|---|
| 1 | `rs232_group_mute_max.png` |
| 4 | `rs232_group_unmute_min.png` |
| 5 | `rs232_group_matrix_min.png` |
| 6 | `rs232_group_choices.png` |
| 7 | `rs232_group_matrix_output_empty.png` |
| 8 | `rs232_group_matrix_output_all.png` |
| 9 | `rs232_group_matrix_input_all.png` |
| 10 | `rs232_group_volume_min.png` |
| 11 | `rs232_group_gain_increase.png` |
| 12 | `file_open.png` |
| 13 | `peq_siblings_output_off.png` |
| 14 | `file_save.png` |
| 15 | `peq_siblings_input_on.png` |
| 16 | `peq_siblings_output_on.png` |
| 17 | `gain_current.png` |
| 18 | `dlg_TfmSubCtrl_current.png` |
| 20 | `dlg_TfmGroupCtrl_current.png` |
| 21 | `matrix_current.png` |
| 22 | `dlg_TfmChannelName.png` |

The folder path and the folder list in `file_open.png` and `file_save.png` are blanked, while the dialog title and
the `*.prs` filter are kept.

The Channel Name popup is shown over Matrix in screenshot 22. Its opening gesture was not recorded.
Group Matrix screenshots establish empty/all masks and their byte position. The second batch below
adds single-channel endpoint selections, generated locally at ID 1. All four copies were checksum-verified.

| Original screenshot number | Repository filename | Group / selection | CMD D1 D2 D3 |
|---|---|---|---|
| 23 | `rs232_group_matrix_input_in1.png` | Input1 / In1 | `69 00 00 01` |
| 24 | `rs232_group_matrix_input_in8.png` | Input1 / In8 | `69 00 00 80` |
| 25 | `rs232_group_matrix_output_in1.png` | Output4 / In1 | `69 07 00 01` |
| 26 | `rs232_group_matrix_output_in8.png` | Output4 / In8 | `69 07 00 80` |

The next offline batch shows the preset-name popup in both workflows. Screenshot 27 is imported as
`preset_name_recall.png` and shows U01 over Recall Preset. Screenshot 28 is imported as
`preset_name_store.png` and shows U02 over Store Preset. Both copies were checksum-verified. The visible
role maps to TfmStoreName; its runtime class and the precise opening gesture were not recorded.

A virsh retake is saved as `msg_factory_no_device_current.png`. File > Factory Default while Offline
shows Information, No device connected !! and OK. The role matches the generic Information message;
the TfmInformation runtime class remains unverified. Both the original and retake are attached to that
candidate inventory entry, which retains a read-only class-identification follow-up.
