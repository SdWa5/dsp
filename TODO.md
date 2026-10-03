# TODO

## First

- Check the clone on the laptop for uncommitted work before touching anything in Automation or related to it.
  Work on the Automation items had already started there, and an auto mode session may have stopped when the
  battery ran empty. Run `git status` and `git log origin/main..` there. If something is left, commit it on a
  feature branch, merge it into `main` and push. Only then pull here and continue

## Repository

- Rename the repository and give it a description. `dsp_linux_8x8` names the first goal, a Linux editor for
  the t.racks 8x8 Matrix, while the project now also measures, rides gains, fits the auto EQ and is to tune a
  whole PA. The GitHub description is empty, and `composer.json` and the README still describe an 8x8 editor
  only. Renaming needs admin rights on the repository, which the account `bestcodename` does not have (push
  and triage, read from the API on 2026-09-30). Settle the new name first, then check what it touches: the
  clone command in the README, the sibling entry in the sdwa5 repository, and whether the project name
  `trackdsp` goes with it, which is also the composer package, the ddev name, `bin/trackdsp` and the
  `trackdsp:` command prefix
- (low priority) Investigate the flaky `PlaybackTest::testAnInterruptBeforeTheRunLeavesTheDeviceAlone`
  (`tests/Measurement/PlaybackTest.php`). It failed once in `composer ci` on 2026-10-03 with a refused
  connection on the scripted bridge socket and passed on the rerun without a change. Find out why the socket
  can be unready, for example by running the test in a loop, and make the test wait for it or fail clearly

## Rewrite

- Browser editor: check every page and dialog against the device, with a harmless round trip per control
- (medium priority) Check that every UI feature of the vendor editor exists in trackdsp in some form. Go through
  its screenshots in `tools/shots/` (`page_*.png` per tab, `dlg_*.png` per dialog, `main.png`) and its control
  dumps in `captures/` (`dialogs.json`, `aux_dialogs.json`, `page_tour.json`), list every control, display and
  menu entry and find its counterpart in the editor or the CLI. A feature without a counterpart becomes an item
  here. On 2026-10-02 this found the curve plots of the Gate and Comp pages, the Limit segment of the output
  meters and the Active lamp of the Aux compressor dialog
  - Complete the screenshots first, since the check can only find what they show. All 22 tabs of
    `page_tour.json` have a `page_*.png`, but of the 34 forms the vendor editor names in `captures/dialogs.json`
    only 13 have a screenshot (counted on 2026-10-03). Five more have a control dump and no screenshot
    (`TfmProcess`, `TfmNetworkSetup`, `TfmPhantomPotent`, `TfmRs232Cmd`, `TfmRs232CmdEnglish`). Sixteen have
    neither (`TfmHidData`, `TfmPeqRst`, `TfmAbout`, `TfmRs232Code`, `TfmRs232CodeEnglish`, `TfmLockPassWord`,
    `TfmLockSetup`, `TfmPtsPassWord`, `TfmPtsSetup`, `TfmInformation`, `TfmExit`, `TfmMemo`, `TfmChannelName`,
    `TfmFactory`, `TfmIdSelect`, `TfmStoreName`). The menu bar and its menus have no screenshot and no dump at
    all, and `msgbox.png` belongs to none of the forms yet. Open each in the vendor editor on the VM
    `win11-lightweight` as for the range survey, take the shot with `virsh -c qemu:///system screenshot
    win11-lightweight tools/shots/dlg_<form>.png`, and note the forms that only open with the device online
- PHPUnit group `device` with read-only checks and reversible writes over LAN and USB
- Packaging: `trackdsp:serve`, FrankenPHP static binary, installer, desktop entry
- Vendor preset files: trackdsp reads `.prs` (docs/protocol.md). Save a second sample in the vendor editor with
  EQ bypass on, a test tone and another delay unit, to confirm that the file leaves them out. Then decide
  whether trackdsp writes `.prs`, and check such a file by loading it in the vendor editor
- Localization of the CLI. The browser editor has English and German catalogues in `translations/`
- Localization of messages returned by the bridge and API, including Auto EQ warnings and recording names.
  Return message codes and parameters so the browser can translate them without changing CLI output
- Remove `legacy/python/` once the rewrite has reached parity
- The meters light their red top at 0 dB, which on the calibrated scale lies 12.5 to 14.8 dB below the output
  ceiling. For programme with a crest factor of 12 to 15 dB that is about where its peaks reach the ceiling,
  while a sine or a limited signal lights it that much too early. Decide where the red top belongs

## Editor

- Let the routing graph on the Matrix page make and unmake connections. A drag from an input or the Aux to an
  output, or from an output to an input, toggles that route as a click on its matrix cell does, so an unset
  route gets set and a set one gets cleared. While dragging, a line follows the pointer from the start node, and
  the node under the pointer lights as the target. A drop on a node of the same column or beside any node does
  nothing. Touch needs the same drag, with pointer capture so the page does not scroll
