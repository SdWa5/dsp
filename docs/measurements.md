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
- The test tone enters before the input gain (B2). The preset in use keeps every input at gain raw 0, which the
  editor shows as -60 dB and which mutes the input (see Meters). The input that feeds a played output was set to
  0 dB for the tests and back to raw 0 afterwards.
- For the meter calibration the bridge's event stream (`/api/events`) was recorded next to every run.

The runs below played Out7 against the reference. The compressor and gain settings of B3 to B6 were written
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

| Set | At 1:1.0 | Model | At 1:1.1 or Limit |
|---|---|---|---|
| +12 dB | +19.7 dB | +21.3 dB | +12.0 dB |
| +9 dB | +15.0 dB | +15.5 dB | |
| +6 dB | +9.7 dB | +10.0 dB | +6.0 dB |
| +3 dB | +4.7 dB | +4.8 dB | |
| -1 dB | -1.5 dB | -1.5 dB | |
| -3 dB | -4.2 dB | -4.2 dB | |
| -6 dB | -8.2 dB | -8.0 dB | -6.0 dB |
| -12 dB | -15.1 dB | -14.7 dB | -12.0 dB |
| -20 dB | -23.4 dB | -23.0 dB | -20.0 dB |
| -30 dB | -33.4 dB | -33.0 dB | |
| -40 dB | -43.3 dB | -43.0 dB | |

The table gives the change of the RMS level, which includes the mirror image described below. Out8 followed
the same law within 0.7 dB. The law held at two signal levels 15 dB apart, since the pink noise and a 100 Hz
sine 15 dB quieter both lost 8.2 to 8.3 dB of RMS level at -6 dB.

The numbers follow one law. Every second sample gets the output gain twice, so with the gain A as a factor the
samples alternate between A and A², and the level becomes 10 · log10((A² + A⁴) / 2). That is the model column,
which fits every row within 0.5 dB up to +9 dB. At +12 dB the samples that get the gain twice probably reach
the output ceiling of B5, and the level stays 1.6 dB below the model. A 5 kHz sine follows the same law within
0.2 dB (B6).

A gain that alternates between two values from one sample to the next also mirrors the signal at 24 kHz minus
each frequency. The image lies at (1 − A) / (1 + A) of the signal, which is 9.6 dB below it at -6 dB.

| Sine at -6 dB | Image | Image below the tone |
|---|---|---|
| 100 Hz | 23.9 kHz | 13 dB |
| 1 kHz | 23 kHz | 10 dB |
| 5 kHz | 19 kHz | 10 dB |
| 10 kHz | 14 kHz | 10 dB |

The image of the 100 Hz sine probably reads lower because the UMC1820's input filter already cuts near
24 kHz. At 0 dB the image is more than 75 dB below the tone. With pink noise at -20 dB the image lifts the top of the
spectrum, so that the band from 16 to 20 kHz reads 4.7 dB higher than the band from 1 to 2 kHz, and the band
from 23.5 to 24 kHz 19 dB higher. Every compressor adds the same image while it works, whatever its ratio
(B6).

At ratio 1:1.1 with the threshold at +20 dB, which is above the output ceiling of B5, the gain is exact and
the spectrum stays flat within 0.2 dB up to 24 kHz at -20 dB. Limit at +20 dB keeps the gain exact without an
image as well (B6). Since 0.13.1 `trackdsp:measure` runs a trimmed output at 1:1.0 that way for the recording
and puts its compressor back afterwards. It warns about a played output at 1:1.0 whose own gain is not 0 dB.

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
- The relation depends on the frequency. By the peak relation above, the 100 Hz sine at unity sits at
  -30.0 dB. The sines of 100 Hz, 1 kHz, 5 kHz and 10 kHz read the same on IN5 within 0.1 dB, and the first
  three read the same on the meters. A limiter holds the 1 kHz and the 5 kHz sine at -27.8 dB (limiter onset
  under Meters). A threshold therefore holds a 100 Hz signal about 2.2 dB higher than a 1 kHz signal.

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
  as the 8x8's maximum output. B3's relation holds for 100 Hz. By the 1 kHz relation the ceiling is about
  +14.8 dB, 3.2 dB below the sheet's value.
