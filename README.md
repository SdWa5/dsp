# trackdsp

Editor, command line and PHP library for the Thomann **t.racks 8x8 Matrix** DSP on Linux.
It talks to the device over **USB** (hidraw) or **LAN** (TCP 9761) with the protocol reverse-engineered from the
Windows vendor software "the t.racks 8x8 Matrix Digital Processor Editor". The protocol is described in
[docs/protocol.md](docs/protocol.md).

## Status

This branch rewrites the first implementation (Python and PySide6, kept in [legacy/python/](legacy/python/)) on
Symfony. The protocol library, the device model, the command line, the device bridge and the JSON API are done and
tested against the vendor editor captures and the live device over LAN. The browser editor covers the main vendor
functions and adds gain riders and Auto EQ. The [vendor inventory audit](docs/vendor-parity.md) is complete
under its agreed outcome rule. It covers all 22 tabs, 34 named forms and 12 observed menus. Of 73 control
groups, 49 are matched and 24 have concrete unresolved TODOs; there are no confirmed implementation gaps.
This is measured inventory coverage, not certification of full hardware parity. Twelve surfaces lack screenshots;
their evidence procedures remain recorded. Connected-device checks are postponed until a later hardware session.
Browser tests use a simulated device, synthetic recordings and a mocked event stream; page-by-page device checks
and packaging remain in [TODO.md](TODO.md).

## Requirements

