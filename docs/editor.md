# Browser editor

The editor uses the bridge state for its controls. Browser preferences change how the controls are displayed.
They do not change the device settings. The device API and protocol are documented in [bridge.md](bridge.md).

## Language

Help > Language offers English, Deutsch and Automatic. English and Deutsch set the `trackdsp_locale` cookie
for one year at `/`, with `SameSite=Lax` and `Secure` on HTTPS. Changing language reloads the page. Automatic
removes this cookie and restores negotiation. The current page and the saved view preferences remain available.

The first supported locale wins, in this order.

1. The `trackdsp_locale` cookie, set by the menu.
2. `TRACKDSP_LOCALE` in the server environment or `.env.local`, when set to `en` or `de`.
3. The browser's `Accept-Language` header, including regional forms such as `de-AT`.
4. English when none of these supplies a supported language.

Unsupported cookie or server values fall through to the next choice. With an empty `TRACKDSP_LOCALE`, each
browser chooses its own language. The locale listener runs after routing and before Symfony's translation
listeners. It applies to the editor route only. API requests and CLI commands keep their existing English messages.
Errors, Auto EQ warnings and recording names returned by the API remain English until that API supplies message
codes and parameters. Channel IDs, names chosen by the user, protocol values and units are unchanged.

## Translation catalogues

`translations/messages.en.yaml` and `messages.de.yaml` hold menu keys, widget labels and explanatory text.
Menus use semantic keys. Reused widget labels use their English source text as the key. Widget macros translate
labels at rendering time and keep state paths and command arguments unchanged. Channel labels rendered as HTML
pass through unchanged.

`labels.en.yaml` and `labels.de.yaml` translate words in device option lists. Numbers, ratios and units use their
source values. `js.en.yaml` and `js.de.yaml` hold browser confirmations, connection messages and rider status.
The request's JS catalogue is embedded as JSON. `assets/lib/i18n.js` interpolates `%name%` parameters as literal
text, with English source text as fallback. Callers use textContent for messages. The translated gain value
"Stumm" parses back to mute, and the original "mute" input remains accepted.

## Tooltips

A widget macro (`fader`, `toggle`, `choice` in `templates/editor/_widgets.html.twig`) looks up its tooltip by its
state path without side, index and `aux`. So `outputs.3.comp.ratio` and `aux.comp.ratio` both show
`editor.tip.comp.ratio`, and `sub.0.gain` shows `editor.tip.sub.gain`. A path without a key renders no title.
`options.tip` names another key. Panels (`fold()`), dialog titles (`dialog()`), the band table headers and the
filter controls name their key under `editor.tip.panel` or `editor.tip` directly. The footer pills use
`editor.tip.foot`, and its Store and Recall buttons show the tooltip of the dialog they open. In the top bar the ID
shows `editor.tip.device_id` and the connection button `editor.tip.bar.online`. A tooltip says what the control
does in one sentence and names the unit where the value has one.

A fader with a default puts the double-click hint after its tooltip. A gain fader turns its knob red (`is-muted`)
while its gain sits at raw 0, the bottom, or while the mute named by `options.muted_by` is set, and the mute puts
"Muted by its Mute button" above its tooltip. A compressor fader with `options.comp`, the compressor's path, turns
its knob red while that compressor is off, by `compActive()` in `assets/lib/meters.js` (ratio 1:1.0 or threshold
+20 dB), and says so above its tooltip. Only the knob changes colour, the track keeps its own. The sliders
therefore draw their own track and knob in `app.css`, because a native slider colours both with one
`accent-color`. The filled part of the track follows `--fill`, which `paintRange()` in `assets/lib/range.js` sets
from the value, and `knobMuted()` there decides the red knob. The toggle controller keeps the tooltip and
puts a state message, such as an unknown value or a mute by the gain, on its own line above it.

`EditorTooltipTest` checks that every `editor.tip` text appears on some control in both languages, and that one
key serves the same control on every page and in the dialogs. It also checks the top bar status and every footer element.

`TranslationTest` checks matching catalogue keys and interpolation parameters. `EditorLocaleTest` checks English
and German rendering and that translated labels preserve protocol values. `LocaleListenerTest` checks precedence.
The JS tests check cookie lifetime, fallback, literal interpolation and the translated muted-gain round trip.
Node test files are excluded from AssetMapper. `EditorAssetsTest` compiles the public map and checks that the
browser helper is served while test files return 404.

```bash
ddev exec composer lint
ddev exec bin/console lint:twig templates
ddev exec composer ci
```

## Meters