- Whether the ceiling is analog or digital is open. The sine cannot reach it, since its peak stays near
  -18 dBFS even with +12 dB on In7 and on Out7.

## B6: the image of a working compressor

A 5 kHz sine on Out7 at the rig's bus settings, which are Limit with an attack of 1 ms and a release of
3000 ms. One run used B3's times instead. The others kept the rig's times and changed the frequency, the ratio
or the signal. The depth is the drop of the RMS level on IN5 against the same signal with the threshold at
+20 dB. The image sits at 24 kHz minus the tone frequency and is given below the tone.

| Run | Signal | Compressor | Depth | Image | Model |
|---|---|---|---|---|---|
| `b6-a1` | 5 kHz | Limit at -31 dB | 4.89 dB | -14.4 dB | -14.1 dB |
| `b6-a2` | 5 kHz | Limit at -34 dB | 8.57 dB | -9.4 dB | -9.0 dB |
| `b6-a3` | 5 kHz | Limit at -40 dB | 15.27 dB | -4.6 dB | -4.2 dB |
| `b6-b2` | 5 kHz | Limit at -34 dB, 50 ms and 200 ms | 8.56 dB | -9.4 dB | -9.0 dB |
| `b6-c2` | 10 kHz | Limit at -34 dB | 8.53 dB | -9.2 dB | -9.0 dB |
| `b6-r1` | 5 kHz | 1:1.1 at -40 dB | 1.27 dB | -26.4 dB | -26.1 dB |
| `b6-r5` | 5 kHz | 1:2 at -40 dB | 8.32 dB | -9.7 dB | -9.3 dB |
| `b6-r9` | 5 kHz | 1:4 at -40 dB | 11.43 dB | -6.9 dB | -6.5 dB |
| `b6-r13` | 5 kHz | 1:10 at -40 dB | 13.78 dB | -5.4 dB | -5.0 dB |

- Without compression the image lies 79 dB below the tone (`b6-a0` and `b6-c0`), at the noise floor.
- The image follows the depth alone. Attack and release, the frequency and the ratio leave it where the depth
  puts it, as the four runs at about 8.5 dB of depth show.
- The law is the one of the output gain at 1:1.0. While the compressor lowers the gain by a factor g, every
  second sample gets the factor twice. The samples alternate between g and g², the RMS level drops by
  10 · log10((g² + g⁴) / 2), and the image lies at (1 − g) / (1 + g) of the tone. The model column computes
  the image from the measured depth that way. The measured image lies 0.2 to 0.4 dB below it at every depth,
  which fits a slight drop of the UMC1820 towards 19 kHz.
- By the law the depths mean gain reductions of 3.5, 6.5 and 12.5 dB at Limit, and of 0.9, 6.2, 8.9 and
  11.1 dB at 1:1.1, 1:2, 1:4 and 1:10. With the 5 kHz sine at -27.75 dB on the threshold scale (Meters), the
  static curves give 3.3, 6.3, 12.3, 1.1, 6.1, 9.2 and 11.0 dB, so the ratios work as labelled.
- The harmonics stay low. The second and the third harmonic lie at least 62 dB below the tone, even at 15 dB
  of depth.

| Gain reduction | RMS drop | Image below the tone |
|---|---|---|
| 1 dB | 1.5 dB | 24.8 dB |
| 3 dB | 4.3 dB | 15.3 dB |
| 6 dB | 8.0 dB | 9.6 dB |
| 10 dB | 12.6 dB | 5.7 dB |

Pink noise at the rig's bus settings, IN5 against IN6 per band, lifted against the band from 1 to 2 kHz:

