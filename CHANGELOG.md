# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.78.0] - 2026-10-04

### Changed

- The matrix orientation in Display Settings is a single choice of the matrix rows, "Outputs and Aux" or "Inputs and Aux", instead of the "Inputs as matrix rows" checkbox, as the Link matrix layout already is. Each option has its own tooltip.
- "One control per link group" gets its own heading in the Link Groups card, so it no longer reads as a fourth placement.

### Fixed

- The PHP display and tooltip tests follow the moved grouping checkbox and the matrix radio pair. 0.77.0 had left two of them failing.

## [0.77.0] - 2026-10-04

### Changed

- "One control per link group" moves from the Channels card of Display Settings to the Link Groups card, below the placement. A `channelview` controller now writes only the visibility or the grouping it holds, so the two cards cannot overwrite each other.

## [0.76.0] - 2026-10-04

### Added

- The Link Groups card of Display Settings chooses the Link matrix layout (lower half, upper half or full matrix). It shares the browser preference with the corner button of the Link matrix, and the channel display browser test checks both directions.

## [0.75.0] - 2026-10-04

### Added

- The Link matrices get a corner button like the routing matrix. It cycles the lower half with the column headers below it (the default), the upper half mirrored on the diagonal with the row headers on the right, and the full matrix. The choice is kept per browser.
- In a half a lit cell takes the later channel of its pair out of its group, so every member stays removable. The full matrix keeps the column rule.
- Pointing at a Link matrix cell or header fades every channel a click there would not change, as on the Matrix page.
- Unit tests cover the half layouts, the click rule and the hover focus, and the channel display browser test checks the layouts, cell alignment and gaps in every placement.

### Fixed

- Stacked placement leaves a gap in the Link matrix only before and after a link group, no longer before every unlinked channel. Header backgrounds stay out of the gap, as in the routing matrix.
- Empty and lit Link matrix cells share their top edge instead of sitting 1 px apart on the text baseline.

## [0.74.0] - 2026-10-04

### Added

- An Edit menu with Undo, Redo and Clear History, and the shortcuts Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z and Ctrl+Y, step through up to 100 device edits of the browser session. Entries name the action and setting, disabled entries explain why, and results are announced in a live region. Text fields keep their native undo.
- Each drag, wheel burst, committed text edit, toggle, reset, group selection, gain correction, copy, link change, preset or factory recall, applied preset file, Auto EQ Apply or Revert and manual rider change is one action. Linked members, including hidden ones, are recorded together.
- `POST /api/history` and the bridge `history` op replay one step. They check every expected value, link membership and affected rider setting before queueing anything, restore only changed fields and mask bits, and rebuild compound commands from the current state.
- Regression tests cover history grouping, branching, limits, unknown values, queue failures, every editable command family, routing bits 0, 7 and 8, changed link membership, guarded rider replay and mocked desktop/mobile menu, shortcut and conflict behaviour.

- A ddev `post-exec` and `post-composer` hook restarts the bridge daemon once its code changed since it started, unless device writes are queued.

### Changed

- Incoming bridge state is kept apart from optimistic browser edits, and the send queue reports batch outcomes. Rider ticks pause while replayed frames are written.
- Display preferences, navigation, incoming state, meters and automatic rider movements never create history entries. A reload, disconnect or target change clears history, as does a failed write whose result cannot be read back.

## [0.73.0] - 2026-10-04

### Added

- The Matrix routing graph edits direct input/output and Aux connections by dragging from either endpoint, with a connection preview, compatible targets and mouse, touch and keyboard controls. Matrix and Aux selectors update immediately through the existing routing commands.
- Double-clicking a graph connection removes it. Focused connections also support keyboard removal. Edits preserve unrelated routing bits and independent destination behavior.
- The TODO list records medium-priority editor-wide undo and redo work.

## [0.72.0] - 2026-10-04

### Added

- The Gain-page EQ overview can show separate Input, Output and Aux plots. Display Settings and the local Separate plots shortcut synchronize immediately and preserve curve selections, channel visibility, link-group selections and magnitude/phase preferences. Combined remains the default.
- Separate overview plots have canvas-specific hover highlighting, responsive columns, empty-state messages and redraw correctly after resizing or expanding their panel.

## [0.71.1] - 2026-10-04

### Fixed

- The Link dialog separates browser group-name fields into labelled Input and Output rows. Fields wrap within their side on narrow screens, matching Display Settings.

## [0.71.0] - 2026-10-04

### Added

- Display Settings provides a dedicated browser preference page for appearance, channels, link groups, meters, EQ plots, matrix orientation and panel expansion. Each settings section folds independently and remembers its browser state.
- English/German tooltips explain all Display Settings controls and section headings, including generated group-name fields, group selectors and shared panel choices.
- Linked input/output detail pages provide a synchronized browser-only link-group name field, preserving shared marks, name limits and individual device rename bindings.
- Channel names are editable in the Channels section through shared device controls, with the Names dialog retained as a synchronized shortcut.
- Shared preference notifications synchronize page controls with local shortcuts, including channel-specific EQ choices, overview selections and group names.
- Regression tests cover preference compatibility, malformed storage, session fallback, translated page inventory and mocked desktop/mobile synchronization.

### Changed

- Display Settings separates channel names into Input, Output and Aux rows, link-group names into Input and Output rows, and panel choices into page rows. Controls within each row wrap together on narrow screens.
- Selected band controls sit below PEQ Bands on input, output and Aux pages, retaining independent collapse controls.
- Display Settings has its own header-menu entry. Channels retains channel actions. The page replaces the channel-display modal and inline link and meter options.
- One control per link group also consolidates Display Settings visibility, Gain EQ overview selections and channel EQ options. Mixed-state group controls include hidden members, retain individual stored choices and stay available for hidden-group recovery.
- Display preferences preserve existing keys and defaults. Hidden channels remain independently configurable, and shared panel keys appear once.

### Fixed

- Channel-page titles omit repeated names. The group-name input occupies the title row without a duplicate label. Grouped pages show one ID/name field pair for every linked member below, including hidden members, with no repeated leader ID. Every device rename keeps its individual binding.
- Grouped channel tabs and Gain EQ overview switches show every member name, including hidden members. Live member renames refresh their captions and tooltips. Shared channel captions put all member IDs once before the names, with no repeated suffix; individual display remains unchanged.
- Link colours fill whole stacked group frames, including padding around compact selectors. Member controls remain readable, disabling colours removes the fill, and dimmed overview groups remain neutral.
- Hidden channels has its own heading. Its Input, Output and Aux columns match Gain EQ overview and Channel EQ plots; columns stack on narrow screens. Grouping and Show all retain separate rows. Group-name fields share link colours and icons in both entry points.
- Channel EQ settings follow shared link ordering and stacked group headings without hiding individual preferences.
- Browser-only EQ display controls and panel headers remain editable while offline.
- Browser preference writes remain readable for the current visit when storage is unavailable or rejects a write.