Every meter marks the threshold of its channel's dynamics with a dashed line. Inputs mark their gate (cyan), the
outputs and the Aux their compressor (orange). Meter and thresholds share one dB scale (`Scale::meterDb()`), so
the mark sits where the meter reads the threshold. The tooltip adds the threshold below the level.

Channels > Meters holds two settings, kept per browser under `trackdsp-meter-threshold`. "Show gate/comp
threshold" switches the mark on, and "Only when active" restricts it to dynamics that work. The device has no
on switch for either, so `assets/lib/meters.js` decides from the values. A gate works above -90 dB (raw 0, the
factory value). A compressor works at a ratio other than 1:1.0 with its threshold below +20 dB, the two settings
`docs/protocol.md` names as off. A threshold below -60 or above +12 dB lies off the meter and shows no mark.

An output meter has an orange Limit lamp above its red top, where the vendor editor has its Limit segment. The
lamp lights while the bridge's `limiting` field, byte 51 of the meter reply, has the output's bit set (bit 0 =
Out1), and the tooltip then says "Limiter working". That reading of the byte rests on the captures, see
`docs/protocol.md`, and is not yet confirmed on the device. The simulated device sets the bit of an output whose
compressor works and whose level reaches its threshold. `trackdsp:meters` shows the same bit as LIMIT.

## EQ curve views

The EQ curve of a channel tab and the EQ overview on the Gain page each have a Mag and a Phase checkbox, which
show the magnitude, the phase or both. `eqView()` in `assets/lib/eq.js` decides the axes. The magnitude reads in dB
on the left. The phase alone reads in degrees on the left, and beside the magnitude it gets its own degree axis on
the right. Both share the grid, so ±180° lies on the ±18 dB lines and each 6 dB line is 60°. The choice is kept per
channel and for the overview in the `eq-view` preference.

The phase is that of the whole channel, the PEQ bands as biquads and the HPF and LPF from their analogue
Butterworth prototype (`crossoverResponse()`). LK is two Butterworths of half the order in series. BL is drawn as a
Butterworth at 0.8 times the frequency ratio, as its magnitude always was, so its phase is an approximation. The
phase wraps at ±180°. `curveRuns()` ends the line where it leaves one edge and starts it again at the opposite one,
so a steep filter winds through the plot instead of drawing vertical lines across it.

## Gate and compressor curves

`assets/lib/dynamics.js` computes the static curve and `dynamics_controller.js` draws it on a canvas, input level
against output level from -100 to +20 dB on the meter scale, with a grid every 20 dB and the threshold as a red
dot. This follows the vendor editor (`tools/shots/page_01_Gate.png`, `page_02_Comp.png`, `dlg_CompSet.png`). A
compressor keeps the level up to its threshold and divides the level above it by its ratio, Limit holds it at the
threshold. A gate keeps the level from its threshold up and drops to the floor below it.

The plot draws a hard knee, as the vendor editor does. On the device the limiter sets in about 4 dB before its
threshold and holds fully about 3 dB past it, and it holds a 100 Hz signal about 2.2 dB higher than a 1 kHz one
([measurements.md](measurements.md)). The ratios match their labels in the measured static curves.

The compressor has no Knee control. The vendor editor has none either and sends the byte as `00`, and nothing
measured says what the byte does. The send arguments carry the value the device holds, so it stays untouched.

The Gate and Comp pages wrap their curve and strips in one controller with `follow`. A `pointerdown` or `focusin`
inside an element with `data-dynamics-source` plots that channel and outlines its strip (`is-plotted`). The
curves on the channel tabs, the Aux tab and in the Aux compressor dialog are fixed to their channel. The plot is a
view and sends nothing. A drawing happens at most once per frame and only when the threshold, the ratio or the
title changed.

## Page change marks

Every function tab shows the number of its settings that differ from `resources/factory_config.bin` as a badge
next to its name, and its tooltip says the same in words. A channel tab marks a difference with a corner
triangle. The bridge state updates the marks for hidden pages too. Restoring
the defaults clears them. Missing or unreported values do not count. Theme, folds, link placement and graphical
curve switches do not affect these marks.

| Tab | Settings counted |
|---|---|
| Gain | Input and output gain, phase and mute, input mode, Sub Control and Group Control |
| Gate | Input gate settings |
| Comp | Output compressor settings |
| Delay | Output delays and the device delay unit |
| Matrix | Output routing masks |
| Aux | Every Aux setting with a factory default |
| Auto EQ | Output PEQ settings, band bypass and EQ bypass |

Auto EQ marks the resulting output EQ state even when there is no active measurement session. It has no factory
reference for recording positions or fit preferences. `pageChanges()` in `assets/lib/defaults.js` derives all
function-page marks from one state comparison. Tests cover each page, counts, restoring defaults and unknown values.