| Band | Limit at -10 dB, 7.5 dB depth | Limit at -16 dB, 14.1 dB depth |
|---|---|---|
| 4 to 8 kHz | +0.1 dB | +0.4 dB |
| 8 to 12 kHz | +0.2 dB | +0.8 dB |
| 12 to 16 kHz | +0.4 dB | +1.5 dB |
| 16 to 20 kHz | +1.0 dB | +2.9 dB |
| 20 to 22 kHz | +1.9 dB | +5.0 dB |
| 22 to 23.5 kHz | +3.9 dB | +8.2 dB |
| 23.5 to 24 kHz | +10.2 dB | +15.2 dB |

The crest factor stays at 12.6 dB in both runs, so the limiter does not clip the peaks. It lowers the gain as
a whole, and its image lifts the top of the spectrum down to 12 kHz.

The output gain at 1:1.0, set directly on Out7 with a 5 kHz sine:

| Out7 at 1:1.0 | Level | Model | Image | Model |
|---|---|---|---|---|
| +6 dB | +9.9 dB | +10.0 dB | -9.9 dB | -9.6 dB |
| -6 dB | -8.1 dB | -8.0 dB | -9.9 dB | -9.6 dB |
| -12 dB | -14.8 dB | -14.7 dB | -4.8 dB | -4.5 dB |
| -20 dB | -23.1 dB | -23.0 dB | -2.1 dB | -1.7 dB |

With Limit at +20 dB instead, gains of -6, +6 and -10 dB gave -6.00, +6.00 and -10.00 dB, and the image
stayed at the noise floor, 70 dB or more below the tone. Limit or 1:1.1 at +20 dB is the way to switch the
compressor off.

## Meters

The meter reply of `40` carries 17 triplets for In1 to In8, Out1 to Out8 and the Aux, followed by
`00 ff ff 00`. Each triplet holds a 16-bit value, low byte first, and a third byte with 1/256 steps of it. The
value is logarithmic, with 128 steps per doubling of the amplitude or 21.26 steps per decibel.

```
dB = (value - 15906) · 20 · log10(2) / 128
```

The zero point puts the meters on the scale of the compressor threshold (limiter onset below). trackdsp shows
everything below -90 dB as -90 dB. Before 0.14.0 it used another formula. Under it an idle input at 0 dB gain
read -6.0 dB, the tone read -0.2 dB and a real step of 8.1 dB showed as 0.7 dB.

A 1 kHz sine on Out7 at 1:1.1 from +20 dB, with the Out7 gain stepped. IN5 is the level in the band of the
tone, against 0 dB.

| Out7 gain | IN5 | Out7 meter | Steps per dB |
|---|---|---|---|
| +12 dB | +12.00 dB | 15569.8 | 21.27 |
| +6 dB | +6.00 dB | 15442.2 | 21.28 |
| 0 dB | 0.00 dB | 15314.6, which is -27.8 dB | |
| -6 dB | -6.00 dB | 15186.9 | 21.27 |
| -12 dB | -12.00 dB | 15059.2 | 21.28 |
| -20 dB | -20.00 dB | 14888.2 | 21.32 |
| -30 dB | -29.99 dB | 14676.9 | 21.26 |
| -40 dB | -39.97 dB | 14470.6 | 21.10 |
| -50 dB | -49.90 dB | 14250.3 | 21.28 |
| -60 dB, raw 0 | -79.1 dB, noise only | 1453.7 | |

- The steps per dB stay at 21.3 over 62 dB, so the scale is logarithmic with 128 steps per doubling.
- Gain raw 0 mutes. The band of the tone on IN5 holds only noise, and the meter reads 0x05AD (1453.7), which
  is what digital silence reads. A channel fed only by muted inputs or by inputs at raw 0 reads the same. The
  editor shows raw 0 as -60 dB.
- The meters tap after the gain and before the mute. In7 steps of +12, -12 and -30 dB moved the In7 and the
  Out7 meter to 15569.2, 15059.2 and 14676.5, the values of the same Out7 steps, and left Out6 alone. With In7
  muted, the In7 meter kept reading 15314.4 while the Out7 meter fell to 0x05AD. With Out7 muted, the Out7
  meter kept reading 15314.3.