## [0.70.0] - 2026-10-03

### Added

- Channels offers browser display settings for individual input/output/Aux visibility and one processing control per link group.
- Input and output section headers offer independent Show all actions. The visibility dialog follows link colours, icons, ordering and stacked placement and provides mixed-state group visibility checkboxes.
- Hidden channel pages offer an inline warning, Unhide channel and Open Gain page actions.
- Additional mixed-state group selectors cover Aux routing, Sub/Group membership, AutoMix, Camera and Auto EQ outputs while retaining all individual choices.
- Regression checks cover browser preferences, sparse layouts, representative changes, masked group writes, translations and mocked desktop/mobile views.
- TODOs record grouped Bands/Selected band sections, directly accessible Aux compressor controls, draggable dynamics thresholds and configurable dynamics curve displays.

### Changed

- Processing strips, channel tabs and Gain EQ switches use the lowest visible linked member when grouping is enabled. Group EQ switches retain every member's curve-selection preference.
- Matrix and routing graph filter hidden endpoints while keeping individual visible channels and original group identities. Visible Aux keeps its independent bottom row; hiding it removes its displayed graph edges and unused bottom row.
- Group selectors live in link-group headers throughout the UI, including mixed visibility selectors in the display dialog. Labels show group names and icons; tooltips identify members. Grouping preserves individual channel order and stacked placement.
- Stacked channel tabs omit the repeated coloured group-edge shadow beneath their shared header.
- Channel pages place Bands, Selected band and Gain first, then HPF/LPF and channel-specific sections in a consistent order. HPF and LPF retain their vertical stack, and existing control bindings and collapse keys remain unchanged.
- Gate follow plots recover to a visible representative or display an empty state. Comp follow plots fall back to visible Aux when all outputs are hidden, or show an empty state when Aux is hidden too.

## [0.69.1] - 2026-10-03

### Fixed

- The Aux page places its compressor curve beside the threshold fader, with the Set button below the fader,
  using the same layout as output channel compressor sections.

## [0.69.0] - 2026-10-03

### Added

- Both matrix orientations include Aux as a source and destination, with its unsupported self-route disabled.
- One shared Aux input/output selector view serves the Aux page, Gain page and Sub Control dialog.
- Regression tests cover Aux routing masks, shared views, translations, hover focus and link placement.
- A UI configurability TODO records per-browser controls to hide unused input and output channels.
- A highest-priority TODO records placing the Aux compressor plot beside its faders.

### Changed

- The read-only routing graph places Aux between input and output columns in a separate bottom row, displays both route directions and keeps direct lines clear of its node.
- Hovering the central Aux node highlights its incoming and outgoing routes in both matrix and graph.
- Matrix change marks include Aux input selection, using the existing factory defaults.

## [0.68.0] - 2026-10-03

### Added

- File Open inspects JSON and vendor preset files without a bridge or device. A read-only preview keeps missing values unknown and live settings unchanged; applying the original file is a separate confirmed action.
- A vendor parity inventory maps the 22 tabs, 34 named forms and observed menus to controls and explicit evidence gaps.
- A local coverage checker validates the inventory, screenshot paths, source counterparts and generated report.
- Help builds RS232 packets locally with named documented commands and captured Group commands, including mute and matrix channel masks, plus raw CMD/data bytes.
- About shows the application version declared in composer.json.
- EQ Reset asks for confirmation before changing any band in the shared input, output and Aux editor.
- Show all filters labels every PEQ band and both crossover filters, independent of bypass, saved per channel in the browser. Labels show effective bypass and retain the selected crossover type. PEQ labels use the same bypass or flat-gain red as their knobs.
- The wheel over HPF/LPF points changes filter type while preserving bypass, using the existing linked-channel controls.
- Double-clicking PEQ, HPF and LPF points toggles individual bypass through the shared channel controls.
- Every fader restores its factory default on double-click, with the existing gain-unity overrides preserved.

### Changed

- The inventory audit is complete with 49 matched groups, 24 concrete unresolved outcomes and no confirmed implementation gap. The completion checker follows that outcome rule; individual evidence TODOs replace the umbrella item. The README describes measured coverage instead of claiming every vendor dialog exists. Connected-device audit checks are recorded separately as postponed work.
- A second vendor preset sample saved with EQ bypass, test tone and feet selected is byte-identical to the original fixture. The preset documentation records both samples and the user-observed offline reopening behavior. Test tone resets to its default while EQ bypass and delay unit stay unchanged.
- The Windows screenshot batch records file choosers, Group command boundaries, sibling EQ overlays and the separate channel-name and preset-name popups. Equivalent comparison in the existing EQ overview closes that audit item.

### Fixed

- The scripted test bridge waits for listener readiness instead of socket-file existence, avoiding reproduced startup connection refusals without consuming scripted status answers.

## [0.67.0] - 2026-10-03

### Added

- Stacked link groups in the routing graph get a head with their code and colour, as the stacked strips have
- Hovering a link group's bar in the matrix or its head in the routing graph brings out its channels and their
  connections in both

### Fixed

- The matrix group bars no longer reach into the gaps between groups
- The link group headers of the stacked strips span the whole block

## [0.66.0] - 2026-10-03

### Added

- The matrix on the Matrix page has a title and folds like the routing graph

### Changed

- Stacked, the matrix heads each link group with one bar and the routing graph frames it, so the group code shows
  once per group instead of on every channel

## [0.65.0] - 2026-10-03

### Added

- Hovering on the Matrix page shows in the matrix and in the routing graph alike. A cell or a line brings out its
  connection and its two channels, a channel itself and its connections

## [0.64.0] - 2026-10-03

### Added

- An output delay fader's knob turns red at 0, no delay

## [0.63.1] - 2026-10-03

### Changed

- Band 1's point on the EQ curve is orange instead of red

### Fixed

- A band's point and a HPF or LPF square on the EQ curve turn red as well while they do nothing

## [0.63.0] - 2026-10-03

### Added

- The selected band's knobs turn red while the band is bypassed or a Peak or shelf sits at 0 dB, a HPF or LPF knob while its Bypass is on

## [0.62.0] - 2026-10-03

### Added

- With stacked link placement, pointing anywhere at a link group's block in the EQ overview picks out the group's curves

## [0.61.2] - 2026-10-03

### Fixed

- The grey single-filter curve stays after dragging a band point or a fader, until a click lands elsewhere

## [0.61.1] - 2026-10-03

### Fixed

- Clicking into a field of a PEQ band's row selects that band

## [0.61.0] - 2026-10-03

### Added

- Pointing at a PEQ band's point, row or faders, or at the HPF or LPF, shows that filter alone and greys out the other filters

