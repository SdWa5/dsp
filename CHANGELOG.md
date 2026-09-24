# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.3.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/compare/10b4636...feature/symfony-rewrite
[0.2.0]: https://github.com/GitiGlitzer/dsp_linux_8x8/commit/10b4636