- Make an active Mute button and a gain turned to the bottom tell apart at a glance. Both light the Mute button
  in the same red (`.toggle--mute.is-on`, `--mute` #ff5252), and since 0.56.0 both turn the gain knob red as
  well, so only the tooltip says which one mutes. That red is also close to the colour of a changed setting
  (`--changed` #c2185b, the mark on a value field), so a red mark alone does not say whether a channel is muted or
  merely differs from the factory value. Give the mute by the gain a look of its own, for example an outlined
  Mute button as the channel tab already has (`.chtab__mute.is-gain`), and move the mute red or the changed colour
  far enough apart in both themes
- Link crossover points between outputs, so that a frequency set on one filter also lands on its partner, for
  example the HighPass of Out1 on the LowPass of Out2. Every pair links the frequency, and optionally the slope
  as well, which keeps the two sides of a crossover the same type and order, for example Linkwitz-Riley 24 on
  both. This is not a link group, because the two outputs keep every other setting of their own, and a link
  group mirrors the HighPass onto the HighPass. The configuration probably has no place for such a pairing, so
  decide where it lives, for example in the bridge next to the gain riders and in the saved preset file. Show
  the pairing on both filters, and decide what happens when one partner is in a link group, where the change
  then reaches every channel of that group. The system tuning (Automation, step 6) needs matching crossover
  filters as well and could set them through such a pair
- (low priority) Proposal: let the look of an idle PEQ point be chosen in the UI. Since 0.63.1 a band point that
  does nothing, and an HPF or LPF square at slope 0, is filled in the mute red on the curve. A red ring around
  the band's own colour would keep the band colour readable. If this is made configurable, it could sit as a
  setting next to the link display options and be kept per browser like them

## Several devices

- Control at least two 8x8 at once. With two connected, the editor shows both control sets side by side, so
  both are controlled at the same time without switching between them. Needs a multi-device bridge (or one
  bridge per device with its own socket) and a device key in the API and the event stream
- Stable USB names per physical port through a udev symlink on `ENV{ID_PATH}` (e.g. `/dev/trackdsp-rack1`),
  since the units share VID/PID and `hidrawN` follows the plug order
- VIM3: the second 8x8 goes on the second USB port of the board, a third device on the Deco LAN port over TCP
  (port plan in `docs/vim3.md`). One bridge unit per device as a template (`trackdsp-bridge@rack1.service`)
  with its own socket and target, `usb:/dev/trackdsp-rack1` for USB and the fixed address for the network device

## VIM3

- JIT on the board with the next deployment. Set `opcache.jit=off` and `opcache.jit_buffer_size=64M` for the
  CLI (`/etc/php/8.4/cli/conf.d/`) as in `.ddev/php/jit.ini`, so that `trackdsp:measure` and `trackdsp:autoeq`
  can switch the JIT on for their FFTs. Check that the JIT is available on ARM64 and time a transfer function
  with and without it. For PHP-FPM, whose opcache outlives the request, try `opcache.jit=1235` directly, since
  the Auto EQ page runs its analysis there. `opcache.jit=function` compiles every function of every process at
  load, which made the test suite in ddev about 2.5 times slower and let some tests time out, so it is not an
  option
- Find out whether the Deco's WAN port is fixed. Its ports are marked 1 and 2 only, which suggests auto-sensing,
  where the Deco takes the port that gets a DHCP lease as WAN. Check in the Deco app which port is the internet
  connection, then plug the VIM3 into the other port and see whether path B (`http://10.42.0.1/`) still comes
  up. If the WAN port is fixed, label it on the Deco. If it is not, drop the "WAN port only" warning in
  `docs/vim3.md`, since then the board serves the Deco on either port
- Late start of the board: with the Deco on, keep the VIM3 off (or its Ethernet unplugged) for 10 minutes, then
  switch it on and time how long until `http://10.42.0.1/` answers from the crew wifi. The measured power cuts
  cover a gap of about a minute only, and a DHCP client may wait longer between retries after a long gap.
  Silent, about 15 minutes. Add the result to the power cut table in `docs/vim3.md`. Path A does not depend on
  it, the wifi power cuts of 2026-09-28 are measured
- Vendor editor fallback without the board and the Deco: a cable straight from the laptop to the 8x8, laptop
  profile `nmcli connection add type ethernet con-name 8x8-direct ifname enp0s31f6 ipv4.method manual
  ipv4.addresses 192.168.68.20/22 ipv6.method disabled connection.autoconnect no`. First find out whether
  `192.168.68.10` is set on the 8x8 or is a lease from the Deco (`ping 192.168.68.10` over the direct cable),
  and whether the vendor editor in the win11 VM finds the device through NAT or needs a macvtap adapter on
  `enp0s31f6`. Then document it in `docs/vim3.md` next to the vendor editor session

## Automation

- The rack's output panel has a broken connection on output 8 (found 2026-09-29). Until it is repaired, measure
  with `--reference-output=out6`. Afterwards repeat B1 of `docs/measurements.md` against Out8
- Measure the 8x8's PEQ shapes against the RBJ filters the fitter assumes. Include the allpass types and the
  phase of the HPF and LPF types, because the phase step of the system tuning predicts with them
- The PEQ fitter wastes bands on overlapping corrections. On Tecnare L (2026-09-29) it cut 477 Hz by 9 dB
  with a Q of 1.9 and then spent three bands at 388 to 397 Hz boosting the cut's flank back up, so the dips at
  5 and 8 kHz stayed uncorrected. The coordinate descent moves one parameter of one band at a time and gets
  stuck where a narrower cut would only pay off together with dropping the boosts. Try removing each band and
  refining the rest, drop a band that barely helps, and move frequency and Q together. Needs a test with a
  synthetic curve of one peak and two dips. Do it after the PEQ shapes are measured, since those may change
  the model, and before the system tuning, which needs free bands for the allpass and the system EQ
- Show the phase of the transfer function in the fit plot of the Auto EQ page
- Auto EQ with two mics: record both at once (`arecord -c 2`, one channel per mic) and power-average them, or
  give each mic its own outputs (left and right side). Today every recording reads one mic channel, and
  several positions are averaged one after another
- System tuning in steps, the way a system engineer tunes a PA, in the browser editor and on the command line.
  Today the auto EQ fits one set of bands to everything that plays and writes it to every chosen output. It
  leaves the output gain alone on purpose and knows no delay, polarity or phase. A tuning session works on speaker
  groups instead. A group is one or more outputs, by default an output link group of the 8x8 or a group of the
  rig import. Cabinets that share an output also share its settings. The session plays one group at a time
  and mutes the other outputs meanwhile, the way the auto EQ bypasses its bands today. Every step backs up the
  device, shows its result against the target curve, writes only after a confirmation and ends with a verify
  measurement.
  1. Check every output alone at a low level, so that a dead driver, a swapped cable or an inverted polarity
     shows up before anything is aligned.
  2. Start from the output gains of the rig import, or from the current ones.
  3. EQ each group on its own within its passband.
  4. Set the relative level between the groups. Each group's output gain is set so that its level within its
     passband meets the target curve, which also decides how loud the subs play against the tops. The output
     gain comes before the limiter, so the thresholds stay where they are. A tuned output needs its compressor
     switched off at Limit or 1:1.1 from +20 dB rather than at ratio 1:1.0, where the 8x8 applies the gain more
     strongly than set ([docs/measurements.md](docs/measurements.md)).
  5. Align delay and polarity between the groups. The transfer function gives the arrival times, and the delay
     and polarity of the later group are chosen so that the groups add up in their crossover region.
  6. Correct the phase between the groups. A delay shifts the phase evenly over frequency, so where the phase
     curves of two groups differ in shape, it matches them only near one frequency. The phase that still
     differs across the crossover region is matched with allpass bands (PEQ types Allpass1 and Allpass2) on
     one of the groups. Matching crossover filters come first, for example Linkwitz-Riley of the same order on
     both sides, because the HPF and LPF types set a large part of the phase there.
  7. EQ the whole system with every group playing, averaged over the positions.

  Steps 5 and 6 predict their choice from the solo measurements of the groups and then verify it with the
  groups playing together. The group EQ comes before the level, the alignment and the phase on purpose. EQ
  changes the level within its band and the phase near the crossover, so a level, a delay or an allpass set
  before it would have to be set again. Where the system EQ of step 7 lives is still open. On the outputs, the
  seven bands of an output have to hold the group EQ, the allpass bands and the system EQ, which gets tight.
  The input PEQ has to match on every input that feeds the PA, and it also colours every other output those
  inputs feed, for example a recording or a video feed. So the output bands stay the better place, with the
  band split set per group.

## Rig import

- Take the limiters and the output gains from the audio routing table (`Audio Routing.xlsx`) instead of typing
  them into the editor. The first step covers the limiter threshold in dBu, the ratio, the attack and the
  release per 8x8 output (sheet `AmpLimiterCalc`), and the output gains, which the system tuning starts from.
  Delays, polarity, the routing matrix and the channel links can follow from the same source later. Every
  piece of data lives where it makes sense. The speakers, the amplifiers and the limiter arithmetic belong to
  the sdwa5-3d repository, which already has `src/Signal/LimiterSetting.php` and plans to take over the
  workbook (SIG-1 and SIG-3 there). The DSP settings and the corrections the system tuning measures belong to
  trackdsp and its preset files. So trackdsp imports the computed values that sdwa5-3d exports rather than
  parsing the workbook, whose Drive folder holds several diverging copies of the limiter sheet. Three things
  need settling before the first import.
  - The sheet assumes that the 8x8's compressor threshold means dBu at the output connector, with 18 dBu as the
    8x8's maximum output. The loopback measurements show that the limiter follows the threshold 1 dB per dB
    and that the output clips at about +12.5 dB on that scale by the 100 Hz relation, or at about +14.8 dB by
    the 1 kHz relation, since a limiter holds a 100 Hz signal about 2.2 dB higher
    ([docs/measurements.md](docs/measurements.md)). Whether the scale is dBu needs a 100 Hz sine from the test
    tone, the amplifiers off and a true-RMS meter on the output.
  - Settle which column holds the output gain. `DSP_Output_Gain_Set_dB` equals the threshold by formula
    (SIG-6 in sdwa5-3d).

## Protocol

- Widen the AutoMix and Camera attack and release faders to 5000 ms and both effect predelays to 250 ms, as the
  vendor editor has them (`tools/shots/ranges/automix_max.png`, `camera_max.png`, `effect_max.png`). No capture
  sends those raw values yet. On the device, send AutoMix (`64`, channel `12`) with attack and release raw 4999 and
  the echo and reverb predelays raw 250, then read the configuration back. Both values held make the faders wider
- Find the reverb time scale. The vendor editor shows 0.1 s at the bottom, 1.96 s at raw 139 and 4.00 s at the
  top (`tools/shots/ranges/effect_min.png`, `effect.png`, `effect_max.png`), while `raw × 1.96 / 139` gives 0 s
  and 3.60 s. Capture the vendor editor online while Time goes to its maximum and its minimum, and read the raw
  values of `60`
- Mic sensitivity: the vendor editor sets 0..50 dB in 1 dB steps (`tools/shots/ranges/gain_input.png`), this
  editor offers Mic 0 dB, +1 dB and +2 dB as modes 1..3. Send `55 00 33` on the device and read input byte 9
  back, expecting `33`, with about 50 dB more gain on a mic than at mode 1. Then replace the three modes with Line
  plus a 0..50 dB sensitivity control
- Find which band frequencies the vendor editor's EQ Reset sends on the Aux. Its offline preset shows the input
  layout (61.1 Hz, 2.00 kHz, 7.13 kHz, `tools/shots/ranges/aux.png`), as this editor's EQ Reset does, while the
  device's factory Aux holds 50.8 Hz, 203.1 Hz, 500 Hz, 2.00 kHz and 10.08 kHz. Capture the vendor editor online
  pressing EQ Reset on the Aux page
- Measure what byte 61 (Aux 85), called knee since the first Python editor, does. The vendor editor has no
  control for it and sends 0, so the editor and the CLI no longer offer it. On the Out7 to IN5 loopback, set ratio
  4:1 from -20 dB and run the level sweep through the threshold of the B6 method in `docs/measurements.md` with
  the byte at 0, 12 and 255. Compare the static curves near the threshold. A softer onset that grows with the
  byte makes it a knee, and then the editor gets the control back with the measured unit
- Confirm byte 51 of the meter reply on the device, which lights the Limit lamp. Set Out1 to Limit from 0 dB,
  play a 1 kHz test tone, raise the Out1 gain and watch `bin/trackdsp meters --raw`. Bit 0 should turn on once the
  level reaches the threshold and hold about 3.3 s after it falls. Repeat on Out3 to Out8 and on the Aux, which has
  no bit in the captures. Find out whether the `FF FF` behind it ever changes
- Find what lights the vendor editor's Limit segment and the "Active" LED of its Aux compressor dialog. Run the
  vendor editor against the device with the test above and watch both
- Locate +48V (probably input byte 8) and the working status in the configuration
- Presets: recall F00 (`20 00`) on the device, and find a command that deletes a stored slot. Store, recall and occupancy are confirmed on U20
- PIN: set (`2F`), unlock (`2D`), change and unset, with the lock state from `2C` after every step. Find the recovery path first (manual chapter 6, the 4x4 projects)
- Command for changing the IP address
- Check on the device that gain raw 0 mutes an output and the Aux, as it mutes an input. The editor and
  `trackdsp:status` already show all three as muted
- Check on the device whether EQ bypass (`3C`) also takes out the HPF and LPF. Set the HPF of one output to 1 kHz,
  LK -48, switch EQ bypass on, play pink noise and record the output (Auto EQ step 2 or `arecord`). Energy below
  1 kHz coming back means it does. `response()` in `assets/lib/eq.js` assumes it does not. If it does, the curve
  drops the filters under EQ bypass, and the HighPass and LowPass Bypass buttons show red with it, like the bands
  do through `bandBypassed()`