## [0.60.0] - 2026-10-03

### Added

- While a PEQ band, the HPF or the LPF is being edited, the EQ curve shows that filter alone in grey with its area shaded

## [0.59.0] - 2026-10-03

### Added

- The EQ overview draws linked channels in one colour, the link group's while link colours show
- Pointing at a curve or a switch in the EQ overview greys out every other curve and switch outside its link group

## [0.58.0] - 2026-10-03

### Added

- Mag and Phase checkboxes on every EQ curve and on the EQ overview show the magnitude, the phase or both, kept per channel

### Fixed

- The phase axis is labelled in degrees from -180° to +180° instead of dB
- The phase includes the HPF and LPF, so a crossover turns it through the plot
- The phase wraps from -180° to +180° without a line across the plot

## [0.57.2] - 2026-10-03

### Added

- Screenshots of the vendor editor's fader ranges and defaults in `tools/shots/ranges/`

### Fixed

- The compressor release fader starts at 1 ms instead of 10 ms, as in the vendor editor
- The Aux compressor's attack fader ends at 200 ms and its release fader at 999 ms, as in the vendor editor's Aux dialog
- The AutoMix and Camera threshold faders start at -50 dB instead of -90 dB, as in the vendor editor
- The echo delay fader ends at 400 ms instead of 1000 ms, as in the vendor editor
- A delay shown in metres or feet uses 343.5 m/s instead of 343 m/s, as the vendor editor does

## [0.57.1] - 2026-10-02

### Fixed

- The gate threshold fader ends at +20 dB instead of 0 dB, as in the vendor editor

## [0.57.0] - 2026-10-02

### Added

- The knobs of a compressor's threshold, ratio, attack and release faders turn red while the compressor is off (ratio 1:1.0 or threshold +20 dB), with the reason in the tooltip

## [0.56.0] - 2026-10-02

### Changed

- A gain fader's knob also turns red while the gain sits at the bottom, and only the knob turns red, no longer the filled track
- Sliders draw their own track and knob, with the filled part following the value (`assets/lib/range.js`)

## [0.55.0] - 2026-10-02

### Removed

- Knee fader on the Comp page, the output tabs, the Aux page and in the Aux compressor dialog, with its tooltip and the `knee` value format
- `--knee` option of `trackdsp:compressor`, which now always sends back the knee byte the device holds

## [0.54.0] - 2026-10-02

### Added

- Orange Limit lamp above the red top of every output meter, lit while byte 51 of the meter reply has the output's bit set, with "Limiter working" in the tooltip
- `limiting` field in the bridge's meters event and `Matrix::parseLimiting()`
- Limiter column in `trackdsp:meters`
- The simulated device sets the limiting bit of an output whose compressor works and whose level reaches its threshold

## [0.53.0] - 2026-10-02

### Added

- Gate curve above the strips of the Gate page and compressor curve above the strips of the Comp page, input level against output level with the threshold as a red dot, as in the vendor editor. The plot follows the strip touched last and folds like the other panels
- A small curve beside the faders of the Gate group of every input tab and the Comp group of every output tab, in the Comp group of the Aux tab and beside the faders of the Aux compressor dialog
- `assets/lib/dynamics.js`, with tests, and tooltips and titles in English and German

## [0.52.0] - 2026-10-02

### Added

- The knob of a gain fader turns red while its channel is muted, on the Gain page, the channel and Aux tabs, in Sub Control and Group Control, with a tooltip line in English and German, the way a gain at its lowest step lights the Mute button

## [0.51.0] - 2026-10-02

### Added

- A dashed mark on every meter at the gate threshold of an input or the compressor threshold of an output and the Aux, with the threshold in the meter's tooltip
- Meters in the Channels menu, which shows or hides the mark and by default limits it to a gate above -90 dB and a compressor off 1:1.0 below +20 dB, kept in the browser, in English and German
- `assets/lib/meters.js` and `assets/lib/meterview.js`, with tests
- `TODO.md` item to check that every feature of the vendor editor exists in trackdsp

## [0.50.0] - 2026-10-02

### Added

- `TODO.md` item in Protocol, which checks on the device whether EQ bypass also takes out the HPF and LPF

## [0.49.0] - 2026-10-02

### Added

- Mag and Phase above the EQ overview on the Gain page, which switch every shown curve between magnitude and phase as on a channel tab. The overview stays a view, so it has no drag hint

## [0.48.0] - 2026-10-02

### Changed

- EQ Bypass on the channel and Aux tabs is red while it is on, like the Bypass of a band
- While EQ Bypass is on, the Bypass of every PEQ band shows red too, with a tooltip in English and German that EQ Bypass does it, as Mute does for a gain at its lowest step

### Added

- `bandBypassed()` in `assets/lib/eq.js`, with a test

## [0.47.3] - 2026-10-02

### Fixed

- The Bands panel of the input, output and Aux tabs has a border and a legend like the groups beside it, and its table still scrolls inside it on a narrow window

## [0.47.2] - 2026-10-02

### Fixed

- The EQ overview on the Gain page draws the Aux curve, dotted in a colour of its own, and has a switch for it after the outputs, in its legend and its tooltip in English and German

## [0.47.1] - 2026-10-02

### Fixed

- The fields of the Auto EQ Setup and Fit steps and of the gain rider dialog follow their labels instead of sitting at the far edge of their grid cell

## [0.47.0] - 2026-10-02

### Added

- Gate group on every input tab, with the threshold, attack and release of the Gate page
- Comp and Delay groups on every output tab, with the compressor and the 1:1.0 gain warning of the Comp page and the delay and its unit of the Delay page
- Aux compressor strip on the Comp page, after the outputs, with the Aux meter
- Aux strip in Sub Control, between the input and the output strip, on the Gain page and in the Sub Control dialog, with the Aux gain, meter, mute and the inputs that feed the Aux
- Tooltips for the new groups, in English and German, and the Sub Control tooltip names the Aux

### Changed

- The gate faders of an input sit in a template of their own, which the Gate page and the input tab share

## [0.46.1] - 2026-10-02

### Fixed

- A stacked link block on the Comp, Gate, Delay and Gain pages grows no wider than its strips can fill, so it no longer leaves an empty strip's width beside its members
- The buttons of the 1:1.0 gain warning on the Comp page show their whole text, and the second one goes to a line of its own when both do not fit

## [0.46.0] - 2026-10-01

### Added

- `TODO.md` section Editor, whose item links crossover points between outputs, the frequency and optionally the slope

## [0.45.0] - 2026-10-01

### Added

- Demo button in the connection dialog, which switches the bridge to the simulated device, with a tooltip in English and German

### Changed

- The target field of the connection dialog no longer names `demo` in its placeholder, and it stays empty while the bridge is on the simulated device
- The connection menu hint and the dialog tooltip name the demo

