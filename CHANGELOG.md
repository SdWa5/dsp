# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.5.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/287c410...main
[0.4.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/36cccde...287c410
[0.3.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/10b4636...36cccde
[0.2.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/commit/10b4636
