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
- The amplifiers were off for the bench tests B1 to B6 and the meter calibration. The speaker tests at the end
  of the page ran with the amplifiers on and used Out7 as the reference.
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

## Speakers and amplifiers

One cabinet of every type, measured indoors on 2026-09-29 with the amplifiers on.

### Setup

- The DCX2496 was left out. The 8x8 split Tecnare L itself. Out1 took In5 for the woofer with a high-pass at
  185 Hz and a low-pass at 2520 Hz, both LK-24, and Limit at -10 dB. Out2 took In5 for the horn with a
  high-pass at 2520 Hz LK-24 and Limit at -20 dB.
- TIP10000q channel 1 drove the woofer and channel 2 the horn. MM14K #1 channel A drove a Flexy from Out3 and
  MM14K #4 a SKRAM from Out4. An Achenbach 18 was fed from Out6. Every amplifier knob stood at half. The DIP
  switch gains were not noted.
- The subs played full range, without a high-pass or a low-pass. Their compressors stood at 1:1.0 from +20 dB,
  which `trackdsp:measure` switches to 1:1.1 for the recording.
- In3 to In7 stood at 0 dB. In1 and In2 stayed at raw 0, since the preset loops Out1 and Out2 back into them.
- Out7 on IN5 was the reference loopback.
- Mic 1 and mic 2 stood side by side on axis, about 1 m in front of the cabinet. For the later runs they stood
  somewhat higher and farther away, since the room left no other place. With one position per cabinet in a
  room, every curve holds room modes and floor reflections, which can move a band below about 300 Hz by
  several dB.
- Every run played pink noise at `--trim=-20` for 10 s, unless the text says otherwise.

```bash
ddev exec bin/trackdsp measure out3 -c 1,2,5 --reference-output=out7 --trim=-20 --save=s4-flexy
ddev exec bin/trackdsp measure out1,out2 -c 1,2,5 --reference-output=out7 --trim=-20 --relative=1 --save=s4-c2c3-tecL-full
ddev exec bin/trackdsp measure -c 1,2,5 --signal=none --save=s4-s1-floor-mm14k1-on
```

### S1: noise floor and hum

The generator was on Analog Input with no signal, and mic 1 stood 1 m from the subs.

| Amplifiers | Mic 1 RMS | Third-octave bands |
|---|---|---|
| Off | -75.8 dBFS | -88 to -93 dBFS from 20 Hz to 5 kHz |
| MM14K #1 on | -71.5 dBFS | -81 to -82 dBFS at 200 Hz and from 315 to 500 Hz, the rest unchanged |

- There is no mains hum. The 50 Hz and the 100 Hz band stayed at the floor with every amplifier on.
- The fans of the MM14K add about 10 dB between 200 and 500 Hz. That stays more than 20 dB below the subs at
  -20 dB.
- The TIP10000q whines between 800 Hz and 1.25 kHz. While the horn played, whose high-pass keeps it out of that
  range, mic 1 read a steady -78 dBFS at 800 Hz and -76 dBFS at 1 kHz.
- `s4-s1-floor-amps-on` was recorded before the amplifiers were actually on and holds the floor with them off.
  `s4-s1-floor-amps-off` holds room noise and is not used.

### S2: subs

Mic 1 per band in dB, against each sub's own power mean from 40 to 125 Hz.

| Band | SKRAM | Flexy | Achenbach 18 |
|---|---|---|---|
| 20 Hz | -20 | -24 | -26 |
| 25 Hz | -8 | -24 | -18 |
| 31.5 Hz | -3 | -19 | -10 |
| 40 Hz | 0 | -3 | -1 |
| 63 Hz | -2 | -4 | -3 |
| 100 Hz | +1 | +1 | +2 |
| 160 Hz | -8 | -2 | -4 |
| 200 Hz | 0 | 0 | -9 |
| 250 Hz | 0 | -6 | -8 |
| 315 Hz | -1 | -12 | -6 |
| 400 Hz | -17 | -13 | -5 |
| 630 Hz | -17 | -9 | -6 |
| 1 kHz | -24 | -13 | -10 |
| 1.6 kHz | -20 | -17 | -15 |
| 2.5 kHz | -27 | -25 | -11 |