## [0.44.1] - 2026-10-01

### Fixed

- The footer shows tooltips on the preset number, the preset name and the Store and Recall buttons, in English and German
- The top bar shows tooltips on the device ID and the Online, Offline or Demo button

## [0.44.0] - 2026-10-01

### Added

- `TODO.md` section First, whose item checks the laptop clone for uncommitted Automation work before anything there continues

## [0.43.0] - 2026-10-01

### Fixed

- Save preset file stores the running gain riders in the JSON, and Open preset file brings them back. A file without riders, a vendor `.prs` file or one saved before this version, stops them. A rider with a bad setting refuses the file before anything is written

### Added

- Bridge op `riders`, which replaces every running rider with a given list

## [0.42.0] - 2026-10-01

### Changed

- Gain riders belong to a preset. Storing a user preset keeps the running riders for its slot, and recalling it brings back exactly those. A slot stored without riders or before this version recalls with none, like the factory default. The riders file holds `{"riders": [...], "presets": {...}}` and still reads the plain list of earlier versions
- The store and recall dialogs and their tooltips say that the gain riders go with the preset

## [0.41.0] - 2026-10-01

### Changed

- A gain rider is one for its link group: starting, changing or stopping it on any member does so for every member, which all show "Rider on" with the same settings. Inputs linked after their riders started take the settings of the rider on the lowest input. The dialog title names the group
- Recalling the factory default (F00) stops every gain rider. A user preset keeps them
- Linking keeps each channel's own name, and the confirmation says so

### Fixed

- A linked channel that moved to another link group kept its old settings, because its flags stayed 0 and no copy of the new master was sent

## [0.40.0] - 2026-10-01

### Added

- A gain rider on a linked input rides its whole link group as one: the power mean of the members' meters, the same gain written to every member, and a pause when somebody else changes any member's gain. A second rider in the same group stands by. The status line names the members, and every other member reads "Ridden with In3"
- A tooltip on every section and dialog title, for example EQ overview, Input, Output, EQ curve, Routing graph, the Auto EQ steps and every dialog of the menu

### Changed

- A setting changed on a linked channel goes to every channel of its group on every page, so linked channels follow in demo mode too. Besides mute, phase, mode and +48V, this now covers gain, gate, compressor, delay, FBQ, PEQ bands, high and low pass and EQ bypass. Only the name stays per channel
- The 1:1.0 fix buttons fix every output of a link group
- "Keep level" says how far it falls short where the level at 1:1.0 lies beyond +12 dB, for example "(+12.0dB, 9.3 dB short)" for an output at +12 dB

## [0.39.0] - 2026-10-01

### Added

- "Keep threshold" above the 1:1.0 fix buttons: ticked, both buttons set only 1:1.1 and leave the threshold as set, so levels above it get compressed slightly. Kept in the browser and shared by every output

### Removed

- The 1:1.0 warning on the output strips of the Gain page. It stays on the Comp page

## [0.38.0] - 2026-10-01

### Added

- Names for link groups, up to 12 characters, typed in the Link dialog. A name replaces the code (L1, L2 …) on the channel tabs, the group headers, the Link matrix, every channel label and the routing graph, and is cut short where a badge runs out of room. It is kept in the browser and keyed by the group's side and lowest channel (`linkName()` and `linkNameKey()` in `assets/lib/link.js`)

### Changed

- The menu option "Group code" is now "Group code or name", and the heading "Link display" no longer names the channel tabs and the Link dialog only
- The routing graph nodes are wider, so a group name fits next to the channel name

## [0.37.0] - 2026-10-01

### Added

- The link display (code, icon, colour) shows on every channel label inside the pages, as on the channel tabs. The Link dialog leaves it out, since it shows the groups being edited
- The link placement applies inside the pages: the strips of Gain, Gate, Comp and Delay, the EQ overview switches, the route buttons of Sub Control, Group Control and the Aux, the Auto EQ outputs, the matrix and the routing graph. Stacked gathers a group under one header, strips form a block that runs across, and the matrix leaves a gap around each group (`linkplace_controller.js`, `linkPlacement()` and `linkGaps()` in `assets/lib/link.js`)

### Changed

- The link display settings live in `assets/lib/linkview.js`, so every section follows a change at once

## [0.36.0] - 2026-10-01

### Added

- Routing graph next to the matrix: In1-In8 and Aux on the left, Out1-Out8 on the right, a line per connection coloured by its source. Hovering a channel brings out its lines. It folds like the other panels (`routing_controller.js`, `assets/lib/routing.js`)

## [0.35.0] - 2026-10-01

### Changed

- Page tabs show their changed-setting count as a badge next to the name instead of a corner triangle. Before, the count was only in the hover text

## [0.34.1] - 2026-10-01

### Fixed

- The two 1:1.0 fix buttons looked alike. Each now names the gain it sets, for example "Exact gain (-6.0dB)" and "Keep level (-8.0dB)"

## [0.34.0] - 2026-10-01

### Added

- Stacked channel tabs: each link group column gets a header with the group's code, icon and colour, and its hover text names the members

### Changed

- Stacked channel tabs: the members of a group share the column height equally, on a common row count for inputs and outputs (`lcm()` in `assets/lib/link.js`). A group member shows its link mark in the header instead of on its tab

## [0.33.1] - 2026-10-01

### Fixed

- Factory default (F00) in demo mode: the simulated device now restores `resources/factory_config.bin`. Before, it only changed the preset number and kept every setting

## [0.33.0] - 2026-10-01

### Added

- Tooltips on the controls of the browser editor in English and German, with what a control does and its unit. A widget finds its tooltip by its state path (`editor.tip.*`), so a fader shows the same one on every page and in every dialog
- Tooltips on the panels and dialogs whose names are abbreviations or vendor terms: FBQ, HPF, LPF, PEQ, Selected band, Sub Control, Group Control, AutoMix, Effect, Camera and the Aux compressor and source
- `EditorTooltipTest` checks that every tooltip text reaches a control in both languages

### Changed

- A switch keeps its tooltip when the device reports no value or the gain mutes the channel. The state message goes on a line above it

### Removed

- The TODO item for tooltips

## [0.32.0] - 2026-10-01

### Added

- Every function page tab shows a corner triangle and a changed-setting count when its controls differ from factory defaults.
- The Auto EQ tab tracks output PEQ settings and bypass state even without a measurement session.
- Page-mark tests cover each page, reset to defaults, unknown values and display preferences.

### Fixed

- Node test files are excluded from AssetMapper so test-only imports cannot break public asset serving.

## [0.31.0] - 2026-10-01

### Added

