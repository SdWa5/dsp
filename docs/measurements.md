# Measurements

The first measurements with a Behringer UMC1820, two measurement mics and the 8x8's own test tone, taken on
2026-09-28 and 2026-09-29 with `trackdsp:measure` ([automation.md](automation.md#measurements-trackdspmeasure)).
This page lists the setup, the commands and the results. The facts about the 8x8 are also summarised in
[protocol.md](protocol.md#signal-chain). The recordings and their JSON files stay in `var/measure/` on the
measuring machine and are not part of the repository.

## Setup

- The UMC1820 records at 48 kHz in `S32_LE` inside ddev, with `TRACKDSP_CAPTURE_DEVICE=plughw:CARD=UMC1820,DEV=0`.
  Mic 1 is on IN1 and mic 2 on IN2, with +48V on for inputs 1 to 4.
- The loopbacks go to the rear line inputs as XLR, with +48V off for inputs 5 to 8, PAD pressed and the gain
  knobs at minimum. 8x8 OUT7 goes to IN5. 8x8 OUT6 goes to IN6 as the reference loopback, because the rack's
  output panel has a broken connection on output 8 (see B1). Until that is repaired, every run needs
  `--reference-output=out6`.
- The amplifiers were off for every test on this page.
- The bridge in ddev holds the 8x8 on USB. Every session starts from a saved state and ends with a byte
  comparison of the configuration against it.
- The test tone enters before the input gain (B2). The preset in use keeps every input at -60 dB, so the input
  that feeds a played output was set to 0 dB for the tests and back to -60 dB afterwards.

The runs below played Out7 against the reference. The compressor and gain settings of B3 to B5 were written
through `/api/write` before each run and put back afterwards.

```bash
ddev exec bin/trackdsp measure out7 -c 5,6 --relative=6 --reference-output=out6 --save=b1-out7-vs-out6
ddev exec bin/trackdsp measure out7 -c 5,6 --relative=6 --reference-output=out6 --signal=sine:100 --save=b3-limit-40
ddev exec bin/trackdsp measure out7 -c 5,6 --relative=6 --reference-output=out6 --trim=-6 --signal=sine:5k --save=img-5k-trim-6
```

## Measurement chain

- The line inputs with PAD and minimum gain have a floor of -109 dBFS RMS.
- The mics have a floor of about -71 dBFS in a quiet room, -71.2 dBFS on IN1 and -70.3 dBFS on IN2. The same
  room sounds read 2 to 3 dB louder on IN2. Whether the gain knobs or the capsules differ is left to the mic
  matching test, which needs a speaker playing.
- The UMC1820 sometimes starts a capture with a click shorter than 5 ms, up to -16 dBFS on a line input that
  is silent afterwards. It never showed on the mic inputs. With the click in the analysis, a muted input read
  -75.5 instead of -108.7 dBFS, and the peak and the crest factor of quiet signals were wrong. Since 0.13.1
  `trackdsp:measure` leaves the first 100 ms of every recording out of the analysis.

## B1: loopbacks

- OUT7 on IN5 reads 0.2 to 0.3 dB above OUT6 on IN6 in every third octave from 20 Hz to 20 kHz. The two
  inputs are matched well enough for a reference loopback.
- OUT8 on IN6 read 10.7 dB below OUT7 up to 1 kHz and only 8.1 dB below it at 20 kHz, which looks like a
  resistor with a capacitor across it in series with the signal. With the two cables swapped at the UMC, the
  fault moved to IN5. It followed the OUT8 path, and a broken connection turned up in the rack's output panel
  on output 8.

## B2: where the test tone enters

Pink noise on Out7, which takes In7 only unless the table says otherwise.

| In7 | IN5 RMS |
|---|---|
| 0 dB | -42.6 dBFS |
| muted | -108.7 dBFS, the floor |
| -10, -20, -30 dB | -52.5, -62.6, -72.6 dBFS |
| In7 and In8 routed, both at 0 dB | -36.7 dBFS |
| In8 alone routed | -42.5 dBFS |

- The generator feeds every input before its gain and its mute. An output plays the tone only through the
  inputs it takes, and so does the Aux.
- The input gain is exact within 0.1 dB.
- Two routed inputs add 5.9 dB. The generator is one coherent source for every input rather than one
  independent source per input, which would add 3 dB.
- Pink noise plays 15 dB louder than the sine, -42.4 against -57.3 dBFS RMS with the same gains.
- The sine frequency index lives at footer byte +50 of the configuration. Writes of 17, 5 and 0 each showed
  up there after a fresh read. Before 0.13.1 the model did not read it, so the editor showed 1 kHz whatever the
  device held, and every restore of `trackdsp:measure` and the auto EQ wrote 1 kHz back.

## Output gain at compressor ratio 1:1.0

The output gain is exact at every compressor ratio except 1:1.0, which is the factory setting. At 1:1.0 every
output gain other than 0 dB acts more strongly than set. The steps below start from 0 dB with pink noise on
Out7. At 1:1.1 and at Limit the threshold was +20 dB, so the compressor did not work.

| Set | At 1:1.0 | At 1:1.1 or Limit |
|---|---|---|
| +12 dB | +19.7 dB | +12.0 dB |
| +9 dB | +15.0 dB | |
| +6 dB | +9.7 dB | +6.0 dB |
| +3 dB | +4.7 dB | |
| -1 dB | -1.5 dB | |
| -3 dB | -4.2 dB | |
| -6 dB | -8.2 dB | -6.0 dB |
| -12 dB | -15.1 dB | -12.0 dB |
| -20 dB | -23.4 dB | -20.0 dB |
| -30 dB | -33.4 dB | |
| -40 dB | -43.3 dB | |

The table gives the change of the RMS level, which includes the mirror image described below. Out8 followed
the same law within 0.7 dB. The law held at two signal levels 15 dB apart, since the pink noise and a 100 Hz
sine 15 dB quieter both lost 8.2 to 8.3 dB of RMS level at -6 dB.

At 1:1.0 with a gain other than 0 dB, the output also carries a mirror image of the signal at 24 kHz minus
each frequency. A gain that alternates between two values from one sample to the next produces exactly such
an image.

| Sine at -6 dB | Image | Image below the tone |
|---|---|---|
| 100 Hz | 23.9 kHz | 13 dB |
| 1 kHz | 23 kHz | 10 dB |
| 5 kHz | 19 kHz | 10 dB |
| 10 kHz | 14 kHz | 10 dB |

The image of the 100 Hz sine probably reads lower because the UMC1820's input filter already cuts near
24 kHz. At 0 dB the image is more than 75 dB below the tone. With pink noise at -20 dB the image lifts the top of the
spectrum, so that the band from 16 to 20 kHz reads 4.7 dB higher than the band from 1 to 2 kHz, and the band
from 23.5 to 24 kHz 19 dB higher. The same image appears while the limiter works. It was 24 dB below the
tone with 1.4 dB of limiting, 16 dB below it with 4 dB and 9 dB below it with 10 dB. Other ratios were not
tested while compressing.

At ratio 1:1.1 with the threshold at +20 dB, which is above the output ceiling of B5, the gain is exact and
the spectrum stays flat within 0.2 dB up to 24 kHz at -20 dB. Since 0.13.1 `trackdsp:measure` runs a trimmed
output at 1:1.0 that way for the recording and puts its compressor back afterwards. It warns about a played
output at 1:1.0 whose own gain is not 0 dB.

## B3: limiter and threshold

A 100 Hz sine on Out7, which reads -54.2 dBFS peak on IN5 without limiting.

| Out7 compressor | IN5 peak | IN5 RMS |
|---|---|---|
| 1:1.0 | -54.2 dBFS | -57.3 dBFS |
| Limit at +20, +10, 0, -10 and -20 dB | -54.2 dBFS | -57.3 dBFS |
| Limit at -30 dB | -55.6 dBFS | -59.1 dBFS |
| Limit at -40 dB | -64.1 dBFS | -69.4 dBFS |
| Limit at -10 dB, gain +12 dB | -34.3 dBFS | -38.4 dBFS |

- The limiter works on the peak. Once it limits, the peak on IN5 sits at the threshold minus 24.2 dB, which
  held at -40 dB and at -10 dB within 0.1 dB. The offset belongs to this UMC setting, PAD and minimum gain.
- At -30 dB the unlimited peak sits right at the threshold minus 24.2 dB and still lost 1.4 dB.
- Limiting distorts the sine with an attack of 50 ms and a release of 200 ms. The second harmonic is 45 dB
  below the tone with 1.4 dB of limiting, 39 dB below it with 4 dB and 38 dB below it with 10 dB.
- The threshold scale is confirmed as relative, one decibel of threshold per decibel of output. Whether it
  means dBu at the output connector needs a voltmeter on a 100 Hz sine.

## B4: gain before the compressor

- With Limit at -40 dB and the gain lowered from 0 to -6 dB, the peak on IN5 stayed at -64.1 and -64.2 dBFS.
  The output gain comes before the compressor, so the threshold stays the real output limit whatever the gain.
- With Limit at -30 dB the same step put the peak at -60.1 dBFS, 6 dB below the unlimited level, because the
  signal then stays below the threshold.

## B5: output ceiling

Pink noise on Out7 at 1:1.1 from +20 dB, so every gain is exact.

| In7 | Out7 | IN5 RMS | IN5 peak | Crest factor |
|---|---|---|---|---|
| 0 dB | 0 dB | -42.5 dBFS | -29.8 dBFS | 12.7 dB |
| 0 dB | +12 dB | -30.5 dBFS | -18.2 dBFS | 12.3 dB |
| +12 dB | 0 dB | -30.4 dBFS | -18.5 dBFS | 11.9 dB |
| +12 dB | +6 dB | -24.4 dBFS | -12.3 dBFS | 12.1 dB |
| +6 dB | +12 dB | -24.6 dBFS | -12.3 dBFS | 12.4 dB |
| +9 dB | +12 dB | -21.7 dBFS | -10.8 dBFS | 10.9 dB |
| +12 dB | +12 dB | -19.6 dBFS | -9.4 dBFS | 10.2 dB |

- The crest factor holds at 12 dB up to -12.3 dBFS peak and falls above it, while IN5 records no clipped sample.
  The 8x8 clips at about -11 dBFS peak on IN5. By the relation of B3 that is a threshold of about +12.5 dB.
  If the threshold scale is dBu, the ceiling lies 5.5 dB below the 18 dBu that the rig's limiter sheet assumes
  as the 8x8's maximum output.
- Whether the ceiling is analog or digital is open. The sine cannot reach it, since its peak stays near
  -18 dBFS even with +12 dB on In7 and on Out7.

## Meters

The meter reply of `40` does not follow the formula in [protocol.md](protocol.md#scales). With it, an idle
input at 0 dB gain reads -6.0 dB, the tone reads -0.2 dB and a real step of 8.1 dB shows as 0.7 dB. A
calibration run against the UMC1820 is listed in [TODO.md](../TODO.md).

## What it means for presets

- An output that needs a gain other than 0 dB needs a compressor ratio other than 1:1.0. Otherwise the gain
  acts more strongly than set, and the output carries the mirror image, which lands in the audible range for
  every signal above 4 kHz. Ratio 1:1.1 with the threshold at +20 dB never compresses and keeps the gain exact.
- The limiter adds the same image while it works, and more of it the harder it limits.
- The output gain comes before the limiter, so changing the gain does not move the limit.
- The output ceiling is about +12.5 dB on the threshold scale, so a threshold above it never limits.