- The SKRAM reaches deepest and is 3 dB down at 31.5 Hz. The Achenbach 18 is 10 dB down at 31.5 Hz and about
  6 dB down at 35 Hz, which fits the 35 Hz in its sdwa5-3d spec. The Flexy falls below 40 Hz and is 19 dB down
  at 31.5 Hz.
- From 40 to 125 Hz all three stay within about 4 dB of flat. The dip at 63 Hz shows in all three and is
  probably the room.
- Above 125 Hz the SKRAM holds up to 315 Hz and then falls by 17 dB, while the Flexy falls from 250 Hz. The
  Achenbach 18 dips by 8 to 9 dB at 200 and 250 Hz and still plays 5 to 11 dB below its passband up to
  2.5 kHz, so the upper limit of 1500 Hz in its spec is too low. Whether the dip comes from the horn or from
  this position needs a second position.
- Mic 1 read -58.7, -57.7 and -56.7 dBFS from 40 to 125 Hz for the SKRAM, the Flexy and the Achenbach 18. The
  amplifiers differ and their gains were not noted, so this compares no sensitivities.
- Every sub sounded clean at -20 dB, without a rattle or a buzz.
- The runs `s4-s2-out3` to `s4-s2-out3-d` had the mics several metres from the Flexy. `s4-flexy` repeats them
  at about 1 m, and `s4-achenbach-spl` repeats `s4-achenbach` within 0.3 dB.

### S2: Tecnare L

Mic 1 in dBFS, with each way at -20 dB. The last column plays both ways together. The horn is left out below
2 kHz, where only the whine of the TIP10000q and the floor reach the mic.

| Band | Woofer, Out1 | Horn, Out2 | Both |
|---|---|---|---|
| 125 Hz | -74.1 | | -73.8 |
| 160 Hz | -61.7 | | -61.9 |
| 200 Hz | -52.2 | | -52.8 |
| 315 Hz | -49.1 | | -48.8 |
| 500 Hz | -45.7 | | -45.9 |
| 800 Hz | -52.6 | | -52.4 |
| 1.25 kHz | -60.2 | | -61.0 |
| 2 kHz | -59.7 | -64.0 | -58.9 |
| 2.5 kHz | -66.6 | -60.6 | -58.6 |
| 3.15 kHz | -75.7 | -55.9 | -57.2 |
| 5 kHz | -93.0 | -51.8 | -52.9 |
| 8 kHz | | -52.7 | -52.8 |
| 12.5 kHz | | -50.8 | -52.6 |
| 16 kHz | | -53.4 | -54.5 |
| 20 kHz | | -63.5 | -64.5 |

- The woofer plays from about 200 Hz and is 10 dB down at 160 Hz, where the 185 Hz high-pass and the cabinet
  add up. It peaks at 500 Hz and falls from 800 Hz on, 14 dB below the peak at 1.25 kHz.
- The horn stays within 3 dB from 5 to 16 kHz at about -52 dBFS and rolls off above 16 kHz.
- Played together, the two ways leave a hollow from 1.25 to 2.5 kHz, about 7 dB below the horn's plateau and
  13 dB below the woofer's peak. The split in the 8x8 stood in for the DCX2496 and is not the rig's crossover,
  but the woofer's fall above 800 Hz belongs to the cabinet.
- Out1 at -30 dB instead of -20 dB lowered every band from 160 Hz to 2.5 kHz by 10 dB within 1.1 dB, so the
  woofer is linear at these levels.
- Tecnare R was not measured.

### S3: pinout and high-pass check

- Before the horn was connected, the high-pass was checked on the loopback. Out2, Out5 and Out6 with a
  high-pass at 2520 Hz LK-24 read 6.0 dB down at 2.5 kHz and 24.3 dB down at 1.25 kHz against their level from
  8 to 16 kHz. That is the corner of a Linkwitz-Riley filter and one octave at 24 dB per octave. Out2 was
  recorded on IN8 in `s4-hf-check-out2-in8`.
- The Tecnare takes both ways over one Speakon cable. In the TIP10000q's other output socket the horn signal
  reached the woofer and the woofer signal reached nothing. In the right socket channel 1 drives the woofer and
  channel 2 the horn.