- The browser editor supports English and German through Symfony Translation catalogues.
- Help > Language offers English, Deutsch and Automatic with a persistent browser cookie.
- The editor chooses its language from the menu cookie, `TRACKDSP_LOCALE`, the browser header, then English.
- Locale precedence, catalogue parity, translated rendering and browser translation helpers have regression tests.
- Translation YAML checks run in CI. A GitLab test configuration is adapted from the sibling Symfony project.

### Changed

- Browser control labels, confirmations, connection messages and rider status use the selected language.
- The translated muted-gain display also parses back to mute.

## [0.30.1] - 2026-10-01

### Changed

- "Shape per group" in the link display settings is now "Icon per group"

## [0.30.0] - 2026-10-01

### Added

- "Shape per group" in the link display settings, combinable with the code, which then shows the code and the shape next to each other (`linkMark()` in `link.js`)
- The link display settings below the two Link matrices too, in step with the Channels menu (`_linkview.html.twig`)
- Stacked placement in the Link dialog: each link group is a block with one header naming its members, with a gap before each further group (`linkUnits()` in `link.js`)

### Changed

- The Link matrices follow the placement after every click and every settings change, not only when the dialog opens
- A colour per group without the code no longer brings a shape along. A browser that stored the code switched off gets the shape switched on

## [0.29.0] - 2026-10-01

### Added

- "Keep level" next to the 1:1.0 fix on the output strips: it moves the gain to the level the output had at 1:1.0 before it sets 1:1.1 from +20 dB (`heardGain()` in `scale.js`)

### Changed

- The 1:1.0 fix button is now "Exact gain", and the warning names the setting both buttons use

## [0.28.0] - 2026-10-01

### Added

- TODO item for change marks on every page tab

### Changed

- The Link dialog is a channel x channel matrix per side instead of a dropdown per channel. A cell links its row and column channel and merges their groups, a lit cell takes the column channel out of its group (`toggleLink()`, `normalizeGroups()` in `link.js`). A group lights as a block with its code, colour or shape, and with the placement side by side or stacked its members are moved next to each other when the dialog opens

### Removed

- `groupsFromPartners()` and `partnersFromGroups()`, which only the dropdowns used

## [0.27.0] - 2026-10-01

### Added

- "Open preset file" also reads a `.prs` file saved by the vendor editor (`Device::fromVendorPreset()`). `/api/upload` tells it from a trackdsp JSON file by its magic. The file carries no test tone, delay unit and EQ bypass, so the upload leaves them on the device as they are
- The vendor file layout in `docs/protocol.md`, and the sample file as `tests/fixtures/vendor/SdWa5_Default_Preset_3_DSPs.prs`
- TODO item for showing the routing as a graph next to the matrix

### Changed

- `Device::$delayUnit` may be null for unknown, and `allFrames()` then sends no delay unit
- The editor uploads the chosen file as it is instead of as text, so a binary file arrives intact

### Removed

- The TODO item for an offline mode: the editor starts in demo mode, and opening a preset file there sets it up without a device

## [0.26.0] - 2026-10-01

### Added

- Folds on the channel and Aux pages: the EQ curve, the band table, and each group beside it (Selected band, HighPass, LowPass, FBQ, Gain, Comp, AutoMix, Effect, Camera, Select). The channel pages share their fold keys, so they fold together

## [0.25.0] - 2026-10-01

### Added

- Placement of linked channels in the Channels menu: in channel order, side by side, or stacked per link group, where the members of a group share one column of the channel tabs (`linkLayout()` in `link.js`)
- The Link dialog shows the group code, colour and shape like the channel tabs and arranges its cells by the same placement

### Changed

- Link dialog: each dropdown lists the other seven channels of its side with their names, and links chain into groups (`groupsFromPartners()`, `partnersFromGroups()`). This replaces the group numbers of 0.24.1
- A stored "Linked channels side by side" becomes the placement "side by side" (`linkView()`)

## [0.24.2] - 2026-10-01

### Changed

- Matrix: the Out headers sit on the right (outputs as rows) or at the bottom (inputs as rows), so the In headers are always left or on top and the signal runs from In to Out. The corner button moves with them

## [0.24.1] - 2026-10-01

### Changed

- Link dialog: the dropdowns name the link groups L1-L4 like the channel tab badges, and the note says they pick a group, not a channel

## [0.24.0] - 2026-10-01

### Added

- Warning on the output strips of the Comp and Gain page while an output runs at compressor ratio 1:1.0 with a gain other than 0 dB, with a button that sets 1:1.1 from +20 dB (`gainwarning_controller.js`, `exaggeratesGain()` in `scale.js`)
- `warnings` in the answers of `/api/autoeq/start` and `/api/autoeq/record`, listed on the Auto EQ page, and the same warnings printed by `trackdsp:autoeq` before it measures (`AutoEq::warnings()`)

### Changed

- `Solo::exaggeratedGain()` holds the 1:1.0 check that `trackdsp:measure` already used, and `Solo::EXACT_RATIO`/`EXACT_THRESHOLD` are public

### Removed

- The TODO item for flagging an output at compressor ratio 1:1.0 with a gain

## [0.23.0] - 2026-10-01

### Added

- EQ overview at the top of the Gain page: the EQ curves of every input (solid) and output (dashed) in one plot, from `eqoverview_controller.js`. A switch per channel shows or hides its curve and only changes the plot, as in the vendor editor. The hidden curves are kept in the browser, and the panel folds

### Removed

- The TODO item for an EQ overview on the Gain page

## [0.22.0] - 2026-10-01

### Added

- Link display options in the Channels menu, each on its own and kept in the browser: the group code (L1, L2 …), a colour per group, and linked channels side by side on the channel tabs. A colour without the code comes with a shape per group
- `linkOrder()` in `assets/lib/link.js`, the tab order with linked channels next to each other

### Changed

- The gap between the input and the output tabs goes before the first output shown, rather than before the ninth tab

### Removed

- The TODO item for a configurable link display

## [0.21.2] - 2026-10-01

### Added

- A TODO item for tooltips on the controls of the browser editor, above all for abbreviations

### Fixed

- The rider dialog's highest gain follows "Allow a highest gain above 0 dB": up to +12 dB with it and 0 dB without, and a higher value drops to 0 dB when the box is cleared. Before, the form took a boost that the bridge then refused

## [0.21.1] - 2026-10-01

### Changed

- A Mute button lights up while its channel's gain is at raw 0, on the Gain page, the channel pages and the Aux page. Only the display changes, the mute flag is not written

## [0.21.0] - 2026-10-01

### Added

- Foldable panels: Input, Output, Sub Control and Group Control on the Gain page and the four steps of the Auto EQ page fold down to their title, from `collapse_controller.js`. Folded panels are kept in the browser
- `fold` macro in `templates/editor/_widgets.html.twig` for the title button of a foldable panel

### Removed

- The TODO item for collapsible sections

