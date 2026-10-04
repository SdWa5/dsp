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

Every fader puts the double-click default hint after its tooltip. A gain fader turns its knob red (`is-muted`)
while its gain sits at raw 0, the bottom, or while the mute named by `options.muted_by` is set, and the mute puts
"Muted by its Mute button" above its tooltip. A compressor fader with `options.comp`, the compressor's path, turns
its knob red while that compressor is off, by `compActive()` in `assets/lib/meters.js` (ratio 1:1.0 or threshold
+20 dB), and says so above its tooltip. An output delay fader (format `delay`) turns its knob red at 0 samples,
which is no delay, and puts "No delay" above its tooltip. The echo delays of the Effect dialog keep their colour at
0 ms, since an echo still repeats then. Only the knob changes colour, the track keeps its own. The sliders
therefore draw their own track and knob in `app.css`, because a native slider colours both with one
`accent-color`. The filled part of the track follows `--fill`, which `paintRange()` in `assets/lib/range.js` sets
from the value, and `knobMuted()` there decides the red knob. The toggle controller keeps the tooltip and
puts a state message, such as an unknown value or a mute by the gain, on its own line above it.

The PEQ faders are plain range inputs inside `.fader` wrappers, so `peq_controller.js` sets `is-muted` on them
itself in `refresh()` (`paintIdle()`). The selected band's three knobs turn red while `bandIdle()` in
`assets/lib/eq.js` says the band does nothing, `'bypass'` by its own Bypass or by EQ Bypass, `'flat'` for a Peak,
Low Shelf or High Shelf at 0 dB. Low Pass, High Pass and the Allpasses filter at any gain, so only their bypass
counts. A HighPass or LowPass frequency knob turns red while `filterIdle()` sees slope 0. EQ Bypass does not turn
the filter knobs red, since whether it takes out the HPF and LPF is not measured yet. The reason goes on a line of
its own above the fader's tooltip. The curve draws the band's point and the filter's square in the theme's
`--mute` red by the same rules, so band 1's own colour is orange rather than the red it had before.

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

Display Settings > Meters holds two settings, kept per browser under `trackdsp-meter-threshold`. "Show gate/comp
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

