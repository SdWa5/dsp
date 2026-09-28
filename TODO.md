# TODO

## Rewrite

- Browser editor: check every page and dialog against the device, with a harmless round trip per control
- PHPUnit group `device` with read-only checks and reversible writes over LAN and USB
- Packaging: `trackdsp:serve`, FrankenPHP static binary, installer, desktop entry
- Offline mode like the vendor editor: check whether a preset can be set up in the browser editor without a
  device and handed to the real device later. The bridge target `demo` simulates a device, but the editor
  offers no obvious way to start from it
- Vendor preset files: the file format of the vendor editor is unknown. Capture a file saved by the vendor
  editor in the win11 VM, then decide whether trackdsp reads and writes it
- Remove `legacy/python/` once the rewrite has reached parity

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

- Late start of the board: with the Deco on, keep the VIM3 off (or its Ethernet unplugged) for 10 minutes, then
  switch it on and time how long until `http://10.42.0.1/` answers from the crew wifi. The measured power cuts
  cover a gap of about a minute only, and a DHCP client may wait longer between retries after a long gap.
  Silent, about 15 minutes. Add the result to the power cut table in `docs/vim3.md`
- Vendor editor fallback without the board and the Deco: a cable straight from the laptop to the 8x8, laptop
  profile `nmcli connection add type ethernet con-name 8x8-direct ifname enp0s31f6 ipv4.method manual
  ipv4.addresses 192.168.68.20/22 ipv6.method disabled connection.autoconnect no`. First find out whether
  `192.168.68.10` is set on the 8x8 or is a lease from the Deco (`ping 192.168.68.10` over the direct cable),
  and whether the vendor editor in the win11 VM finds the device through NAT or needs a macvtap adapter on
  `enp0s31f6`. Then document it in `docs/vim3.md` next to the vendor editor session

## Automation

- Verify the gain riders and the auto EQ on the device with the UMC1820 and the measurement mic, on the command
  line and in the browser editor
- Measure the 8x8's PEQ shapes against the RBJ filters the fitter assumes. Include the allpass types and the
  phase of the HPF and LPF types, because the phase step of the system tuning predicts with them
- `arecord` in the ddev web container (alsa-utils and the sound device), so a measurement runs inside ddev and
  the Auto EQ page can record there
- Measure a transfer function instead of the plain pink noise spectrum, which the delay, polarity and phase
  steps of the system tuning need. Decided is a dual-channel FFT of the mic against the device's pink noise,
  looped back from the spare OUT8 into a second input of the UMC1820, rather than a log sweep with
  deconvolution. The impulse response gives the arrival time per output, and the fit plot can show the phase
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
     passband meets the target curve, which also decides how loud the subs play against the tops. If the
     output gain comes after the limiter, the step moves every threshold along with the gain.
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
    8x8's maximum output. Confirm it with a 100 Hz sine from the test tone, the amplifiers off and a true-RMS
    meter on the output.
  - Find out whether the output gain comes before or after the compressor. If it comes after it, every gain
    change moves the real limit, so the tuning has to move the threshold with it.
  - Settle which column holds the output gain. `DSP_Output_Gain_Set_dB` equals the threshold by formula
    (SIG-6 in sdwa5-3d).

## Protocol

- Locate +48V (probably input byte 8) and the working status in the configuration
- Presets: recall F00 (`20 00`) on the device, and find a command that deletes a stored slot. Store, recall and occupancy are confirmed on U20
- PIN: set (`2F`), unlock (`2D`), change and unset, with the lock state from `2C` after every step. Find the recovery path first (manual chapter 6, the 4x4 projects)
- Command for changing the IP address
- Meter dB scale calibration