## [0.20.0] - 2026-10-01

### Added

- The corner cell of the matrix is a button that swaps rows and columns, with the inputs as rows. The choice is kept in the browser
- `assets/lib/prefs.js` for view preferences kept in the browser, with tests

### Changed

- The theme choice goes through `assets/lib/prefs.js`, under the same storage key as before

### Removed

- The TODO item for the matrix swap

## [0.19.0] - 2026-10-01

### Added

- Sub Control next to the channel strips and Group Control below them on the Gain page

### Changed

- The Sub and Group Control strips come from one partial, `templates/editor/_subgroup.html.twig`, for the dialogs and the Gain page

### Removed

- The TODO item for Sub and Group control on the Gain page

## [0.18.3] - 2026-10-01

### Added

- A TODO item for a button in the matrix corner that swaps rows and columns

### Fixed

- In the Link and Names dialogs a channel name now sits next to its control, and the gap of 24px falls between the cells

## [0.18.2] - 2026-10-01

### Added

- TODO items for a configurable link display, Sub and Group control on the Gain page, and collapsible sections

## [0.18.1] - 2026-10-01

### Added

- A TODO item for an EQ overview on the Gain page

## [0.18.0] - 2026-10-01

### Added

- `Scale::GAIN_MUTED` in PHP and `GAIN_MUTED` in `assets/lib/scale.js`, gain raw 0
- `RiderSettings::LOWEST_GAIN_DB`, -59.5 dB
- `trackdsp:gain` warns when the value is gain raw 0, which mutes the channel
- The browser editor accepts "mute" as a gain value
- A TODO item to check on the device that gain raw 0 mutes an output and the Aux

### Changed

- The browser editor shows a gain at raw 0 as "Muted" instead of -60.0 dB, and the channel tab of such a channel gets a hollow mute dot
- `trackdsp:status` reads the whole configuration and shows `MUTED(gain)` for a channel whose gain is at raw 0
- The gain rider's lowest gain is -59.5 dB. A rider with a lower `min` is refused, and a saved one is skipped with a warning when the bridge starts
- The rider dialog allows -59.5 dB as the lowest value for the lowest and highest gain
- The README names `TRACKDSP_TRUSTED_HOSTS` for a ddev with another `project_tld`

### Removed

- The TODO item for gain raw 0

## [0.17.0] - 2026-09-30

### Added

- `trackdsp:bridge --target-file`, by default the socket path with `.target.json`. Every `connect` and `disconnect` saves the target and whether the bridge should be connected there, and a start without `--target` takes both from it
- `demo` in the bridge status, true while the target is the simulated device
- The editor shows "Demo" instead of "Online" and a banner while the bridge is on the simulated device, from `assets/lib/connection.js`
- A TODO item to rename the repository and give it a description

### Changed

- The ddev daemon starts the bridge with `TRACKDSP_TARGET=demo` rather than `--target=demo`, so the saved target wins over it

### Fixed

- After a restart of the ddev web container the bridge came back on the simulated device instead of the device the editor had chosen, and writes went to the demo without a warning

### Removed

- The TODO item for the bridge coming back on the demo target

## [0.16.0] - 2026-09-29

### Added

- `trackdsp:measure --reference=<ch>` prints the transfer function of every other recorded channel against the reference loopback, with delay, polarity, coherent range and third-octave bands of magnitude, phase and coherence, on live recordings and on `--wav` files. The JSON file holds it per channel under `transfer`, and `reference_input` names the reference
- `App\Measurement\TransferFunction`, a dual-channel FFT in two passes with the sub-sample delay from the analytic impulse response
- `App\Measurement\Transfer` for the result and the `Polarity` enum
- `Fft::transform()` and `Fft::inverse()` for complex signals
- `FittedBand::phaseAt()` gives the phase of the RBJ peaking filter
- `Signals::delay()` delays a test signal by a fractional or negative number of samples
- `App\Measurement\Jit` switches PHP's JIT on for `trackdsp:measure` and `trackdsp:autoeq`, which makes their FFTs about twice as fast
- `.ddev/php/jit.ini` reserves the JIT buffer in ddev and leaves the JIT off for every other process
- `docs/automation.md` and `docs/measurements.md` describe the transfer function and its first results on the bench and speaker recordings
- TODO items for localization, the JIT on the VIM3 and the phase in the Auto EQ plot

### Changed

- `Fft::power()` is built on `transform()` and gives the same values

### Removed

- The TODO item to measure a transfer function

## [0.15.2] - 2026-09-29

### Added

- `docs/measurements.md` covers the auto EQ and the gain rider on the device

### Removed

- The TODO item to verify the gain riders and the auto EQ on the device, which both passed

## [0.15.1] - 2026-09-29

### Added

- `docs/measurements.md` covers the speaker and amplifier tests with one Tecnare, one Flexy, one SKRAM and one Achenbach 18, the noise floor and hum of the amplifiers, the mic matching and the SPL offset

## [0.15.0] - 2026-09-29

### Added

- `trackdsp:measure --restore` replays the restore file that a crashed measurement left behind
- `App\Measurement\Interrupt` and `Interrupted`. `trackdsp:measure` and `trackdsp:autoeq` set the flag on Ctrl+C and SIGTERM
- `Playback::restoreFile()` and `Playback::recover()`, and the constructor arguments `$reconnectTimeout` (30 s) and `$interrupt`
- The scripted test bridge can reject writes with a bridge error code

### Changed

- A measurement saves its restore frames and the settings of the paused gain riders to `<socket>.measure.restore.json` before the setup and deletes the file only after a clean restore. No measurement starts while the file is there
- The setup and the test tone go to the bridge in one write, and so do the tone and the restore
- When both the recording and the restore fail, the error names both and keeps the first one as previous

### Fixed

- A setup write that failed or timed out skipped the restore, although the bridge still applies a write whose client has gone
- A device that dropped off during a recording kept the test tone on and the other outputs muted. The restore now waits up to 30 s for the reconnect and sends the frames again
- Ctrl+C cut the settle short and the recording started anyway. It now skips the recording, drops one that ends after the signal and ends the remaining positions of the auto EQ
- A failing restore replaced the error of the recording, so the reason of the first failure was lost

## [0.14.0] - 2026-09-29

### Added

- `Scale::dbToMeter()` and the constants `Scale::METER_ZERO`, `METER_PER_DOUBLING` and `METER_FLOOR`
- `docs/measurements.md` covers the mirror image of a working compressor at every ratio, the meter calibration, the limiter onset and the frequency dependence of the threshold, and `docs/protocol.md` documents the meter scale

### Changed