While a single filter is being edited, the channel tab draws it alone in grey behind the channel curve. The PEQ
controller counts a band, the HPF or the LPF as edited while its point is dragged or while one of its controls has
focus (`focusin` on the editor, read from the control's band or kind param, or the selected band for the "Selected
band" faders). Focus on a field of a band's row also selects that band, as its number button does, so the
"Selected band" faders follow the row being typed in. `singleFilter()` builds a channel that holds only that
filter, so `response()` draws it with the same bypass rules as the channel curve. Pointing works the same way. `pointermove` on the curve finds the point
under the pointer, and `pointerover` on the editor finds the band's table row, the "Selected band" group or the
filter group. A press on a filter's point or control holds that filter until a press lands elsewhere,
so the grey curve stays after a drag ends and the pointer moves on, and on a touch screen, where a range input takes
no focus and the pointer leaves as the finger lifts. A drag wins over pointing, then focus, then the last press. Only pointing and dragging grey out the other
filters' points, rows and groups, so a field in focus leaves the rest of the editor as it is. The area between it and the 0 line is shaded for the curve on the left
axis, the magnitude, or the phase while it shows alone.

The EQ overview colours a curve and its switch by channel number. Linked channels share one EQ, so
`curveColourSource()` in `assets/lib/overview.js` gives every member of a link group the same colour. That is the
group's `--group` colour from `app.css` while display settings enable link colours, read back from the switch's
`data-group`, else the colour of the group's first member. Pointing at a curve picks the nearest one within 6 px
(`nearestCurve()`), pointing at a switch picks its curve. One `pointerover` handler on the switches finds the
switch under the pointer, or else the link group's `.link-block` around it, so the block's head, border and gaps
pick the group too. A block whose switches all grey out greys out with them. Every curve and switch outside that curve's link group
(`linkedPaths()`) turns grey, and the greyed curves go below the others.

## Display Settings

The header’s Display Settings button opens `#display` without adding a DSP tab or device change count. The page stays
accessible when all channels are hidden. The settings entry sits outside Channels, which retains channel actions. Inline link and
meter controls and the old channel-display modal have been replaced by the page.

The page contains Appearance, Channels, Link Groups, Meters, EQ Plots, Matrix and Panels, in that order. Appearance
sets theme and language using the existing theme key and locale cookie. Link Groups also edits browser group
names with existing length and identity rules and shared colour/icon marks; link assignments stay in the Link dialog. EQ Plots shows overview
magnitude/phase and curve selection plus independent magnitude/phase/filter labels for all 17 channels, including
hidden channels. Hidden channels has its own heading. Visibility and Gain EQ overview use Input, Output and Aux
columns, matching Channel EQ plots and stacking on narrow screens. Grouping and Show all have separate rows. Input and output EQ controls follow shared link placement and use one group heading when
stacked. Grouping replaces linked member controls with one mixed-state Mag/Phase/Show all filters set,
including hidden members. Aux stays separate. Group overview selectors show mixed selections. Matrix exposes the existing orientation.
Channel names use separate Input, Output and Aux rows. Browser link-group names use separate Input and Output
rows. Panels groups choices by page context in separate rows and enumerates each rendered collapse key once;
shared channel sections retain one preference across pages. Controls wrap within each grouping row on narrow screens.
All seven settings sections use the shared collapse controller with distinct `display.*` keys and start expanded.
Their headings remain reachable when folded, their state survives reloads, and Panels also lists these keys.
Link colours fill the whole stacked group frame, including padding around compact selectors. Member controls
retain neutral backgrounds for legibility. Disabling link colours removes the fill, and dimmed overview groups
return to a neutral background.
Section headings and every preference control have English/German tooltips. Shared name, link and meter controls
retain that help in their shortcut dialogs. Generated group-name and panel choices carry contextual help without
rebuilding focused fields; aggregate selectors explain how selecting or clearing the whole group works.

`prefs.js` retains existing storage formats and adds `onPref(keys, listener)` with disconnect cleanup. Writes
keep a session copy even when storage is unavailable or throws. `displayprefs.js` shares EQ-label, overview and
collapse updates. Group EQ edits notify once per preference key and preserve other fields and channels.
Controllers subscribe so edits on the page and local shortcuts agree immediately. Theme,
language, plots, Link-dialog display controls, matrix swap and panel headers remain shortcuts.
Browser-only EQ choices and panel headers remain editable offline while processing controls stay disabled. A language change
retains its existing reload behavior. Group-name fields rebuild on membership changes rather than meter updates
and retain focus while typing. No preference action sends a device command or changes saved preset content. Channels also includes all 17
individual device name fields through the same partial as the Names dialog. These fields use existing channelName
commands, eight-character limits and live synchronization. They stay disabled offline, and the page explains
that name edits write device settings and are included in presets.

### Channel visibility and grouping

The Channels section keeps `{hidden, grouped}` under `trackdsp-channel-display`. Hidden indices
are individual input/output channels 0..15 or Aux at 18. All start visible and grouping starts disabled.
Malformed preferences fall back to those defaults. Storage failures retain changes for the current visit.
The settings send no API writes and do not affect changed-setting counts, presets or linked-write behavior.
Section-header Show all buttons restore inputs or outputs independently without unhiding Aux or the other side.
The global Show all restores every channel. Each section uses the shared link placement and marks while keeping
individual stored visibility for every member. With grouping disabled every member remains independently selectable;
with grouping enabled one mixed-state group toggle replaces linked member controls. Entirely hidden groups stay
available for recovery. Overview selectors follow the same rule, and unlinked channels and Aux remain individual.
Its group-header selectors show checked, unchecked or mixed visibility, select every member when mixed or empty,
and hide every member when fully selected. They reuse checkbox preference actions and send no device commands.

Visibility filters channel tabs, Gain/Gate/Comp/Delay strips and Gain EQ switches and curves. Matrix filters rows
and columns in both orientations. Visible Aux retains its disabled self-route; hiding Aux suppresses its row
and column. The graph compacts its outer columns,
removes edges with hidden endpoints and keeps visible Aux beneath them. Hiding Aux removes its bottom row.
Original channel indices, masks and group identities stay intact. Matrix and graph never combine link groups into a single channel.

One control per link group chooses the lowest visible member as representative. Its strip, tab and EQ switch
show group membership. Tabs and EQ switches use `displayChannelParts()` to list every member name in channel
order, including hidden members, with complete ID/name tooltips. Shared captions replace the leading ID with
the full member-ID list before the names, with no appended duplicate. Ungrouping restores the individual ID. Default names remain suppressed unless needed
to identify an unnamed member alongside renamed members. The shared renderer includes all member names in its
cache so a non-representative rename updates immediately. Fully hidden groups disappear from processing views. A visible non-representative's
individual detail URL remains usable and activates its representative's tab. An explicitly hidden channel URL
shows a warning with Unhide channel and Open Gain page. Channel names and link assignments remain individual.
Channel-page titles omit `.ch__name` because editable device-name fields already show those names.
`linkname_controller.js` puts the browser group-name input in the title row using `renderLinkNames()` with
member captions and repeated group-name marks suppressed. It retains shared identity, 12-character limits,
colour/icon marks, focus during edits and live synchronization with Display Settings and the Link dialog.
With grouping enabled the individual title ID is hidden, and every linked member appears below once with its
ID and its own device-name input, including globally hidden members. Inputs retain single-channel commands
and eight-character limits. Grouping off restores the current channel’s ID and one name field; the browser
group-name input remains available while linked and disappears when unlinked. Aux stays individual.
The inputs remain in the DOM while visibility changes so ordinary state updates preserve editing focus.
Gate and Comp follow plots recover to a visible representative when their selected strip disappears. With no
visible inputs Gate shows an empty state; with no visible outputs Comp displays Aux if visible, otherwise an empty state.

EQ overview curve selection remains separate under `trackdsp-eq-overview-hidden`. Group switches show mixed
selection and update every member, including hidden members, while global visibility still suppresses hidden
curves. Turning grouping off restores the individual switches without losing their selections.

The shared `selectors` controller adds group checkboxes to Aux input/output routes, Sub/Group membership,
AutoMix/Camera inputs and Auto EQ outputs. Individual controls remain complete even for hidden channels.
Group selectors appear in the existing stacked group headers. Normal and adjacent placement add headers at the
first member, preserving individual channel order. There is no separate aggregate selector bar. Headers retain
the chosen group name, colour and icon. Member IDs and hidden members appear in tooltips rather than repeating the individual channel labels.
Stacked channel tabs omit their individual group-edge shadow because their shared header already marks the group.
Mixed or empty groups select all members; fully selected groups clear all. Tooltips identify hidden members.
Shared masks produce one combined masked write; Aux output choices update each existing output mask separately,
preserving unrelated bits. Auto EQ aggregates update individual form checkboxes and have no submission name.

The shared channel editor places Bands, Selected band and Gain before HPF/LPF and channel-specific sections.
Inputs then show Gate and FBQ, outputs show Comp and Delay, and Aux shows Comp, Select, AutoMix, Effect and Camera.
Selected band sits directly below Bands in a shared vertical layout stack; both sections retain independent collapse controls.
HPF and LPF retain their shared vertical stack. Existing collapse keys and bindings are retained.

`ChannelDisplayTest` checks page inventory, menu order, templates, section order and translations. JavaScript tests cover normalization,
storage failures, representative changes, layouts and real bound-control aggregate writes. The isolated browser
regression intercepts every API request and checks 1440 px and 390 px views, hidden-page recovery, group selectors,
both matrix orientations, every link placement and settings/shortcut synchronization. It also checks
reloads, panel expansion, per-channel plot choices and storage fallback. Provide an installed Playwright module and a running editor.

```bash
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tools/test-channel-display.mjs
```

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

The Aux tab uses the same two-column dynamics layout as output channel sections. Its threshold fader and Set
button share the left column, and its 170 × 170 curve occupies the right column. The existing `aux.comp`
collapse preference folds both columns. The compressor dialog keeps its larger curve beside all four controls
when space permits and wraps it below them on narrow screens.

## Aux routing

Both matrix orientations have nine sources and nine destinations before visibility filtering. Visible Aux stays
last on each axis and outside
input/output link groups. Inputs feeding Aux bind to bits 0..7 of `aux.select` and send the existing routing
command for channel 18. Aux feeding an output binds to bit 8 of that output's `route` mask and sends its output
channel 8..15. Each toggle preserves the other mask bits. Routing keeps its existing per-output write behavior,
without adding the gain/PEQ controls' client-side linked writes. No API, protocol or preset format changes.

The Aux-to-Aux intersection has a disabled button with an explanatory translated label and no command binding.
The shared Aux selector template renders eight incoming and eight outgoing selectors in separate labelled
columns on the Aux page, Gain page and Sub Control dialog. Each column follows the corresponding input/output link placement independently.
All three views use the shared store.

The graph puts a single Aux node between input and output columns in a separate row below all their channels. `routingEdges()` accepts output masks and
`aux.select`; destination index 8 denotes Aux only inside the UI, not a ninth hardware output. `routingLayout()`
places Aux below both outer columns, and `routingPath()` keeps direct connections clear of its box.
`data-aux="1"` distinguishes the central node's two roles from a connection. Its hover lights both incoming and
outgoing routes; individual edges light their endpoints. Matrix headers keep their source or destination role.
The graph remains read-only. Missing routing masks produce no edges, and disconnected controls are disabled.

Regression tests cover matrix orientations and commands, both Sub Control views, translated labels, independent
incoming/outgoing masks, preserved bits, shared-store synchronization, hover behavior and all link placements.
The demo transport tests use isolated state and do not contact a shared bridge or hardware.

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
| Matrix | Output routing masks and Aux input selection |
| Aux | Every Aux setting with a factory default |
| Auto EQ | Output PEQ settings, band bypass and EQ bypass |

Auto EQ marks the resulting output EQ state even when there is no active measurement session. It has no factory
reference for recording positions or fit preferences. `pageChanges()` in `assets/lib/defaults.js` derives all
function-page marks from one state comparison. Tests cover each page, counts, restoring defaults and unknown values.

## Inspecting preset files

File > Open preset file opens JSON and vendor `.prs` settings in a read-only dialog, even without a bridge.
Choose a section to inspect each input, output, Aux, Aux functions, Sub/Group controls or saved gain riders.
The file owns its preview data; live events cannot replace it. Missing values show “Not stored in this file”,
including EQ bypass and delay units absent from `.prs`. A delay with no stored display unit uses milliseconds.
No imported value is sent or committed to the live store during inspection.

Apply to device is a separate button, enabled only with live state and a connected device. It asks for
confirmation, then uploads the original bytes through the existing validated upload endpoint and refreshes
live state. A parse error clears the prior preview and disables Apply. Both endpoints use the same parser and
command validation. Applying also replaces running gain riders with those saved in the file; a file without
riders stops them. Test tone and device identity remain outside the existing preset upload operation.

## Vendor parity and serial code generation

[Vendor parity](vendor-parity.md) records each known tab, form and menu and its control counterparts. The JSON
inventory is the source; `tools/vendor-parity.php --write` regenerates the report. Coverage checks compare the
recorded surfaces with the vendor dumps, verify screenshot paths and source counterparts, and reject stale
documentation. Unknown controls require a concrete verification recipe. Coverage does not certify parity or
hardware behavior. `--require-complete` accepts counterparts, evidenced exclusions and concrete unresolved
TODOs, and rejects confirmed implementation gaps or missing evidence outcomes.
The user postponed connected-device checks and captures until a later hardware session. No further screenshot queue is active. Unreachable forms and uncertain behavior remain individual TODOs,
with evidence procedures recorded in the completed inventory audit.

Help contains the serial command builder evidenced by `tools/shots/dlg_TfmRs232CmdEnglish.png`.
`assets/lib/rs232.js` encodes the separate nine-byte RS232 protocol, with named commands from manual chapter
6.1 and raw CMD/data bytes for the remaining vendor functions. The Stimulus serial controller generates a
read-only hex field locally and neither subscribes to the bridge nor sends packets. Gain values use raw
0..400 with a dB hint; channels are numbered 1..8 in the form and encoded from zero. Input masks use bits
0..7 for In1..In8. Factory is F00 and measured user slots are U01..U20; U21 remains an audit item.
Get Full Matrix uses the captured command 61 with three zero data bytes. Get Group Parameter, Group
volume, Group gain step, Group mute and Group matrix follow recorded vendor packets. Group indices
0..7 follow the Input1..4, Output1..4 choice list. Group Matrix offers channel checkboxes alongside an editable mask byte.
The single-channel shots establish In1 as bit 0 and In8 as bit 7 on both group sides. Invalid or incomplete values
clear the output.

Sibling EQ comparison is available in the Gain-page overview. Its channel switches overlay the same
PEQ/HPF/LPF response used on channel pages. The vendor batch shows the equivalent comparison on
individual input and output pages; trackdsp keeps this function in the shared overview.

About reads the declared version from `composer.json`, so a release change appears before dependency install.

Close the browser tab or window to leave the editor. This closes the browser view, not the separate bridge
process. The browser supplies the equivalent of the vendor desktop application’s Exit action.

EQ Reset asks for confirmation before changing a band, including bands on linked channels. Cancelling leaves
the live settings untouched. This matches the vendor confirmation in `tools/shots/dlg_TfmPeqRst.png`.

Show all filters displays frequency, Q and gain beside every PEQ band point, and frequency and slope
beside the HPF and LPF points, including bypassed filters. PEQ labels show effective band or global
bypass. PEQ value labels and the selected-band summary use the same bandIdle() rule as their knobs.
They turn red when bypassed or when a Peak or shelf has 0 dB gain.
Bypassed HPF/LPF labels retain the type selected in their slope selector, alongside Bypass.
Because the device encodes bypass as slope 0, this type is a browser choice, not a separate device readback. This extends the vendor SHOW ALL EQ labels to
all filters at the user’s request. Label visibility follows the display toggle rather than bypass state.
The preference is saved per channel in this browser and writes no DSP setting.

The wheel over a PEQ point changes Q. Over HPF/LPF it steps through the filter types in the slope selector,
clamped to its first and last type. A bypassed filter stays bypassed; changing its remembered type sends
no device command until it is enabled. Active filters use the existing linked-channel write path.

Double-clicking a PEQ, HPF or LPF point toggles its individual Bypass through the existing linked write path.
A global EQ Bypass still overrides PEQ bands. Blank plot space changes nothing; overlapping points choose
the closest handle, then the one painted last on a tie.

Double-clicking any fader restores its numeric factory default through the existing command path. Explicit
widget defaults take precedence, preserving gain unity (raw 280) and delay 0. PEQ frequency, Q and gain
use the selected band’s factory values; HPF/LPF use the channel’s factory frequency without altering bypass.
Defaults are clamped to the fader range. Disabled controls and missing defaults send nothing.
