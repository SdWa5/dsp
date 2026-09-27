# trackdsp

Editor, command line and PHP library for the Thomann **t.racks 8x8 Matrix** DSP on Linux.
It talks to the device over **USB** (hidraw) or **LAN** (TCP 9761) with the protocol reverse-engineered from the
Windows vendor software "the t.racks 8x8 Matrix Digital Processor Editor". The protocol is described in
[docs/protocol.md](docs/protocol.md).

## Status

This branch rewrites the first implementation (Python and PySide6, kept in [legacy/python/](legacy/python/)) on
Symfony. The protocol library, the device model, the command line, the device bridge and the JSON API are done and
tested against the vendor editor captures and the live device over LAN. The browser editor has every page and
dialog, and the gain rider, the DJ compressor and the auto EQ in it. It is tested against the simulated device,
synthetic recordings and a mocked event stream, not yet page by page on the device. Packaging follows, see
[TODO.md](TODO.md).

## Requirements

- Development: [ddev](https://ddev.com/) (PHP 8.4 in a container, no PHP on the host needed)
- Runtime (planned): the FrankenPHP static binary, no system PHP
- USB access without root: the udev rule in [udev/99-trackdsp.rules](udev/99-trackdsp.rules)
- Auto EQ: `arecord` (alsa-utils), a measurement mic and an audio interface

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
bin/trackdsp discover                  # devices on USB and the local networks
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

The target is `auto` (the first device that discovery finds, USB before the network), `usb` (first USB device),
`usb:/dev/hidrawN`, `demo` (simulated device) or `host[:port]`. Pass it with `-t` or set `TRACKDSP_TARGET` in
`.env.local`. Discovery searches `TRACKDSP_DISCOVERY_NETWORKS` (comma-separated CIDRs such as
`192.168.68.0/22`), by default the networks of the local interfaces. Inside ddev, run the commands with
`ddev exec` and set the LAN in `.env.local`, since the container only sees its own network.

The device accepts only a few TCP connections. While the vendor editor is connected, new connections time out
although the device answers ping. Close the vendor editor first.

### Browser editor

`https://trackdsp.ddev.site` in ddev. It has the Gain, Gate, Comp, Delay, Matrix, Aux and Auto EQ pages, a tab
per input and output with the EQ curve, live meters and the File, Device, Channels, Tools and Help menus. The
editor talks to the bridge through the JSON API below.

- A named channel shows its name next to its ID wherever the ID appears, for example "Out1 Main L" or
  "Out1 (Main L)" in lists. A name that only repeats the ID is left out.
- The channel tabs show a dot for a muted channel and the link group (L1, L2, ...).
- A fader value can be typed, with or without its unit ("-6", "2k", "200ms", "1:4"). A double click on a gain
  or delay fader sets it back to 0 dB or no delay.
- The meters hold their peak for 1.5 s and light a red top for 2 s after reaching 0 dB.
- The Gain page starts and shows the gain riders, the Comp page has the DJ and 1:1 buttons, and the Auto EQ
  page leads through measure, fit, apply and verify. See [docs/automation.md](docs/automation.md).
- The theme button switches between auto, light and dark. The choice is kept in the browser.

### Bridge and JSON API

The web editor reaches the device through `trackdsp:bridge`, which owns the connection, merges fast writes and
publishes state and meters. In ddev it runs by itself on the simulated device.

```bash
bin/console trackdsp:bridge -t 192.168.68.10                  # outside ddev
curl -s localhost/api/state                                   # inside ddev: ddev exec curl ...
curl -s -XPOST localhost/api/write -d '{"command":"mute","args":{"channel":8,"on":true}}'
curl -s -XPOST localhost/api/connect -d '{"target":"192.168.68.10"}'
curl -sN localhost/api/events                                 # server-sent events: status, state, meters
```

Writes need the header `X-Trackdsp`, and `TRACKDSP_TRUSTED_HOSTS` lists the host names the app answers to.
The socket protocol and every endpoint are described in [docs/bridge.md](docs/bridge.md).

### Gain rider, DJ compressor and auto EQ

```bash
bin/console trackdsp:ride in1 --level=-18                          # the bridge keeps the DJ input at -18 dB
bin/console trackdsp:ride --list                                   # and in1 --stop ends it
bin/console trackdsp:compressor out1,out2 --dj                     # 1:4 from -6 dB, 10 ms attack, 200 ms release
bin/console trackdsp:autoeq out1,out2 --device=plughw:CARD=UMC1820 --positions=3   # needs the bridge
bin/console trackdsp:autoeq out1,out2 --revert=var/autoeq/backup-<time>.json
```

The gain riders run inside the bridge and come back after a restart. The auto EQ plays the device's pink noise,
records the measurement mic, fits up to 7 PEQ bands to a target and writes them only after confirmation. All three
are also in the browser editor. Every option and the fitting rules are in
[docs/automation.md](docs/automation.md).

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
| `App\Model` | the configuration as objects, JSON preset files, upload frames, `DeviceState` for applying writes |
| `App\Bridge` | the bridge loop, write queue, command allow-list and the socket client |
| `App\Discovery` | device search on USB and on the local networks |
| `App\Automation` | the gain rider |
| `App\Measurement` | FFT, WAV reader, spectrum analysis, responses, calibration files, `arecord` recorder |
| `App\AutoEq` | target curves, the PEQ fitter, and the measure, backup, apply and revert steps shared by the command and the browser session |
| `App\Command` | the `trackdsp:*` console commands |
| `App\Controller` | the browser editor, the JSON API, the auto EQ API and the event stream |
| `App\EventListener` | the header check for API writes |

## Development

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

## Authors

- GitiGlitzer: protocol analysis, first implementation
- Stefan Ripper: Symfony rewrite

## License

No license has been chosen yet.