- Meter levels are calibrated decibels on the scale of the compressor threshold, with 128 steps per doubling and 0 dB at 15906. A 1 kHz sine at unity gain reads -27.8 dB, the level at which a limiter holds it. `trackdsp:meters`, the browser meters, the bridge's meter events and the gain rider use the new scale, so a rider target or silence level saved in `<socket>.riders.json` before this version means something else now and needs to be set again
- The gain rider judges silence on the level before the gain, which is the meter level minus the gain, and counts a meter at its floor as silence at any gain
- The meters of the demo device move between -40 and -10 dB

### Fixed

- The meter levels came from a formula that did not fit the device. An idle input read -6.0 dB, the test tone -0.2 dB and a real step of 8.1 dB showed as 0.7 dB
- The gain rider stopped raising the gain after a loud source. A quiet source that followed read below the silence level at the gain the loud one left behind, so the rider took it for a pause and held the gain

## [0.13.1] - 2026-09-29

### Added

- `docs/measurements.md` with the setup, the commands and the results of the first loopback measurements of the UMC1820 and the 8x8
- `trackdsp:measure` warns about a played output at compressor ratio 1:1.0 whose gain is not 0 dB, and counts such a gain as not flat on the reference output

### Fixed

- A `--trim` of `trackdsp:measure` on an output at compressor ratio 1:1.0, the factory setting, came out stronger than asked (-20 dB as -23.4 dB, +6 dB as +9.7 dB) and added the spectrum mirrored at 24 kHz. Such an output now runs at 1:1.1 from +20 dB for the recording and gets its compressor back afterwards
- `trackdsp:measure` analysed the first milliseconds of a recording, where the UMC1820 sometimes starts with a click. A muted input read -75.5 instead of -108.7 dBFS, and the peak and the crest factor of quiet signals were wrong. The analysis now leaves out the first 100 ms
- The device model did not read the test tone frequency, which the 8x8 keeps at footer byte +50. The editor showed 1 kHz whatever the device held, and every restore of `trackdsp:measure` and the auto EQ wrote 1 kHz back

## [0.13.0] - 2026-09-29

### Added

- `trackdsp:measure` plays the device's test tone (pink or white noise, a sine or none) on some outputs alone or on every output as set, while a reference output keeps playing for the loopback. It records inputs of the interface and prints RMS, peak, crest factor, clipped samples and third-octave bands, absolute or relative to one input, with warnings about what can mislead a recording. The recording and a JSON file of the results stay in `var/measure/`, and `--wav` analyses an existing recording without the bridge
- `App\Measurement\Playback`, `Solo`, `TestTone` and `Levels`, `App\Model\Links`, and `Spectrum::bands()` for third-octave levels in dBFS
- `trackdsp.measure_dir` parameter

### Changed

- The auto EQ plays and restores through `Playback`, which `trackdsp:autoeq`, the Auto EQ page and `trackdsp:measure` share. The lock `<socket>.measure.lock` replaces the page's `record.lock` in the auto EQ directory

### Fixed

- The auto EQ started the settle and the recording before the device had taken the bypass and the test tone, since the bridge answers a write once it is queued. It now waits until the bridge has sent every write
- `trackdsp:autoeq` and the Auto EQ page could record at the same time, because only the page took a lock

## [0.12.0] - 2026-09-28

### Added

- ALSA in ddev: `.ddev/config.alsa.yaml` installs alsa-utils and `.ddev/docker-compose.alsa.yaml` binds the host's `/dev/snd` and allows the ALSA devices only, so the auto EQ records inside ddev, also after replugging the interface
- `TRACKDSP_CAPTURE_DEVICE`, the capture device of `trackdsp:autoeq`, the auto EQ API and the Auto EQ page when none is given. The page lists it also when `arecord` does not see it
- `RecorderDeviceTest` in the PHPUnit group `device`, a one-second recording from `TRACKDSP_TEST_CAPTURE`

### Fixed

- A one-channel recording through plughw averaged every input of a multichannel interface, so the auto EQ with the mic on input 1 of a UMC1820 recorded all ten inputs at -20 dB each. arecord now captures two channels at least

## [0.11.0] - 2026-09-28

### Added

- USB in ddev: `.ddev/docker-compose.hidraw.yaml` mounts the host's `/dev` at `/run/host-dev` and allows the hidraw devices, so the bridge and the command line in the web container reach an 8x8 on the laptop's USB, also after replugging
- `TRACKDSP_HIDRAW_SYSFS` and `TRACKDSP_HIDRAW_DEV` set where the USB search looks

### Changed

- The udev rule is now `udev/70-trackdsp.rules`. Remove an installed `99-trackdsp.rules`

### Fixed

- The udev rule gave the desktop user no access, because its number came after systemd's `73-seat-late.rules`
- The discover test found a real USB device on the machine running the tests

## [0.10.0] - 2026-09-28

### Added

- The browser editor marks every setting that differs from the factory default, with a bar on the control and a corner triangle on its channel tab or the Aux tab
- Preset occupancy: `Matrix::presetOccupancy()`, `preset_stored` in the device state, a "Stored" column in `trackdsp:presets`, and empty slots in the store and recall dialogs, which cannot be recalled
- `trackdsp.factory_config` parameter and `App\Model\FactoryDefaults`

### Changed

- The bridge re-read also reads the preset occupancy (50 steps)
- Storing a preset in the device state also makes it the active preset and puts its name into the header, like the device

### Fixed

- A preset recall or store waits up to 3 s for the ACK instead of 0.15 s per try over USB, since the device answers only after its flash work
- The store and recall dialogs showed the name of the neighbouring slot

## [0.9.0] - 2026-09-28

### Added

- `resources/factory_config.bin`, the configuration read after a factory reset

### Changed

- `trackdsp:compressor` takes every value not given from the compressor on the device and needs at least one value

### Fixed

- A preset file upload also writes the EQ bypass of the inputs, outputs and Aux, the Aux name, Sub control, Group control, the delay unit and +48V when the file knows it, and ends with an activate. A file without groups leaves the groups of the device alone
- An uploaded preset file with a value outside the device's range gets a 400 instead of a 500
- Save preset file in the browser editor shows an error in the error line instead of opening a JSON page when no device is connected

### Removed

- The DJ and 1:1 buttons on the output and Aux compressors
- `--dj` of `trackdsp:compressor`

## [0.8.0] - 2026-09-28

### Added

- `resync` in the bridge status, the progress of a running re-read

### Changed

- The bridge re-reads the device one request per loop pass and answers clients in between, instead of blocking every request for about 3.3 s over USB. Writes during the re-read are applied again to its result
- Over USB a request waits 0.15 s per attempt and is tried up to 5 times instead of 1 s and 3 times, since the 8x8 loses about one request per 0.2 to 0.3 s of continuous traffic
- The VIM3 bridge unit re-reads every 60 s again instead of every 600 s

## [0.7.0] - 2026-09-27

### Added

