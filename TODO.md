# TODO

## Rewrite

- Browser editor: check every page and dialog against the device, with a harmless round trip per control
- DJ and 1:1 compressor buttons: write to the linked outputs too, like the gain and mute controls
- Browser editor: mark every setting that differs from the factory default visibly, in a different colour and
  with a mark that works without colour, on the control and on its channel tab, so a changed preset is readable
  at a glance. The defaults come from a factory reset read-out (the device was reset on 2026-09-25)
- PHPUnit group `device` with read-only checks and reversible writes over LAN and USB
- Packaging: `trackdsp:serve`, FrankenPHP static binary, installer, desktop entry
- Preset upload (`Device::allFrames()`) leaves out Sub control, Group control, the delay unit and the Aux name, like the Python editor
- Offline mode like the vendor editor: check whether a preset can be set up in the browser editor without a
  device and handed to the real device later. The bridge target `demo` simulates a device, but the editor
  offers no obvious way to start from it
- Preset files like the vendor editor: check that saving the settings as a file (File menu, `/api/download`)
  and opening one (`/api/upload`) work in the browser editor, also offline, and that the files open in the
  vendor editor or the Python editor
- Remove `legacy/python/` once the rewrite has reached parity

## Several devices

- Control at least two 8x8 at once. With two connected, the editor shows both control sets side by side, so
  both are controlled at the same time without switching between them. Needs a multi-device bridge (or one
  bridge per device with its own socket) and a device key in the API and the event stream
- Stable USB names per physical port through a udev symlink on `ENV{ID_PATH}` (e.g. `/dev/trackdsp-rack1`),
  since the units share VID/PID and `hidrawN` follows the plug order

## Automation

- Verify the gain riders, the DJ compressor and the auto EQ on the device with the UMC1820 and the
  measurement mic, on the command line and in the browser editor
- Measure the 8x8's PEQ shape against the RBJ peaking filter the fitter assumes
- `arecord` in the ddev web container (alsa-utils and the sound device), so a measurement runs inside ddev and
  the Auto EQ page can record there
- Auto EQ with phase and delay: measure a transfer function instead of the plain pink noise spectrum, either
  with a log sweep from the interface and deconvolution, or with a dual-channel FFT against the device's pink
  noise looped back from a spare output (OUT8) into a second interface input. Decided: the loopback, with OUT8
  on a second input of the UMC1820. The impulse response gives the
  arrival time per output, so the output delays can be aligned (subs against tops), and the fit plot can show
  the phase
- Auto EQ with two mics: record both at once (`arecord -c 2`, one channel per mic) and power-average them, or
  give each mic its own outputs (left and right side). Today every recording reads one mic channel, and
  several positions are averaged one after another

## Protocol

- Confirm the hidraw report framing (report number on write and read) on the device
- Locate +48V (probably input byte 8) and the working status in the configuration
- Presets: store (`26` name + `21`), recall (`20`) and occupancy (`22`). Back up the live config first and store only into a slot the user names. The device was factory reset on 2026-09-25, so the occupancy reply should now differ from 20 × `FF`
- PIN: set (`2F`), unlock (`2D`), change and unset, with the lock state from `2C` after every step. Find the recovery path first (manual chapter 6, the 4x4 projects)
- Command for changing the IP address
- Meter dB scale calibration
