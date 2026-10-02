# Gain rider, compressor, auto EQ and measurements

Three tools take routine level and EQ work off the operator. Each of them works from the browser editor and
from the command line. The gain riders run inside the bridge (`trackdsp:bridge`). The auto EQ writes through
the bridge, so the browser editor stays usable at the same time. The compressor command talks to the device
directly. A fourth command, `trackdsp:measure`, checks outputs one at a time with the test tone and prints
levels and third-octave bands. It runs on the command line only.

## Gain rider

In the browser, the Gain page has a **Rider** button under every input. It opens the settings, and **Start
rider** hands them to the bridge. A line under the button shows what the rider does right now, for example
"Riding -3.4 dB", "At its limit", "Silent, holding" or "Paused after a manual change". **Stop rider** leaves the
gain where it is.

A rider on a linked input rides its whole link group as one. It takes the power mean of the members' meters,
so a stereo pair with panned material keeps its balance, and writes the same gain to every member. A member
whose gain differs when the rider starts is set to the rider's gain. A change to any member's gain by somebody
else pauses the rider, and once the pause ends the other members follow that gain.

The rider is one for the whole group. Starting, changing or stopping it on any member does so for every member,
which all show **Rider on** with the same settings, and the dialog title names the group ("Gain rider · In3 +
In4"). The rider on the lowest input rides, and the others stand by, so they never take each other's writes for
manual changes. Inputs linked after their riders started take the settings of the rider on the lowest input, and
a member without a rider gets one. Unlinked again, every member keeps riding on its own with those settings. The
status line of the leading rider names its members ("Riding -3.4 dB · In3+In4"), and every other member reads
"Ridden with In3".

Riders belong to a preset. Storing a user preset keeps the running riders for its slot, and recalling it brings
back exactly those, which take the preset's gains as they come. A slot stored without riders, or stored before
0.42.0, recalls with none, and so does the factory default (F00), so the factory gains stay as they are. The
bridge keeps the riders of every slot in its riders file next to the running ones, so they survive a restart.
A preset stored in the vendor editor or on the front panel carries no riders.

A preset file carries its riders as well. Save preset file writes the running riders into the JSON, and Open preset
file replaces the running riders with the ones in the file. A file without riders, a vendor `.prs` file or one
saved before 0.43.0, stops them.

```bash
bin/console trackdsp:ride in1                              # ride In1 towards -18 dB
bin/console trackdsp:ride in1 --level=-20 --min=-12 --max=0   # a second call changes the settings
bin/console trackdsp:ride --list                           # running riders with gain, level and status
bin/console trackdsp:ride in1 --stop
```

The rider reads the meters and adjusts the input gain of one channel so that its long term level stays at the
target. It is meant for a DJ input whose level changes from track to track, not for fast dynamics. That is the
compressor's job.

The riders run in the bridge, so they keep working without a browser and after the command has ended. The
bridge polls the meters while a rider runs, also without a client. It keeps the settings in
`<socket>.riders.json` (`var/bridge.riders.json` by default, `--riders-file` changes it) and starts the riders
again after a restart. The auto EQ and `trackdsp:measure` pause the riders while they record and start them
again afterwards.

| Option | Browser field | Default | Meaning |
|---|---|---|---|
| `--level` | Target level | -18 | target level in meter dB |
| `--min`, `--max` | Lowest gain, Highest gain | -20, 0 | gain window in dB, from -59.5 dB, since -60 dB is gain raw 0 and mutes the channel. It never leaves the window, and a gain outside it only moves back in |
| `--allow-boost` | Allow a highest gain above 0 dB | off | needed for `--max` above 0 dB |
| `--slew` | Fastest change | 0.5 | largest change in dB per second |
| `--average` | Averaging time | 5 | averaging time in seconds (power average) |
| `--deadband` | Leave alone within | 1 | no change while the average is this close to the target |
| `--silence` | Hold below | -45 | below this level before the gain the gain is frozen, so a pause between tracks does not pull it up |
| `--hold` | Pause after a manual change | 30 | seconds the rider pauses after somebody else changed the gain |

A gain change that did not come from the rider (browser editor, vendor editor, another command) counts as a
manual override. The rider adopts the new gain and pauses for the hold time. The device meters show the level
after the gain, and the rider compensates its average for every step it writes. For the same reason it judges
silence on the level before the gain, which is the meter level minus the gain. A quiet track after a loud one
therefore gets raised, even where the gain the loud track left behind puts its meter level below the silence
level. A meter at its floor counts as silence at any gain, since gain raw 0 reads the same as no signal. A muted
input is no pause. Its meter still shows the level, because the mute acts after it, so the rider keeps the
gain right for the moment the input is unmuted.

## Compressor: `trackdsp:compressor`

```bash
bin/console trackdsp:compressor out1,out2 --ratio=4 --threshold=-6 --attack=10 --release=200
bin/console trackdsp:compressor out1,out2 --threshold=-10
bin/console trackdsp:compressor aux --ratio=limit --threshold=-3
```

The 8x8 has compressors on the outputs and the Aux only. The command sets one or more values on several
compressors at once: `--ratio` (`4`, `1:4` or `limit`), `--threshold` (-90..20 dB), `--attack` and `--release`
in ms. At least one value must be given. Every value left out keeps what the device holds for that compressor,
so `--threshold=-10` changes only the threshold. The byte called knee has no option and always keeps the device's
value, because the vendor editor has no control for it and its meaning is unmeasured.

At ratio 1:1.0, the factory setting, the 8x8 applies every output gain but 0 dB more strongly than set and adds
the spectrum mirrored at 24 kHz (see [Solo](#solo)). The browser editor flags such an output on its strip on the
Comp page, with two buttons that set 1:1.1 from +20 dB. That never compresses below the output ceiling and keeps
the gain exact. The other compressor values stay as they are. Each button names the gain it sets, for example
"Exact gain (-6.0dB)" and "Keep level (-8.0dB)" for an output at -6 dB. With **Keep threshold** ticked, the
buttons set only 1:1.1 and leave the threshold where it is, so levels above it get compressed slightly. The
browser keeps that choice for every output.

- **Exact gain** keeps the gain value, so the output now plays at the level its fader shows. An output set to
  -6 dB that played at -8.2 dB gets 2.2 dB louder.
- **Keep level** first moves the gain to the level the output had at 1:1.0, from the model
  10 · log10((A² + A⁴) / 2) of [measurements.md](measurements.md#output-gain-at-compressor-ratio-110). The model
  lies within 0.5 dB of the measurement up to +9 dB set. Above about +6 dB set that level lies beyond +12 dB, and
  the gain stops at +12 dB. The button then says how far the output falls short, for example "Keep level
  (+12.0dB, 9.3 dB short)" for an output at +12 dB, whose level at 1:1.0 the model puts at +21.3 dB. A muted
  gain stays muted.

On a linked output both buttons fix every output of the link group.

A gain that differs only in its value cannot fix 1:1.0 on its own, because the 8x8 also mirrors the signal at
24 kHz there, and that image is only gone at exactly 0 dB.

## Auto EQ

The auto EQ runs from the **Auto EQ** page of the browser editor or from `trackdsp:autoeq`. Both do the same
measurement and the same fit, described below.

### In the browser

The page leads through four steps. Every step is one request to `/api/autoeq`, and the session lives in
`var/autoeq/session.json`, so a reload of the page loses nothing.

Start and every recording answer with `warnings` about the chosen outputs, which the page lists above the steps
until the next answer. An output at compressor ratio 1:1.0 with a gain other than 0 dB is warned about, because
the fit would try to correct its exaggerated gain and its image above 24 kHz. `trackdsp:autoeq` prints the same
warnings before it measures. Neither refuses to measure.

1. **Setup.** Choose the outputs, the capture device (preselected from `TRACKDSP_CAPTURE_DEVICE`), the mic
   input, the seconds per position, the noise before each recording and the bands to use. Starting saves a
   backup of the device.
2. **Measure.** **Record position** plays the pink noise, records and analyses one position. The recording
   runs on the server, so `arecord`, the interface and the mic belong to the machine the web server runs on.
   **Upload WAV** takes a recording made elsewhere instead. **Load** reads a mic calibration file.
3. **Fit.** Set the target (flat or a tilt) and the limits, then **Fit**. The plot shows the measurement,
   the target, the EQ and the predicted result, the table the bands. Fitting again with other limits needs no
   new measurement.
4. **Apply and verify.** **Apply** writes the bands. **Record verify position** then measures with the bands
   on, and the next fit shows it as the verified curve next to the prediction. **Revert to backup** restores
   the PEQ of the outputs, **End session** deletes the recordings. Backups stay and are listed at the end of
   the page with a Revert button each.

A target file (`file:`) is available on the command line only, so the web page cannot read files of the
server.

| Method | Path | Body | Does |
|---|---|---|---|
| GET | `/api/autoeq` | – | `{session, recorder, devices, device, backups}`: `recorder` is false without `arecord`, `device` is `TRACKDSP_CAPTURE_DEVICE` |
| POST | `/api/autoeq/start` | `{"outputs": [8, 9], "bands": 7, "device": "default", "mic_channel": 1, "seconds": 8, "settle": 2}` | backup, new session. Without `device` it records from `TRACKDSP_CAPTURE_DEVICE` |
| POST | `/api/autoeq/record` | `{"verify": false}` | records one position, blocks until done, 409 while another measurement runs on the bridge |
| POST | `/api/autoeq/upload?name=&verify=1` | a WAV file of up to 64 MB | adds it as a position, as a verify position with `verify=1` |
| POST | `/api/autoeq/remove` | `{"index": 0}` | removes one recording |
| POST | `/api/autoeq/calibration` | "frequency dB" lines, empty to remove | sets the mic calibration |
| POST | `/api/autoeq/fit` | `{"target": "flat", "low": 60, "high": 16000, "max_boost": 3, "max_cut": 9, "max_q": 5, "tolerance": 1}` | fits, the result has the bands and the curves |
| POST | `/api/autoeq/apply` | – | writes the bands of the last fit |
| POST | `/api/autoeq/revert` | `{"backup": "backup-<time>.json"}`, the session's backup without it | restores the PEQ |
| POST | `/api/autoeq/discard` | – | ends the session and deletes its recordings |

Every answer except `GET` carries the session as `{"session": ...}`. Errors are `{"error": "..."}` with 400,
409 (no device, or a measurement running), 500 (the recording failed), 503 or 504 as in the rest of the API.

### On the command line: `trackdsp:autoeq`

```bash
bin/console trackdsp:autoeq out1,out2 --device=plughw:CARD=UMC1820,DEV=0 --mic-channel=1 --positions=3
bin/console trackdsp:autoeq out1,out2 --cal=ecm8000.txt --target=tilt:-1 --apply
bin/console trackdsp:autoeq out1,out2 --wav=var/autoeq/measure-20260927-120000-1.wav
bin/console trackdsp:autoeq out1,out2 --revert=var/autoeq/backup-20260927-120000.json
```

The auto EQ is supervised and runs before the show, not during it. One run does this:

1. Saves the device state as a preset file `backup-<time>.json` in `--dir` (default `var/autoeq`).
2. For each of `--positions` positions it saves the restore to a file (see [Restore](#restore)), pauses the
   gain riders, bypasses the PEQ bands it owns on the
   chosen outputs (bands 1 to `--bands`) and starts the device's pink noise on every routed output. Keep the
   level safe before starting. With more than one position it asks before each recording.
3. Waits until the device has taken these writes, lets the noise settle for `--settle` seconds (default 2) and
   records the measurement mic with `arecord` for `--seconds`.
4. Switches the test tone back, restores the bands and starts the riders again.
5. Analyses the recordings and averages them.
6. Fits peaking bands to the target and prints them with the deviation before and after.
7. Writes the bands only with `--apply` or after a confirmation, whose default is no. Unused owned bands are
   set flat and bypassed. Without a write, the original bands are restored.

The tone and the bands are restored when a recording fails. Ctrl+C before or during a recording puts them back,
skips the recording and ends the remaining positions. See [Restore](#restore) for a device that drops off. `--revert` restores the PEQ of the outputs from a backup. `--wav` analyses existing
recordings instead of measuring and leaves the device untouched until the write.

Only one measurement runs on a bridge at a time. The Auto EQ page, `trackdsp:autoeq` and `trackdsp:measure`
share the lock `<socket>.measure.lock` (`var/bridge.measure.lock` by default), and the one that comes second
fails with "Another measurement is running on this bridge".

### Measurement

- A mono measurement mic on an input of the interface, `--mic-channel` counts from 1. `--device` is the ALSA
  capture device, by default `TRACKDSP_CAPTURE_DEVICE`, and `arecord -l` lists them. Recordings are 48 kHz,
  32 bit, with two channels at least. Asked for a single channel, plughw averages every input of a multichannel
  interface into it, while from two channels up it copies them one to one.
- `--cal` reads a calibration file with "frequency dB" lines (the usual format of mic calibration files,
  header lines are skipped). Without it the mic is taken as flat.
- The analysis is a Welch power spectrum (16384-point FFT, Hann window, 50 % overlap) summed into
  1/6-octave bands on a 24-points-per-octave grid from 20 Hz to 20 kHz. Pink noise reads flat.
- Several positions are averaged in the power domain, which evens out room modes at single spots.
- ddev has `arecord` and the host's sound cards (`.ddev/docker-compose.alsa.yaml`). Inside ddev `default` is
  the laptop's own codec, so `.env.local` names the interface in `TRACKDSP_CAPTURE_DEVICE`. PipeWire has to
  let go of the card first, `pactl set-card-profile <card> off`.

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

## Measurements: `trackdsp:measure`

`trackdsp:measure` plays the device's test tone on some outputs through the bridge, records inputs of the
interface and prints their levels and third-octave bands. With `--reference` it also prints the transfer
function of every channel against the reference loopback. It is meant for checking the measurement chain, the
8x8 and the speakers one output at a time. Every recording stays in `--dir` with a JSON file of its results,
so it can be analysed again later.

```bash
bin/console trackdsp:measure out3 -c 1,2,6 --trim=-20             # pink noise on Out3 alone, Out8 keeps playing
bin/console trackdsp:measure out7 -c 5,6 --relative=6             # a loopback against the reference loopback
bin/console trackdsp:measure out3 -c 1,6 --reference=6            # delay, polarity and phase of Out3 at mic 1
bin/console trackdsp:measure out7 -c 5 --signal=sine:100          # level of a 100 Hz sine
bin/console trackdsp:measure -c 1 --signal=none                   # generator off: noise floor and hum
bin/console trackdsp:measure -c 1,2,5,6 --wav=var/measure/stage1-loopback.wav   # no bridge needed
```

The argument names the outputs to play, and every other output is muted for the recording. Without it every
output plays as set. One run does this:

1. Reads the device state from the bridge and plans the solo (see [Solo](#solo)). Every frame of the setup
   and of the restore is built before the first write.
2. Saves the restore to a file, pauses the gain riders, mutes the other outputs, sets the compressor of a trimmed output at ratio 1:1.0 to
   1:1.1 from +20 dB, trims the played ones, unmutes a played output that is muted and starts the test tone.
3. Waits until the device has taken these writes, lets the tone settle for `--settle` seconds and records
   `--seconds` from `--device`.
4. Switches the tone back to what it was, puts the gains, the compressors and the mutes back and starts the
   riders again. This also happens when the recording fails. Ctrl+C before or during the recording skips it
   and puts the device back.
5. Prints the levels and the bands, with `--reference` also the transfer functions, and saves the recording
   and its JSON file in `--dir`.

| Option | Default | Meaning |
|---|---|---|
| `-c`, `--channels` | 1 | inputs of the interface to record and analyse, counted from 1, for example `1,5,6` |
| `-d`, `--device` | `TRACKDSP_CAPTURE_DEVICE` | ALSA capture device, recorded as for the auto EQ (see [Measurement](#measurement)) |
| `--signal` | pink | `pink`, `white`, `sine:<Hz>` or `none`. A sine takes the nearest of the device's 31 frequencies (20 Hz to 20 kHz in third octaves) and is refused more than 1/6 octave away from it. `none` switches the generator off, so whatever arrives at the analog inputs plays |
| `--trim` | 0 | dB added to the gain of every played output except the reference. Each output is trimmed from its own gain and held within -60..+12 dB. A trimmed output at compressor ratio 1:1.0 runs at 1:1.1 from +20 dB for the recording, see [Solo](#solo) |
| `--allow-boost` | off | needed for a positive `--trim` |
| `--reference-output` | out8 | output that keeps playing, unmuted and untrimmed, so that every recording also holds its loopback. `none` plays the outputs given and nothing else |
| `--seconds` | 10 | recording length in whole seconds, 2 at least |
| `--settle` | 2 | seconds of tone before the recording |
| `--cal` | – | mic calibration file for the first recorded channel, or `<channel>:<file>`. It can be given once per channel and corrects the bands only |
| `--relative` | – | shows the other channels relative to this input |
| `--reference` | – | input that records the reference loopback. Every other recorded channel gets its transfer function against it, see [Transfer function](#transfer-function) |
| `--wav` | – | analyses this recording instead of measuring, without the bridge. The JSON file is written only with `--save` |
| `--save` | `measure-<time>` | base name of the recording and its JSON file in `--dir`. An existing file is never overwritten |
| `--restore` | – | replays the restore file a crashed measurement left behind and stops, see [Restore](#restore) |
| `-s`, `--socket` | `TRACKDSP_BRIDGE_SOCKET` or `var/bridge.sock` | bridge socket |
| `--dir` | `var/measure` | directory for recordings and results |

Symfony Console reads `-c=1,5,6` as `=1,5,6`. Write `-c 1,5,6` or `--channels=1,5,6`.

### Solo

- Linked outputs play together. Asking for `out2` plays Out1 and Out2 when the two are linked, and the command
  says so. Every member gets its own mute and gain frame.
- A played output that is muted plays for the recording and is muted again afterwards.
- At compressor ratio 1:1.0, the factory setting, the 8x8 applies every output gain but 0 dB more strongly
  than set and adds the spectrum mirrored at 24 kHz ([measurements.md](measurements.md#output-gain-at-compressor-ratio-110)).
  A trim at -20 dB would act as -23.4 dB and lift the top octave by several dB. So a trimmed output at 1:1.0
  runs at 1:1.1 from +20 dB for the recording, which never compresses below the output ceiling and keeps the
  gain exact, and it gets its own compressor back afterwards. The command says so in a note.
- A linked reference output is refused, and so is a reference output among the played ones.
- Warnings name what can make a recording misleading. These are a played output without a routed input, a
  compressor on a played output, a played output at 1:1.0 whose own gain is not 0 dB, an output group or the
  sub control that mutes or lowers a played output, an Aux that takes inputs and is not muted, a test tone that
  was already on, and a reference output that is not flat (PEQ, HPF, LPF, compressor, a gain off 0 dB at 1:1.0,
  delay or inverted phase).

### Levels and bands

- The analysis leaves out the first 100 ms of every recording. The UMC1820 sometimes starts a capture with a
  click shorter than 5 ms, up to -16 dBFS on a line input that is silent afterwards, which would spoil the
  levels of quiet inputs.
- The levels are in dBFS. The table shows RMS, peak, the crest factor (peak minus RMS) and the samples at full
  scale (|x| ≥ 0.9999). A full-scale sine reads -3.01 dBFS RMS. Silence reads "silent", which is -200 dB in
  the JSON file. A warning names every input that clipped or delivers digital silence.
- The bands are the 31 third octaves of the test tone from 20 Hz to 20 kHz. They come from a Welch power
  spectrum with a 65536-point FFT (Hann window, 50 % overlap), so that the 20 Hz band gets enough bins. Their
  powers add up to the RMS level of everything between 17.8 Hz and 22.4 kHz.
- `--relative` shows the difference to that input, for the RMS in the level table and per band in the band
  table. The difference between two inputs includes the difference of their preamp gains, so compare two
  outputs recorded on the same pair of inputs.
- The JSON file holds the settings of the run (signal, played outputs, reference output, trims, notes,
  warnings, seconds, settle) and per channel the levels, the calibration file and the bands, rounded to
  0.01 dB.

### Transfer function

`--reference` names the input that records the reference output's loopback, for example IN6 for Out8. The
reference has to be one of at least two recorded channels. Every other channel then gets its transfer function
against it, on a live recording and on a `--wav` file alike. The interface records both inputs with one clock,
so no drift correction is needed.

- The analysis is a dual-channel FFT (`App\Measurement\TransferFunction`). Welch's method with Hann windows and
  50 % overlap gives the auto spectra Gxx and Gyy and the cross spectrum Gxy. The transfer function is
  H = Gxy / Gxx and the coherence is γ² = |Gxy|² / (Gxx · Gyy). Both signals of a segment go through one
  complex FFT.
- The first pass uses 65536 points and finds the whole-sample lag from the peak of the impulse response, within
  ±682 ms. The second pass shifts the reference by that lag and computes H and γ² with 16384 points, which gives
  bins of 2.9 Hz. It averages up to 32 segments, about 2.8 s of the recording, and refuses fewer than 8,
  because the coherence of a few segments reads too high. A recording of 2 s is enough for a lag up to about
  350 ms.
- The delay is the peak of the envelope of the analytic impulse response. Every bin counts by its coherence, so
  bins without signal add no noise. The envelope is evaluated on a grid of 1/8 sample around the peak and
  refined with a parabola. Without a peak at least 20 dB above the envelope's median there is no delay. The
  delay covers everything between the two inputs, which means the output path past the loopback, any delay set
  on the output, the processors and amplifiers in the chain and the air.
- The polarity comes from the phase of the analytic impulse response at the peak. It reads normal within ±45°
  of 0°, inverted within ±45° of 180° and unclear otherwise. For a way that is coherent over fewer than
  3 octaves, the command adds a note. There the peak gives the group delay of that band, and the way is aligned
  by its phase in the crossover region instead.
- The phase leaves out the measured delay, so a pure delay reads 0° at every frequency. Without a delay it leaves
  out the whole-sample lag.
- The bands are the third octaves of the level bands. Each gives the power mean of |H| in dB, the phase of the
  coherence-weighted mean of H/|H| and the mean γ². A band's phase is hidden below a mean γ² of 0.5.
- The summary names the widest run of adjacent bands with γ² of 0.5 or more as the coherent range, which is the
  band a way plays in, and the mean γ² inside it.
- A reference that clips or is silent gives a warning and no transfer functions, while the levels and the bands
  still count. A reference below -50 dBFS RMS gives a warning, since the interface noise then lowers the
  coherence.
- The magnitude includes the difference between the preamp gains of the two inputs, like `--relative`. Compare
  outputs recorded on the same pair of inputs.
- The JSON file names `reference_input` and holds per channel a `transfer` with the lag, the delay in samples
  and in ms, the delay the phase leaves out (`aligned`), the polarity, the phase and the ratio of the peak, the
  mean coherence, the coherent range, the segments, the warnings and the bands.
- The FFT runs in plain PHP. `trackdsp:measure` and `trackdsp:autoeq` switch PHP's JIT on at their start
  (`App\Measurement\Jit`), which makes it about twice as fast where the JIT is set to `off` with a buffer, as
  in `.ddev/php/jit.ini`. One pair of a 10 s recording then takes about 2 s in ddev.

### Restore

`trackdsp:measure`, `trackdsp:autoeq` and the Auto EQ page share one restore:

- Before the setup, the frames that put the device back and the settings of the paused gain riders are saved
  to `<socket>.measure.restore.json` (`var/bridge.measure.restore.json` by default). A measurement whose
  restore file cannot be saved does not start.
- The restore also runs when the setup writes fail or time out, since the bridge still applies a write whose
  client has gone.
- When the device drops off, the restore waits up to 30 s for the bridge to reconnect and sends the frames
  again. Sending them twice is safe, because they carry absolute values. The riders start again in any case,
  since they run in the bridge.
- Only a restore that the device has taken deletes the file. The error then names both failures when the
  recording failed as well.

While a restore file is there, no measurement starts and the error names the file, because the next snapshot
would take the device as it was left for the original. Once the device is connected again, replay the file:

```bash
bin/console trackdsp:measure --restore        # prints what it put back, or "Nothing to restore."
```

Deleting the file instead keeps the device as it is. After a crash of the bridge, start it first, since the
replay goes through it.

Ctrl+C or SIGTERM sets a flag that the settle and the wait for the device check every 0.1 s. The recording then
does not start, a recording that ends after the signal is dropped, and the restore runs without waiting for
another signal.