- Khadas VIM3 appliance setup in `deploy/vim3/` (bridge unit, PHP-FPM pool, Caddyfile, login banner, env template), the Ethernet role switch `sdwa5-wan-dhcp` for the Deco WAN port, and `docs/vim3.md` with the off-grid network and power cut measurements

## [0.6.0] - 2026-09-27

### Added

- Auto EQ page in the browser editor: setup, recordings per position or WAV upload, mic calibration, fit with a plot of the measurement, target, EQ and prediction, apply, verify positions with the bands on, revert and a list of backups
- `/api/autoeq` endpoints for the auto EQ steps, with the session kept in `var/autoeq/session.json`
- Gain riders run inside the bridge: socket ops `ride` and `unride`, `POST /api/ride` and `/api/unride`, the settings in the bridge status and the live values in the `meters` event
- The bridge keeps the gain riders in `<socket>.riders.json` (`--riders-file`) and starts them again after a restart
- Rider button, status line and settings dialog per input on the Gain page
- DJ and 1:1 buttons on every output compressor and the Aux compressor
- Channel names next to the channel ID wherever the ID appears in the browser editor
- Channel tabs with the mute state and the link group, a Tools menu and a theme switch for auto, light and dark
- Typed fader values with units, a double click resets gain and delay faders
- Peak hold and a clip mark on the meters
- `trackdsp:ride --list` and `--stop`

### Changed

- `trackdsp:ride` starts or changes a rider in the bridge and returns, instead of riding while it runs
- The auto EQ pauses the gain riders while it records, and waits the settle time before every recording instead of only the first one
- `trackdsp:autoeq` and the browser session share the measure, backup, apply and revert steps in `App\AutoEq\AutoEq`
- The Matrix page is a grid table with the channel names as headers
- Strips fill the page width and wrap on narrow screens, and the PEQ panel keeps the gain beside the filters

### Removed

- `trackdsp:ride --dry-run` and `--duration`

## [0.5.0] - 2026-09-27

### Added

- Browser editor at `/` with the Gain, Gate, Comp, Delay, Matrix and Aux pages, a page per input and output with the EQ curve, live meters and dialogs, built on AssetMapper and Stimulus without a build step
- Browser code tests with `node --test`, run by `composer ci` and a GitHub Actions job
- `trackdsp:discover` and `POST /api/discover`: device search on USB and on TCP 9761 of the local networks or `TRACKDSP_DISCOVERY_NETWORKS`, with devices whose connections are all in use listed as busy by their MAC prefix
- Target `auto`: the first device found, USB before the network. It is the new default of `TRACKDSP_TARGET`
- API writes need the header `X-Trackdsp`, and `TRACKDSP_TRUSTED_HOSTS` limits the host names the app answers to
- `trackdsp:ride`: gain rider that keeps an input at a target level through the bridge, with slew limit, deadband, gain window, silence freeze and a pause after manual changes
- `trackdsp:compressor`: sets the output or Aux compressor, `--dj` for a DJ default of 1:4 from -6 dB, 10 ms attack, 200 ms release
- `trackdsp:autoeq`: supervised auto EQ with pink noise, `arecord` measurement at several mic positions, mic calibration, target curves, a PEQ fit in raw device steps, backup, confirmation before writing and `--revert`
- FFT, WAV reader, Welch spectrum in fractional-octave bands and calibration file reader in `App\Measurement`
- `docs/automation.md`

### Changed

- A TCP connect timeout explains that the device accepts no new connection while all of them are in use
- `trackdsp:info` and the bridge report the address actually connected to, also for the target `auto`

## [0.4.0] - 2026-09-25

### Added

- `trackdsp:bridge`: owns the device connection and serves it over a unix socket with JSON lines, merges repeated writes to the same target, re-reads the device after preset changes and periodically, polls meters for subscribers and reconnects after errors
- JSON API `/api/status`, `state`, `write`, `connect`, `disconnect`, `refresh`, `upload`, `download` with an allow-list of write commands and range checks
- Server-sent events `/api/events` with `status`, `state` and `meters`, relayed from the bridge
- `DeviceState`: applies write commands to a cached configuration and keeps +48V, working status, test tone frequency and device ID
- The simulated device stores presets and restores them on recall, and answers the preset occupancy
- ddev runs the bridge as a daemon of the web container
- `docs/bridge.md`

### Changed

- The simulated device applies writes through `DeviceState`. A channel copy now also copies the mute state

## [0.3.0] - 2026-09-25

### Added

- Symfony 7.4 project on PHP 8.4 with ddev, php-cs-fixer (Symfony standard), PHPStan level 8, PHPUnit and a GitHub Actions workflow
- Protocol library: frame codec, stream reassembly, opcodes, channel indices, scales, value labels and builders for every editor command
- Transports for TCP 9761, Linux hidraw and a simulated device, hidraw discovery by USB VID/PID and a target factory
- `Matrix` client with reply matching, retries with session re-init and reads of info, IP, presets, configuration, groups and meters
- Device model of the 1277-byte configuration with JSON preset files compatible with the Python editor
- Console commands `trackdsp:info`, `presets`, `status`, `gain`, `mute`, `unmute`, `phase`, `route`, `dump`, `meters` and the `bin/trackdsp` short form
- Replay test that rebuilds all 4830 editor frames of the USB captures byte for byte
- `docs/protocol.md` as the English protocol reference, including the connect replies of `22` and `2C`

### Changed

- The Python implementation moved to `legacy/python/`
- `captures/README.md` and the udev rule comments are in English

## [0.2.0] - 2026-09-24

### Added

- Python library `trackdsp`: frame codec, USB (hidraw) and TCP transports, client, command builders and configuration model
- PySide6 editor with the Gain, Gate, Comp, Delay, Matrix, Aux and per-channel pages, menus, presets and live meters
- Command line `trackdsp`, Linux installer, udev rule and desktop entry
- `PROTOCOL.md` with the commands and the configuration layout
- Windows tools used for the protocol discovery (GUI automation, USBPcap captures)
- USB captures, test logs and screenshots of the vendor editor

[0.16.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/49d84ea...main
[0.15.2]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/613f252...49d84ea
[0.15.1]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/840ca13...613f252
[0.15.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/011da6f...840ca13
[0.14.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/66d6b82...011da6f
[0.13.1]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/d45de7f...66d6b82
[0.13.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/9494c73...d45de7f
[0.12.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/1bb70a3...9494c73
[0.11.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/c378966...1bb70a3
[0.10.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/c432438...c378966
[0.9.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/de8a921...c432438
[0.8.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/d94e3e1...de8a921
[0.7.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/60ef138...d94e3e1
[0.6.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/eac219d...60ef138
[0.5.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/287c410...eac219d
[0.4.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/36cccde...287c410
[0.3.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/10b4636...36cccde
[0.2.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/commit/10b4636
