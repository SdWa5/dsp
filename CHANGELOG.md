# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.13.1]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/d45de7f...main
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
