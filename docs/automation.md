# Gain rider, DJ compressor and auto EQ

Three console commands take routine level and EQ work off the operator. The gain rider and the auto EQ work
through a running bridge (`trackdsp:bridge`), so the browser editor stays usable at the same time. The
compressor preset talks to the device directly.

## Gain rider: `trackdsp:ride`

```bash
bin/console trackdsp:ride in1                              # ride In1 towards -18 dB
bin/console trackdsp:ride in1 --level=-20 --min=-12 --max=0
bin/console trackdsp:ride in1 --dry-run --duration=60      # only print what it would do
```

The rider listens to the bridge's `meters` events and adjusts the input gain of one channel so that its long
term level stays at the target. It is meant for a DJ input whose level changes from track to track, not for
fast dynamics. That is the compressor's job.

| Option | Default | Meaning |
|---|---|---|
| `--level` | -18 | target level in meter dB |
| `--min`, `--max` | -20, 0 | gain window in dB. It never leaves the window, and a gain outside it only moves back in |
| `--allow-boost` | off | needed for `--max` above 0 dB |
| `--slew` | 0.5 | largest change in dB per second |
| `--average` | 5 | averaging time in seconds (power average) |
| `--deadband` | 1 | no change while the average is this close to the target |
| `--silence` | -45 | below this level the gain is frozen, so a pause between tracks does not pull it up |
| `--hold` | 30 | seconds the rider pauses after somebody else changed the gain |
| `--duration` | 0 | stop after this many seconds, 0 runs until Ctrl+C |
| `--dry-run` | off | print the changes, write nothing |

A gain change that did not come from the rider (browser editor, vendor editor, another command) counts as a
manual override. The rider adopts the new gain and pauses for `--hold` seconds. The device meters show the
level after the gain, and the rider compensates its average for every step it writes.

## DJ compressor: `trackdsp:compressor`

```bash
bin/console trackdsp:compressor out1,out2 --dj                   # 1:4 from -6 dB, 10 ms, 200 ms, hard knee
bin/console trackdsp:compressor out1,out2 --dj --threshold=-10
bin/console trackdsp:compressor aux --ratio=limit --threshold=-3
```

The 8x8 has compressors on the outputs and the Aux only. `--dj` sets a default that catches peaks of a DJ
mix without pumping. Every value can be given on its own and overrides the default: `--ratio` (`4`, `1:4` or
`limit`), `--threshold` (-90..20 dB), `--attack` and `--release` in ms, `--knee` (0..255).

## Auto EQ: `trackdsp:autoeq`

```bash
bin/console trackdsp:autoeq out1,out2 --device=plughw:CARD=UMC1820 --mic-channel=1 --positions=3
bin/console trackdsp:autoeq out1,out2 --cal=ecm8000.txt --target=tilt:-1 --apply
bin/console trackdsp:autoeq out1,out2 --wav=var/autoeq/measure-20260927-120000-1.wav
bin/console trackdsp:autoeq out1,out2 --revert=var/autoeq/backup-20260927-120000.json
```

The auto EQ is supervised and runs before the show, not during it. One run does this:

1. Saves the device state as a preset file `backup-<time>.json` in `--dir` (default `var/autoeq`).
2. Bypasses the PEQ bands it owns on the chosen outputs (bands 1 to `--bands`).
3. Starts the device's pink noise on every routed output. Keep the level safe before starting.
4. Records the measurement mic with `arecord` for `--seconds` at each of `--positions` positions. With more
   than one position it asks before each recording.
5. Switches the test tone back, analyses the recordings and averages them.
6. Fits peaking bands to the target and prints them with the deviation before and after.
7. Writes the bands only with `--apply` or after a confirmation, whose default is no. Unused owned bands are
   set flat and bypassed. Without a write, the original bands are restored.

The tone and the bands are restored whatever goes wrong, also on Ctrl+C. `--revert` restores the PEQ of the
outputs from a backup. `--wav` analyses existing recordings instead of measuring and leaves the device
untouched until the write.

### Measurement

- A mono measurement mic on an input of the interface, `--mic-channel` counts from 1. `--device` is the ALSA
  capture device, `arecord -l` lists them. Recordings are 48 kHz, 32 bit.
- `--cal` reads a calibration file with "frequency dB" lines (the usual format of mic calibration files,
  header lines are skipped). Without it the mic is taken as flat.
- The analysis is a Welch power spectrum (16384-point FFT, Hann window, 50 % overlap) summed into
  1/6-octave bands on a 24-points-per-octave grid from 20 Hz to 20 kHz. Pink noise reads flat.
- Several positions are averaged in the power domain, which evens out room modes at single spots.
- `arecord` is not in the ddev container. Run a real measurement on the host or on the machine next to the
  device.

### Fit

| Option | Default | Meaning |
|---|---|---|
| `--target` | flat | `flat`, `tilt:<dB per octave>` (pivot 1 kHz) or `file:<path>` with "frequency dB" lines |
| `--low`, `--high` | 60, 16000 | range to correct in Hz |
| `--bands` | 7 | bands to use, from band 1 up |
| `--max-boost` | 3 | largest total boost at any frequency in dB |
| `--max-cut` | 9 | deepest cut of one band in dB |
| `--max-q` | 5 | narrowest cut. Boosts are never narrower than Q 2 |
| `--tolerance` | 1 | deviations up to this size are left alone |

Only the shape counts. The measurement is shifted to the target's mean level first, and the output gain is
not touched. Every new band goes to the largest remaining deviation, with its width taken from where the
deviation falls to half. After each band all bands are refined in raw device steps (1/30 octave, 0.1 dB, one
Q step), so nothing is lost when they are written.

Peaks are cut as measured. A dip counts only as far as it survives 1-octave smoothing. Narrow dips are
usually room cancellations that more power cannot fill, so boosting them wastes headroom. The filter model
is the RBJ peaking biquad at 48 kHz. The 8x8's own filter shape is unconfirmed, so measure again after
writing to verify.
