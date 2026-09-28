# TODO

## Rewrite

- Browser editor: check every page and dialog against the device, with a harmless round trip per control
- PHPUnit group `device` with read-only checks and reversible writes over LAN and USB
- Packaging: `trackdsp:serve`, FrankenPHP static binary, installer, desktop entry
- Offline mode like the vendor editor: check whether a preset can be set up in the browser editor without a
  device and handed to the real device later. The bridge target `demo` simulates a device, but the editor
  offers no obvious way to start from it
- Vendor preset files: the file format of the vendor editor is unknown. Capture a file saved by the vendor
  editor in the win11 VM, then decide whether trackdsp reads and writes it
- Remove `legacy/python/` once the rewrite has reached parity

## Several devices

- Control at least two 8x8 at once. With two connected, the editor shows both control sets side by side, so
  both are controlled at the same time without switching between them. Needs a multi-device bridge (or one
  bridge per device with its own socket) and a device key in the API and the event stream
- Stable USB names per physical port through a udev symlink on `ENV{ID_PATH}` (e.g. `/dev/trackdsp-rack1`),
  since the units share VID/PID and `hidrawN` follows the plug order

## Automation

- Verify the gain riders and the auto EQ on the device with the UMC1820 and the
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

- Locate +48V (probably input byte 8) and the working status in the configuration
- Presets: recall F00 (`20 00`) on the device, and find a command that deletes a stored slot. Store, recall and occupancy are confirmed on U20
- PIN: set (`2F`), unlock (`2D`), change and unset, with the lock state from `2C` after every step. Find the recovery path first (manual chapter 6, the 4x4 projects)
- Command for changing the IP address
- Meter dB scale calibration