- The output meters also tap after the compressor, since they follow a limiter (below).
- The meters read like an RMS meter. Pink noise at unity reads 14.35 dB above the sine on the meter, 14.5 dB
  above it in RMS on IN5 and 24.0 dB above it in peak. With pink noise the steps scatter between 20.3 and 21.7
  per set dB, because the noise level itself varies from run to run.
- With the generator on Analog Input and no signal, In7 reads 13827.6 at 0 dB gain, which is -97.8 dB. The
  Out7 meter follows the Out7 gain with it at 21.5 steps per dB.

### Limiter onset

A sine on Out7 at unity, with Limit, an attack of 50 ms, a release of 200 ms and a hard knee, and the threshold
walked down from +20 dB. The table gives the drop of the Out7 meter against the unlimited reading, which was
-27.8 dB for all three frequencies.

| Threshold | 100 Hz | 1 kHz | 5 kHz |
|---|---|---|---|
| -22 dB | -0.06 dB | -0.10 dB | -0.11 dB |
| -24 dB | -0.07 dB | -0.53 dB | -0.55 dB |
| -25 dB | -0.12 dB | -0.88 dB | -0.89 dB |
| -26 dB | -0.37 dB | -1.30 dB | -1.31 dB |
| -27 dB | -0.62 dB | -1.80 dB | -1.79 dB |
| -28 dB | -0.95 dB | -2.36 dB | -2.34 dB |
| -29 dB | -1.42 dB | -2.96 dB | -2.95 dB |
| -30 dB | -1.96 dB | -3.62 dB | -3.60 dB |
| -31 dB | -2.45 dB | -4.74 dB | -4.73 dB |
| -32 dB | -3.04 dB | -6.20 dB | -6.19 dB |
| -34 dB | -5.07 dB | -8.82 dB | -8.82 dB |
| -38 dB | -9.75 dB | -13.65 dB | -13.65 dB |

- 1 kHz and 5 kHz behave the same. Each drop, taken as the drop of the tone, (g + g²) / 2 by the law of B6,
  gives the gain reduction, and the threshold plus the gain reduction gives the level at which the limiter
  holds the sine. From -31 dB down that level settles at -27.7 to -28.0 dB. The zero point of the meter law
  puts the sine at unity at -27.8 dB, so a limiter holds a 1 kHz signal at its threshold as read on the output
  meter. Taken as drops of the RMS level instead, the level would drift from -27.6 to -27.0 dB, so the zero
  point holds within about 0.5 dB.
- The onset is soft. The limiter already works about 4 dB before the sine reaches the threshold and holds it
  only from about 3 dB past it.
- At 100 Hz the drop starts later and does not settle. The limiter holds the peak of the 100 Hz sine at the
  threshold (B3), and by that relation the sine at unity sits at -30.0 dB, 2.2 dB below its meter reading. A
  limiter therefore holds a 100 Hz signal about 2.2 dB above its threshold as read on the meter.

## What it means for presets

- An output that needs a gain other than 0 dB needs its compressor switched off another way than with ratio
  1:1.0. Limit or 1:1.1 with the threshold at +20 dB never compresses, keeps the gain exact and adds no image.
  At 1:1.0 the gain acts more strongly than set, and the output carries the mirror image, which lands in the
  audible range for every signal above 4 kHz.
- Every compressor adds the same image while it works, whatever its ratio and its times. It lies 15 dB below
  the signal at 3 dB of gain reduction and 10 dB below it at 6 dB. A limiter set as protection costs nothing
  while it stays idle, but a threshold that the programme reaches all the time colours the top end all the
  time.
- The output gain comes before the limiter, so changing the gain does not move the limit.
- The output ceiling is about +12.5 dB on the threshold scale at 100 Hz and about +14.8 dB at 1 kHz, so a
  threshold above that never limits.
- Gain raw 0, which the editor shows as -60 dB, mutes the channel.
- A limiter holds a 1 kHz signal at its threshold as read on the output meter, and a 100 Hz signal about
  2.2 dB above it. The meters show the level before the mute, so a muted channel keeps its reading.
