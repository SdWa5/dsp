# trackdsp

Editor, command line and PHP library for the Thomann **t.racks 8x8 Matrix** DSP on Linux.
It talks to the device over **USB** (hidraw) or **LAN** (TCP 9761) with the protocol reverse-engineered from the
Windows vendor software "the t.racks 8x8 Matrix Digital Processor Editor". The protocol is described in
[docs/protocol.md](docs/protocol.md).

## Status

This branch rewrites the first implementation (Python and PySide6, kept in [legacy/python/](legacy/python/)) on
Symfony. The protocol library, the device model and the command line are done and tested against the vendor editor
captures and the live device over LAN. The browser editor, the device bridge and the packaging follow, see
[TODO.md](TODO.md).

## Requirements

- Development: [ddev](https://ddev.com/) (PHP 8.4 in a container, no PHP on the host needed)
- Runtime (planned): the FrankenPHP static binary, no system PHP
- USB access without root: the udev rule in [udev/99-trackdsp.rules](udev/99-trackdsp.rules)

## Installation

```bash
git clone git@github.com:GitiGlitzer/dsp_linux_8x8.git
cd dsp_linux_8x8
ddev start
ddev composer install
```

For USB, install the udev rule once:

```bash
sudo cp udev/99-trackdsp.rules /etc/udev/rules.d/
sudo udevadm control --reload-rules && sudo udevadm trigger
```

## Usage

### Command line

`bin/trackdsp` is the short form of `bin/console trackdsp:<command>`.

```bash
bin/trackdsp info -t 192.168.68.10     # device, IP, active preset
bin/trackdsp presets                   # preset names U01..U20, F00
bin/trackdsp status                    # mute state of all channels
bin/trackdsp gain in1 -6               # boosts above 0 dB only with --allow-boost
bin/trackdsp mute out1                 # and unmute out1
bin/trackdsp phase out3 invert         # or normal
bin/trackdsp route out3 1,2            # Out3 gets In1 + In2, "none" for no input
bin/trackdsp dump config.bin           # raw configuration, hex without a file
bin/trackdsp meters                    # levels in approximated dB, --raw for the bytes
```

The target is `usb` (first device found), `usb:/dev/hidrawN`, `demo` (simulated device) or `host[:port]`.
Pass it with `-t` or set `TRACKDSP_TARGET` in `.env.local`. Inside ddev, run the commands with `ddev exec`.

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
| `App\Model` | the configuration as objects, JSON preset files, upload frames |
| `App\Command` | the `trackdsp:*` console commands |

## Development

```bash
ddev exec composer ci          # coding standard, PHPStan level 8, PHPUnit
ddev exec composer cs-fix      # apply the Symfony coding standard
```

The tests need no device. They replay all 143 USB captures in [captures/](captures/) against the command builders,
check the model against the editor display and run the command line against the simulated device.
Tests in the PHPUnit group `device` talk to real hardware and are excluded by default.

## Authors

- GitiGlitzer: protocol analysis, first implementation
- Stefan Ripper: Symfony rewrite

## License

No license has been chosen yet.