### C2: mic matching

- Mic 2 reads 1 to 2.5 dB above mic 1 from 25 Hz to 6.3 kHz in every run with the capsules side by side, most
  bands at 1.5 to 2 dB. Above 8 kHz the difference falls to between +0.9 and -1.0 dB.
- The same curve in every run points to the capsules or the preamps rather than to the positions. Single bands
  of the Tecnare and the Achenbach 18 stray by up to 1 dB more, where a few centimetres between the capsules
  matter.
- A first run with one mic hanging towards the floor read 4 dB apart. The capsules have to stand side by side.
- `s4-c2-out4-parallel` and `s4-c2c3-tecL-full` keep the curve for the auto EQ with two mics.

### C3: SPL offset

The meter at hand is a cheap one with A weighting only and a min, max and hold function. It was held at the
mics and read on max. Mic 1's level was A-weighted from its third-octave bands.

| Run | Mic 1 | Meter max | Offset |
|---|---|---|---|
| Tecnare L, both ways at -20 dB | -41.8 dBFS(A) | 81.3 dB(A) | 123.1 dB |
| Flexy at -14 dB | -60.1 dBFS(A) | 64.0 dB(A) | 124.1 dB |
| Achenbach 18 at -20 dB | -56.0 dBFS(A) | 69.4 dB(A) | 125.4 dB |

- With the gain knob of UMC1820 IN1 as set on 2026-09-29, 0 dBFS on mic 1 means about 123 dB SPL(A). The
  offset holds within about 2.5 dB.
- The max function catches the loudest moment of the noise, and the room noise weighs more in the quieter
  runs. Both lift the offset of the sub runs by 1 to 2.5 dB.
- The first Flexy reading at -20 dB, 66.1 dB(A), was disturbed by room noise and is left out.

### Open

- Tecnare R and the DIP switch gains of the amplifiers.
- The DCX2496's latency and the rig's crossover, which need the DCX2496 in the chain.
- A meter with C or Z weighting and a slow RMS reading would pin the SPL offset more tightly.

## Auto EQ and gain rider on the device

Taken on 2026-09-29 after the speaker tests, with Tecnare L on Out1 and Out2 at -20 dB and every other speaker
output muted.

### Auto EQ

Mic 1 stood about 1 m in front of Tecnare L. The fit ran from 200 Hz to 16 kHz against the flat target with
the default limits.

```bash
ddev exec bin/console trackdsp:autoeq out1,out2 -n --low=200 --high=16000
```

| | Deviation RMS | Deviation peak |
|---|---|---|
| Without the bands | 2.7 dB | 8.6 dB |
| Predicted with the bands | 1.8 dB | 6.5 dB |
| Measured with the bands | 1.8 dB | 5.5 dB |

- The seven bands were written to both outputs through `/api/write`, recorded with `trackdsp:measure --trim=0`
  and analysed with `trackdsp:autoeq --wav`. Measured and predicted deviation agree within 1.4 dB per third
  octave and 1.0 dB RMS. Above 1.25 kHz every band reads about 1 dB below the prediction, which is the level
  alignment of the fit rather than a band error. The largest difference lies at 400 and 500 Hz, where five bands
  overlap.
- The largest deviation is the hollow at 2.5 kHz between the two ways, 8.5 dB deep. The boost limit of 3 dB
  leaves most of it, and the crossover rather than the EQ has to close it.
- The fitter spent three of its seven bands at 388 to 397 Hz on boosts that lift the flank of its own 9 dB cut
  at 477 Hz, so the dips at 5 and 8 kHz stayed uncorrected (TODO.md).
- The Auto EQ page gave the same result through its own API, 2.7 against 1.78 dB RMS, and showed the session,
  the plot and the band table correctly.
- `--revert` put the backup back, and the bands of both outputs read flat afterwards.

### Gain rider

A UMC1820 line output fed In5 with pink noise in level steps. The rider ran with its defaults except for a gain
window up to +6 dB.

```bash
ddev exec bin/console trackdsp:ride in5 --max=6 --allow-boost
```

