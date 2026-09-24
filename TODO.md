# TODO

## Rewrite

- Browser editor: Gain, Gate, Comp, Delay, Matrix, Aux and In1–8 / Out1–8 pages, menus and dialogs, EQ curve with draggable points, live meters
- PHPUnit group `device` with read-only checks and reversible writes over LAN and USB
- Packaging: `trackdsp:serve`, FrankenPHP static binary, installer, desktop entry
- Preset upload (`Device::allFrames()`) leaves out Sub control, Group control, the delay unit and the Aux name, like the Python editor
- Remove `legacy/python/` once the rewrite has reached parity

## Protocol

- Confirm the hidraw report framing (report number on write and read) on the device
- Locate +48V (probably input byte 8) and the working status in the configuration
- Presets: store (`26` name + `21`), recall (`20`) and occupancy (`22`). Back up the live config first and store only into a slot the user names. The device was factory reset on 2026-09-25, so the occupancy reply should now differ from 20 × `FF`
- PIN: set (`2F`), unlock (`2D`), change and unset, with the lock state from `2C` after every step. Find the recovery path first (manual chapter 6, the 4x4 projects)
- Command for changing the IP address
- Meter dB scale calibration