- Development: [ddev](https://ddev.com/) (PHP 8.4 in a container, no PHP on the host needed)
- Runtime: PHP 8.4 with PHP-FPM and Caddy, as on the Khadas VIM3 ([docs/vim3.md](docs/vim3.md)). Planned: the
  FrankenPHP static binary, no system PHP
- USB access without root: the udev rule in [udev/70-trackdsp.rules](udev/70-trackdsp.rules)
- Auto EQ and measurements: `arecord` (alsa-utils, ddev has it), a measurement mic and an audio interface

## Installation

```bash
git clone git@github.com:GitiGlitzer/dsp_linux_8x8.git
cd dsp_linux_8x8
ddev start
ddev composer install
```

For USB, install the udev rule once:

```bash
sudo cp udev/70-trackdsp.rules /etc/udev/rules.d/
sudo udevadm control --reload-rules && sudo udevadm trigger
```

Remove an older copy named `99-trackdsp.rules` first. Its number came after systemd's `73-seat-late.rules`, so
the desktop user never got access, only the group `plugdev`.

ddev reaches an 8x8 on the laptop's USB through [.ddev/docker-compose.hidraw.yaml](.ddev/docker-compose.hidraw.yaml).
It mounts the host's `/dev` at `/run/host-dev`, so a replugged device shows up without a restart, and allows
the hidraw devices only. The container runs as the desktop user, so the udev rule above is all it needs. The
hidraw major number is assigned at boot. If `awk '$2=="hidraw"' /proc/devices` shows another value than 242,
set it as `TRACKDSP_HIDRAW_MAJOR` in `.ddev/.env` and run `ddev restart`.

ddev records from the laptop's sound cards through [.ddev/docker-compose.alsa.yaml](.ddev/docker-compose.alsa.yaml).
It binds the host's `/dev/snd` and allows the ALSA devices only, so a replugged interface also shows up without a
restart. Inside ddev `default` is the laptop's own codec, so name the interface in `.env.local`, for example
`TRACKDSP_CAPTURE_DEVICE=plughw:CARD=UMC1820,DEV=0` (`arecord -l` lists the cards). PipeWire opens a card whenever a
desktop stream uses it, so hand the interface to ALSA before measuring with `pactl set-card-profile <card> off`
(`pactl list cards short` names the card) and switch its profile back on afterwards.

The appliance setup on a Khadas VIM3 (bridge, PHP-FPM, Caddy, systemd units) is described in
[docs/vim3.md](docs/vim3.md), the configuration files are in [deploy/vim3/](deploy/vim3/).

## Usage

### Command line

`bin/trackdsp` is the short form of `bin/console trackdsp:<command>`.

```bash
bin/trackdsp discover                  # devices on USB and the local networks
bin/trackdsp info -t 192.168.68.10     # device, IP, active preset
bin/trackdsp presets                   # preset names U01..U20, F00, and which slots are stored
bin/trackdsp status                    # mute state of all channels, MUTED(gain) for a gain at raw 0
bin/trackdsp gain in1 -6               # boosts above 0 dB only with --allow-boost, -60 mutes
bin/trackdsp mute out1                 # and unmute out1
bin/trackdsp phase out3 invert         # or normal
bin/trackdsp route out3 1,2            # Out3 gets In1 + In2, "none" for no input
bin/trackdsp dump config.bin           # raw configuration, hex without a file
bin/trackdsp meters                    # levels in dB on the threshold scale, --raw for the bytes
```

The target is `auto` (the first device that discovery finds, USB before the network), `usb` (first USB device),
`usb:/dev/hidrawN`, `demo` (simulated device) or `host[:port]`. Pass it with `-t` or set `TRACKDSP_TARGET` in
`.env.local`. Discovery searches `TRACKDSP_DISCOVERY_NETWORKS` (comma-separated CIDRs such as
`192.168.68.0/22`), by default the networks of the local interfaces. Inside ddev, run the commands with
`ddev exec` and set the LAN in `.env.local`, since the container only sees its own network.

The device accepts only a few TCP connections. While the vendor editor is connected, new connections time out
although the device answers ping. Close the vendor editor first.

### Browser editor

`https://trackdsp.ddev.site` in ddev. It has the Gain, Gate, Comp, Delay, Matrix, Aux and Auto EQ pages, a tab per
input and output with the EQ curve, live meters and the File, Edit, Device, Channels, Tools and Help menus. The Comp page
also holds the Aux compressor, after the outputs, and Sub Control on the Gain page and in its dialog holds the Aux
gain, mute and separate input/output routing selectors between its input and output strip. An input's tab also carries its gate, and an
output's tab its compressor and delay, with the same controls as on the Gate, Comp and Delay pages. The editor
talks to the bridge through the JSON API below. A ddev with another `project_tld` needs its host names in
`TRACKDSP_TRUSTED_HOSTS` in `.env.local`, or the app answers 400 "Untrusted Host".

- Help > Language chooses English, Deutsch or Automatic. A choice is remembered for this browser. Automatic
  uses `TRACKDSP_LOCALE=en` or `de` from `.env.local`, then the browser language, then English. The CLI and
  messages returned by the bridge and API stay in English. See [docs/editor.md](docs/editor.md).
- File > Open preset file inspects a trackdsp JSON file or vendor `.prs` file without a bridge or connected
  device. The read-only dialog shows channel settings and Aux, Sub, Group and rider settings independently of
  live state. Apply to device is a separate confirmed action, available when connected. The two inspected `.prs` samples carry no test tone, delay unit or EQ bypass, so those
  stay as they are. The second sample saved with these controls changed is byte-identical to the first.
  The user observed that reopening offline in the vendor editor resets test tone to its default while EQ
  bypass and delay unit stay unchanged. This observation does not establish device writes.
  Save preset file writes JSON, including the running gain riders, which applying the file brings back. In demo
  mode Apply sets up a preset on the simulated device.
- A named channel shows its name next to its ID wherever the ID appears, for example "Out1 Main L" or
  "Out1 (Main L)" in lists. A name that only repeats the ID is left out.
- The channel tabs show a dot for a muted channel, a hollow one for a channel whose gain mutes it, and the link
  group. Display Settings and the Link dialog set how a link group shows on the tabs and in the Link matrix, kept
  in the browser. The code (L1, L2, ..., on by default), an icon per group (circle, square, triangle, diamond) and a
  colour per group combine freely, and code and icon stand next to each other. The placement of linked channels is
  in channel order, side by side, or stacked per link group in one column. Stacked puts a header with the group's
  code, icon and colour above each group column, and the members share the column height equally.
- A setting changed on a linked channel goes to every channel of its group, on every page: gain, mute, phase,
  mode and +48V, gate, compressor, delay, FBQ, PEQ bands, high and low pass and EQ bypass. Only the name stays
  per channel. This also holds in demo mode.
- The link display and the placement also apply inside the pages. Every channel label outside the Link dialog
  carries its group's code, icon and colour. The strips of Gain, Gate, Comp and Delay, the EQ overview switches,
  the route buttons of Sub Control, Group Control and the Aux, the Auto EQ outputs, the matrix and the routing
  graph follow the placement. Link colours fill group frames, including padding; member controls keep their own
  backgrounds. Stacked gathers a group under one header, and strips, being too tall to stack, form
  a block that runs across. The matrix leaves a gap around each group and heads it with one bar outside the
  channel headers, and the routing graph frames each group under a head. Either way the code shows once per group.
- The header’s Display Settings button opens `#display`, the complete browser display overview. Its sections cover
  appearance and language, channel visibility, link appearance, grouping and names with shared colour marks, meter thresholds,
  EQ plot options and curve selection with shared link-group placement, matrix orientation and shared panel expansion. Local theme, language,
  plot, Link-dialog and panel controls remain synchronized shortcuts. All seven settings sections fold
  through their headings and retain their state per browser. Translated tooltips explain every display
  control, including browser group names and shared panel choices. Existing preferences are preserved;
  changes stay available for the current visit when storage is blocked. Display preferences send no device
  writes. Channel names are also editable here through the existing
  device controls and Names dialog shortcut. Input, Output and Aux names have separate rows; link-group names
  use Input and Output rows in Display Settings and the Link dialog, and panel choices use one row per page context. Controls wrap within their rows. Name edits write to the connected device and belong to presets.
- Grouped channel tabs and Gain EQ overview switches show every member’s channel name and update after any
  member rename, including hidden members. Shared channel captions show all IDs once before the names.
  Turning grouping off restores individual captions. Channel-page titles omit repeated device names; grouped
  linked titles use an editable group-name field without a repeated label. Every linked member has one ID/name
  field pair below, including hidden members. The browser group name stays synchronized with Display Settings
  and the Link dialog, and each device-name field renames its own channel only.
- The Channels section hides chosen inputs, outputs and Aux in this browser. Channel Visibility has its own
  heading and Input, Output and Aux columns, matching Gain EQ overview and Channel EQ plots. Hidden channels stay
  active, and linked edits still reach them. The option "Mute hidden channels", off by default, mutes a channel on
  the device when this browser hides it. Showing it again or switching the option off never unmutes it. It writes
  that channel only, not its link group. Hidden detail pages offer Unhide channel and
  Open Gain page. Show all restores every channel, while section-header buttons restore inputs or outputs
  independently. The page follows existing link colours, icons, ordering and stacked placement, and its
  group-header checkboxes show mixed visibility and restore or hide every member of the group. With grouping
  enabled, visibility and overview selection show one toggle per group, and Channel EQ plots show one mixed-state
  set of preferences per group. Hidden members remain included. Turning grouping off restores individual controls
  without changing stored choices.
- One control per link group shows the lowest visible member's processing strip, header tab and Gain EQ switch,
  labelled with group members. There it takes the place of the placement, and a note below the placement options
  says so, since the placement then orders only the Matrix, the routing graph and the selector lists. Matrix and routing graph keep individual visible channels. Routing and membership
  selectors (Sub and Group Control, the Aux routes, AutoMix and Camera Select, the Auto EQ outputs) leave out
  hidden channels, as the matrix does, and put mixed-state group checkboxes in their group headers, which still
  include hidden members. The Auto EQ neither measures nor writes a hidden output, even when its box is checked. Group control labels show the group name and icon; their tooltips identify members.
  Enabling grouping preserves channel order and stacked placement. Auto EQ group
  selection changes its form only. Preferences survive reloads, or last for the visit if storage is blocked.
- A link group can carry a name of up to 12 characters, typed in the Link dialog below the matrices. The name
  stands wherever the code would (L1, L2, ...), so it shows when "Group code or name" is on. It is kept in the
  browser with the other display settings and stays with the group's lowest channel, so it survives when other
  groups change.
- The Link dialog is a channel x channel matrix per side. A cell links its row and column channel, and linked
  channels form a group that lights as a block. The lowest channel of a group is copied to the others. The corner
  button cycles three layouts, kept per browser and also selectable in the Link Groups card of Display Settings. The lower half (the default) shows each channel pair once with the
  column headers below it, the upper half mirrors it on the diagonal with the row headers on the right, and the full
  matrix shows both halves. In a half a lit cell takes the later channel of its pair out of its group, in the full
  matrix the column channel. Pointing at a cell or a header fades every channel a click there would not change. The
  rows and columns follow the placement after every click, and stacked turns each group into a block with one
  header naming its members and a gap before and after it. The display settings sit below the two matrices as well.
- Input, output and Aux detail pages hold Bands and Selected band in one PEQ section. PEQ folds both, and each of
  the two still folds on its own. Gain comes next. HPF/LPF follow where
  available, stacked vertically, then Gate or Comp and the remaining channel-specific sections.
- Every setting that differs from the factory default (`resources/factory_config.bin`) has a bar on its left
  edge. Every page tab shows the number of its changed settings as a badge, and every channel tab shows a corner
  triangle when its settings differ. The tooltip counts the changed settings. Auto EQ tracks output PEQ bands and EQ bypass. The marks clear when values return to
  their defaults and read without colour. An input gain counts from 0 dB, not from the factory's muting bottom
  step, and link groups never count. Display Settings > Changed settings switches the marks and the counts off,
  each on its own, per browser.
- A click on a changed mark resets what it marks to the factory default. The bar of a field resets that field at
  once, and linked channels follow. The triangle of a channel tab resets the channel with its linked channels,
  and the bar of a page tab resets that page on every channel, hidden ones included. Both ask first and say how
  many settings change. Names and link groups are never reset. Every reset is one undo step. On touch a tap on
  the left edge of a field hits its bar.
- Hovering a control shows what it does and its unit, in English or German. Every fader, switch and list shows
  the same tooltip on every page and in every dialog. Every section and dialog title says what the section holds,
  and the ones with abbreviations spell them out, for example FBQ, HPF, LPF, PEQ, Sub Control and Group Control.
  The device ID and the connection button in the top bar and every element of the footer have tooltips as well.
- The store and recall dialogs show which user slots are empty. An empty slot cannot be recalled.
- A fader value can be typed, with or without its unit ("-6", "2k", "200ms", "1:4"). Double-clicking any fader
  restores its factory default. Gain keeps its unity override; delay returns to no delay.
- A gain at its lowest step shows as "Muted", because raw 0 mutes the channel although the vendor editor shows
  it as -60 dB. Typing "mute" sets it. -59.5 dB is the lowest gain that passes signal. The channel's Mute button lights up then as well,
  without setting the mute flag. It is outlined in red then, where its own mute fills it.
- The other way round, a muted channel turns its gain fader's knob red, on every page, in Sub Control and in
  Group Control. A gain at the bottom turns the knob into a red ring instead, so a filled red knob means the Mute
  button mutes and a ring means the gain does. Only the knob changes, the track keeps its colour. The fader's
  tooltip says when its Mute button mutes it.
- A setting that differs from the factory default is marked in violet, far from the mute red.
- A compressor that is off, at ratio 1:1.0 or with its threshold at +20 dB, turns the knobs of all its faders red,
  on the Comp page, the output tabs, the Aux page and in the Aux compressor dialog.
- An output delay fader's knob turns red at 0, no delay, on the Delay page and the output tabs.
- The knobs of the selected band's faders turn red while the band does nothing, bypassed by its own Bypass or by EQ
  Bypass, or a Peak or shelf at 0 dB. A HighPass or LowPass frequency knob turns red while its Bypass is on. EQ
  Bypass leaves the filter knobs as they are until it is measured whether it takes out the HPF and LPF. The band's
  point and the filter's square on the EQ curve turn red with them, so no band point uses red as its own colour.
- The meters hold their peak for 1.5 s and light a red top for 2 s after reaching 0 dB.
- Every meter marks a threshold with a dashed line, the gate threshold on the inputs in cyan and the compressor
  threshold on the outputs and the Aux in orange. Display Settings > Meters switches the mark off or shows it for every
  gate and compressor, by default only for the ones that work. A gate works above -90 dB, a compressor at a ratio
  other than 1:1.0 with its threshold below +20 dB. A threshold off the meter's -60 to +12 dB shows no mark.
- Output meters have an orange Limit lamp above the red top, lit while the device reports the output's limiter
  working. The captures show that byte of the meter reply, but the device has not confirmed its meaning yet.
- Every EQ curve shows the magnitude, the phase or both, chosen with Mag and Phase beside it and kept per channel
  in the browser. The phase reads in degrees from -180° to +180°, on the right axis while the magnitude shows too,
  and includes the HPF and LPF.
- Show all filters labels PEQ and HPF/LPF values, bypass state and the selected crossover type. PEQ labels use the same idle red as their knobs. The wheel on a PEQ point changes Q; on HPF/LPF it changes type without enabling a bypassed filter. Double-clicking a point toggles its bypass.
- While a PEQ band, the HPF or the LPF is being edited, by dragging its point or with one of its controls in focus,
  the curve shows that filter alone in grey behind the channel curve, with the area to the 0 dB line shaded.
  The grey curve stays after letting go, until a click lands elsewhere. Clicking into a field of a band's row selects
  that band. Pointing at a filter's point, its table row, the selected band's faders or a filter's group does the same and
  greys out the points and controls of every other filter.
- The Gain page starts with an EQ overview, the EQ curves of every input (solid), output (dashed) and the Aux (dotted) in one plot by default.
  Separate plots in Display Settings or beside Mag and Phase switches to labelled Input, Output and Aux plots.
  They share the existing curve selections and axes, arrange in columns on desktop and stack on narrow screens.
  Empty plots explain that no visible curves are selected. The layout preference stays in this browser and sends no device writes.
  Mag and Phase switch the plot as on a channel tab. A switch per channel, or per link group when grouping is
  enabled, shows or hides its curves, kept in the browser. It changes nothing on the device. Linked channels share one EQ, so their curves and switches share one
  colour, the group's while Channels shows link colours, else the first member's. Pointing at a curve or a switch
  greys out every other curve and switch, except the rest of its link group. With stacked placement the whole
  block of a link group works as its switch.
- The Gain page also carries Sub Control next to the channel strips and Group Control below them, the same
  controls as in their dialogs.
- Edit > Undo and Redo, or Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z and Ctrl+Y, step through up to 100 device edits of this
  browser session. A drag, a group selection, a preset recall, an applied preset file or Auto EQ Apply counts as
  one action, and linked members are undone together. Replay restores only the changed fields and bits, so later
  changes elsewhere stay. If a setting changed since, the step is refused and the setting named. Display
  preferences, navigation and incoming device state are not recorded. A reload, disconnect or target change
  clears history. See [docs/editor.md](docs/editor.md#undo-and-redo).
- The Gain page starts and shows the gain riders. A rider on a linked input rides its link group as one, and
  starting, changing or stopping it on any member does so for the whole group. A user preset stores the running
  riders and brings them back on recall, and the factory default runs with none.
  The Auto EQ page leads through measure, fit, apply and verify. See [docs/automation.md](docs/automation.md).
- The matrix and the routing graph each fold under their own title. The matrix includes Aux as both a source
  and a destination, last on both axes. Its corner button swaps rows and columns and keeps the choice in the
  browser. Eight input-to-Aux cells use Aux's input selection; Aux-to-output cells use each output's Aux source
  bit. Aux cannot feed itself, so that intersection is disabled. The Aux page and Sub Control expose the same routes in two
  selector columns, also shared by the Gain page and Sub Control dialog.
- The routing graph places inputs on the left and outputs on the right, with Aux between them in a separate row below all channels. It displays direct
  input-to-output, input-to-Aux and Aux-to-output connections. Direct lines avoid the Aux box. Hovering a cell
  or line highlights its connection and endpoints in both views; hovering Aux highlights both its incoming
  and outgoing connections. Link placement and group headings apply to inputs and outputs; Aux stays independent.
  Drag between compatible channel nodes in either direction to toggle a connection. A preview follows the
  pointer and valid destinations light up. Double-click a connection to remove it. Keyboard users can select
  endpoints with Enter or Space, cancel with Escape, or remove a focused connection with Delete.
  Touch drags capture the pointer and prevent scrolling from nodes; blank graph space remains scrollable.
  Edits preserve unrelated mask bits and use the same per-destination commands as the matrix.
- The Gate and Comp pages plot the curve of a gate or compressor above their strips, input level against output
  level from -100 to +20 dB with the threshold as a red dot, as the vendor editor does. The plot follows the strip
  touched last, which is outlined, and starts at In1 and Out1. Every input tab draws its gate and every output tab
  its compressor small beside the faders. The Aux tab places its compressor curve to the right of the threshold
  fader, with Set below the fader. The Aux compressor dialog places the curve beside its faders on desktop
  and wraps it below on narrow screens, as the vendor's Compressor Setting dialog does on desktop.
- The compressors offer threshold, ratio, attack and release, as the vendor editor does. The byte called knee
  keeps the value the device holds until a measurement shows what it does.
- An output at compressor ratio 1:1.0 with a gain other than 0 dB is flagged on its strip on the Comp page and on its own tab. Two
  buttons set 1:1.1 from +20 dB, one keeping the gain value ("Exact gain") and one moving the gain to the level
  heard before ("Keep level"). Each button names the gain it sets, and "Keep level" says how far it falls short
  where the level lies beyond +12 dB. "Keep threshold" makes them leave the threshold as set. On a linked output
  the buttons fix the whole link group. The Auto EQ page and `trackdsp:autoeq` warn about it before
  measuring.
- On a channel tab, EQ Bypass is red while it is on, like a band's Bypass, and every band's Bypass then shows red too,
  with a tooltip that EQ Bypass does it. Each band keeps its own bypass, so switching EQ Bypass off brings it back.
- Every page with more than one panel folds them down to their title: the panels of the Gain page, the steps of
  the Auto EQ page, and on the channel and Aux pages the EQ curve, the band table and each group beside it. Folded
  panels are kept in the browser. All channel pages fold together, so a folded HighPass stays folded on every
  channel.
- The theme button switches between auto, light and dark. The choice is kept in the browser.

### Bridge and JSON API

The web editor reaches the device through `trackdsp:bridge`, which owns the connection, merges fast writes and
publishes state and meters. In ddev it runs by itself, the first time on the simulated device. The connect
dialog of the editor, or `POST /api/connect` with `{"target":"usb"}`, switches it to an 8x8 on the laptop's USB.
Its Demo button switches it back to the simulated device, which the dialog's target field does not offer.
It keeps that choice in `var/bridge.target.json` and starts on it again after a restart. While it is on the
simulated device, the editor shows "Demo" instead of "Online" and a banner saying that nothing reaches an 8x8.
The simulated device keeps stored presets in memory until the bridge restarts, and a recall of F00 restores the
factory default from `resources/factory_config.bin`.

```bash
bin/console trackdsp:bridge -t 192.168.68.10                  # outside ddev
curl -s localhost/api/state                                   # inside ddev: ddev exec curl ...
curl -s -XPOST localhost/api/write -d '{"command":"mute","args":{"channel":8,"on":true}}'
curl -s -XPOST localhost/api/connect -d '{"target":"192.168.68.10"}'
curl -sN localhost/api/events                                 # server-sent events: status, state, meters
```

Writes need the header `X-Trackdsp`, and `TRACKDSP_TRUSTED_HOSTS` lists the host names the app answers to.
The socket protocol and every endpoint are described in [docs/bridge.md](docs/bridge.md).

### Gain rider, compressor, auto EQ and measurements

```bash
bin/console trackdsp:ride in1 --level=-18                          # the bridge keeps the DJ input at -18 dB
bin/console trackdsp:ride --list                                   # and in1 --stop ends it
bin/console trackdsp:compressor out1,out2 --ratio=4 --threshold=-6 # the other values stay as they are
bin/console trackdsp:autoeq out1,out2 --device=plughw:CARD=UMC1820,DEV=0 --positions=3   # needs the bridge
bin/console trackdsp:autoeq out1,out2 --revert=var/autoeq/backup-<time>.json
bin/console trackdsp:measure out3 -c 1,6 --trim=-20                # Out3 alone, mic on input 1, Out8's loopback on 6
bin/console trackdsp:measure out3 -c 1,6 --reference=6            # plus delay, polarity and phase against the loopback
bin/console trackdsp:measure -c 1,6 --wav=var/measure/<name>.wav   # analyse a recording again, no bridge needed
bin/console trackdsp:measure --restore                             # put the device back after a crashed measurement
```

The gain riders run inside the bridge and come back after a restart. The auto EQ plays the device's pink noise,
records the measurement mic, fits up to 7 PEQ bands to a target and writes them only after confirmation. It records
from `TRACKDSP_CAPTURE_DEVICE` unless `--device` names another ALSA capture device. All three
are also in the browser editor. `trackdsp:measure` plays the test tone on some outputs alone while the reference
output keeps playing for the loopback, records the interface and prints levels and third-octave bands. With
`--reference` it adds the transfer function of every channel against the loopback, with delay, polarity,
coherence and the phase per band. It keeps
the recording and a JSON file of the results in `var/measure/` and puts the tone, the gains and the mutes back
afterwards, also after Ctrl+C or a device that drops off. What a crash leaves behind, `--restore` replays. Every option, the fitting rules and the solo rules are in [docs/automation.md](docs/automation.md).
The first measurements of the chain and of the 8x8 itself, with their commands and results, are in
[docs/measurements.md](docs/measurements.md).

### Library

```php
use App\Model\Device;
use App\Protocol\Commands;
use App\Protocol\Channel;

$dsp = $transportFactory->connect('192.168.68.10');     // App\Transport\TransportFactory, or 'usb', 'demo'
$device = $dsp->device();                               // parsed 1277-byte configuration
echo $device->presetName, ' ', $device->outputs[0]->name;
$dsp->write(Commands::mute(Channel::output(1), true));  // Out1 muted
file_put_contents('preset.json', $device->toJson());    // same file format as the Python editor
```

| Namespace | Content |
|---|---|
| `App\Protocol` | frame codec, opcodes, channel indices, scales, value labels, command builders |
| `App\Transport` | TCP, hidraw and demo transports, hidraw discovery, target factory |
| `App\Client` | `Matrix`: request/reply matching, retries with session re-init, reads and writes |
| `App\Model` | the configuration as objects, JSON and vendor `.prs` preset files, upload frames, `DeviceState` for applying writes, link groups |
| `App\Bridge` | the bridge loop, write queue, command allow-list and the socket client |
| `App\Discovery` | device search on USB and on the local networks |
| `App\Automation` | the gain rider |
| `App\Measurement` | FFT, WAV reader, spectrum and third-octave analysis, transfer function, levels, responses, calibration files, `arecord` recorder, the test tone, the solo plan and the playback that puts the device back |
| `App\AutoEq` | target curves, the PEQ fitter, and the measure, backup, apply and revert steps shared by the command and the browser session |
| `App\Command` | the `trackdsp:*` console commands |
| `App\Controller` | the browser editor, the JSON API, the auto EQ API and the event stream |
| `App\EventListener` | the header check for API writes |

## Development

Help includes a local RS232 command builder for documented serial packets, captured Group commands and raw CMD/data bytes. Group Matrix offers channel checkboxes and an editable mask byte. Its
output is copyable and it sends no device commands. EQ Reset asks before changing any band. PEQ views can display labels for all bands. About shows the version declared in `composer.json`.
The parity inventory can be regenerated and checked without a device.

```bash
ddev exec php tools/vendor-parity.php --write
ddev exec php tools/vendor-parity.php --check
ddev exec php tools/vendor-parity.php --require-complete
```

The last command fails for confirmed implementation gaps or unrecorded evidence outcomes. Explicit unknowns
with concrete TODOs satisfy the agreed inventory-audit completion rule; the gate does not certify hardware parity.

The bridge runs as a ddev daemon and keeps the code it was started with. After every `ddev exec` and
`ddev composer`, a hook in `.ddev/config.bridge.yaml` restarts it once `src/`, `config/`, the lock file or `.env`
changed since its start, unless device writes are queued. A restart reconnects to the last target and loses
presets stored on the simulated device. `ddev exec supervisorctl restart webextradaemons:trackdsp-bridge`
restarts it by hand.

```bash
ddev exec composer ci          # coding standard, PHPStan level 8, PHPUnit, browser code tests (node --test)
ddev exec composer cs-fix      # apply the Symfony coding standard
```

The tests need no device. They replay all 143 USB captures in [captures/](captures/) against the command builders,
check the model against the editor display and run the command line, the bridge (in the test process and as a
subprocess) and the JSON API against the simulated device. The measurement and fitting tests use synthetic pink noise through
known filters, and in the test environment a fake recorder stands in for `arecord`. The page test checks that every
binding, command template, preset button, controller and action of the browser editor exists.
Tests in the PHPUnit group `device` talk to real hardware and are excluded by default.
`ddev exec 'TRACKDSP_TEST_CAPTURE=plughw:CARD=UMC1820,DEV=0 vendor/bin/phpunit --group device'` records one second
from that capture device and needs a live signal on input 1, a connected mic is enough.

## Authors

- GitiGlitzer: protocol analysis, first implementation
- Stefan Ripper: Symfony rewrite

## License

No license has been chosen yet.