| Time | Step | Rider gain | Average | Status |
|---|---|---|---|---|
| 0 to 60 s | start level | -6.2 dB | -17.3 dB | steady after 18 s |
| 60 to 120 s | 10 dB quieter | +2.2 dB | -18.7 dB | steady after 17 s |
| 120 to 180 s | 16 dB louder | -12.2 dB | -17.3 dB | steady after 30 s |
| 180 to 210 s | silence | -12.2 dB | | silent |
| 210 to 260 s | 10 dB quieter | +2.0 dB | -18.9 dB | steady after 29 s |

- The rider holds the target within its deadband of 1 dB and stops at its edge, 0.7 dB off the target.
- The gain never changed faster than 0.5 dB per second, so the step of 14.4 dB took 29 s.
- The gain stayed frozen through the silence.
- The UMC1820 passes its own inputs to its MAIN outputs. Fed from MAIN OUT, the mics went to In5 as well, which
  raised the floor to -63 dB and closed a feedback loop over the Tecnare once the MAIN knob was turned up. The
  line outputs carry only the playback.
- In the rig the rider belongs on the console inputs IN3 and IN4, before the bus compressor on Out1 and Out2.
  It works on the gain and the meter of the input it rides, so the loop back into IN1 and IN2 does not reach
  it. On IN1 and IN2 it would undo the bus compression. A rider rides one channel and knows no links, so a
  stereo pair needs a rider for a link group first (TODO.md).

## Transfer function

`trackdsp:measure --reference` ([automation.md](automation.md#transfer-function)) analysed recordings from
the bench and from the speaker tests again on 2026-09-29, with no new measurements. The bench recording has Out6
as the reference on IN6. The speaker recordings have Out7 as the reference on IN5. The mics stood about 1 m in
front of each cabinet, somewhat farther for the later runs, and 1 m of air accounts for about 2.9 ms.

```bash
ddev exec bin/trackdsp measure -c 5,6 --reference=6 --wav=var/measure/b1-out7-vs-out6.wav
ddev exec bin/trackdsp measure -c 1,2,5 --reference=5 --wav=var/measure/s4-s2-tecL-hf.wav
```

| Recording | Mic 1 delay | Mic 2 delay | Polarity | Coherent range |
|---|---|---|---|---|
| Out7 against Out6 on the bench | 0.00 samples | | normal | 20 Hz to 20 kHz |
| Tecnare L woofer, Out1 | 4.33 ms | 4.33 ms | unclear | 100 Hz to 4 kHz |
| Tecnare L horn, Out2 | 3.92 ms | 3.92 ms | inverted | 1 to 16 kHz |
| Flexy, Out3 | 12.22 ms | 12.29 ms | inverted | 31.5 Hz to 2.5 kHz |
| SKRAM, Out4 | 7.73 ms | 6.72 ms | inverted and unclear | 20 Hz to 2.5 kHz |
| Achenbach 18, Out6 | 3.28 ms | 3.31 ms | normal | 31.5 Hz to 4 kHz |

- The bench loopback reads 0 samples and 0° in every band, with γ² 1.00 from 20 Hz to 20 kHz. Its magnitude is
  +0.2 to +0.3 dB, the preamp difference that B1 found as well. That is the flat case of T1.
- The two mics agree within 0.07 ms on every cabinet except the SKRAM, where they differ by 1.0 ms. A sub's
  impulse response has a broad peak, so its delay is weaker than that of a top.
- The horn arrives 0.41 ms before the woofer. Both ways carry an LK-24 filter at 2520 Hz, and the filter's group
  delay is part of each reading.
- The horn reads inverted and the woofer unclear. The high-pass at 2520 Hz alone turns the horn's phase by 180°
  at its corner, so the peak phase does not tell whether the horn is wired inverted. The phase of both ways in
  the crossover region decides that (T4).
- The subs played full range, so their delays include the group delay of the cabinet, which is long for a
  horn-loaded sub. The Flexy arrives 9 ms after the Achenbach 18. The system tuning aligns
  subs by their phase at the crossover rather than by the peak.
- The table is not an alignment yet. Every delay includes any delay set on the output, and the rig's crossover
  in the DCX2496 was not in the chain.
