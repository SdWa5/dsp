# Device bridge and JSON API

The device accepts one controller at a time. It needs a session start (`10`), meters that are polled, and
writes that arrive no faster than it acknowledges them. A hidraw handle cannot be shared either.
`trackdsp:bridge` therefore owns the one connection and serves it to the web editor, which reaches it
through the JSON API.

```
browser ──HTTP JSON──▶ ApiController ──┐
browser ◀──SSE──────── EventsController ┤ unix socket, JSON lines
                                        ▼
                               trackdsp:bridge ──▶ TCP 9761 | /dev/hidrawN | demo
```

## Running it

```bash
bin/console trackdsp:bridge -t 192.168.68.10     # or usb, usb:/dev/hidrawN, demo
```

| Option | Default | Meaning |
|---|---|---|
| `-t`, `--target` | `$TRACKDSP_TARGET` | device to connect to |
| `-s`, `--socket` | `$TRACKDSP_BRIDGE_SOCKET`, else `var/bridge.sock` | unix socket |
| `--meter-interval` | `0.3` | seconds between meter polls while a client listens |
| `--resync-interval` | `60` | seconds between full re-reads of the device |
| `--riders-file` | the socket path with `.riders.json` | where the gain riders are kept across restarts |

In ddev the bridge runs as a daemon of the web container (`.ddev/config.bridge.yaml`) on the simulated
device. `POST /api/connect` switches it to the real one. Its log is in `ddev logs -s web`, and `-v` shows
the info messages.

The bridge stops on SIGINT and SIGTERM and removes its socket. It refuses to start while another bridge
answers on the same socket. A socket file without a bridge behind it is removed.

## Behaviour

- **Connect:** init, device string, the 26 configuration pages, group control, the 21 preset names and the
  active preset. The IP comes from `B0` over USB. Over TCP it is the connected address.
- **Writes** change the cached state at once and wait in a queue. A write replaces a queued write to the
  same target (command plus channel, plus band for PEQ, and command alone for device-wide settings).
  Copy, link, preset and PIN commands are barriers: they are never merged, and no later write merges
  past them. The queue is flushed for at most 50 ms per loop pass, so the socket stays responsive.
- **Re-read:** after a preset recall or store, on `refresh` and every resync interval, once the queue
  is empty. The re-read keeps the values the configuration does not carry (+48V, working status, test
  tone frequency, device ID). They are taken from the writes the bridge has seen.
- **Meters** are polled only while at least one client is subscribed or a gain rider runs.
- **Gain riders** run in the bridge loop. Each meter reading goes to every rider, and the gains they change
  are queued like any other write. Their settings are saved on every change and loaded at start, so the
  riders survive a restart. See [automation.md](automation.md) for what a rider does.
- **Errors:** a device error drops the connection and clears the queue. The bridge reconnects after
  1, 2, 4, 8 and then every 15 seconds. `disconnect` stops that until the next `connect`.

## Socket protocol

One JSON object per line in each direction.

```
→ {"id": 1, "op": "write", "frames": ["3508 01"]}
← {"id": 1, "ok": true, "result": {"queued": 1}}
← {"id": 2, "ok": false, "error": "Device not connected.", "code": "offline"}
```

| op | Parameters | Result |
|---|---|---|
| `ping` | – | `"pong"` |
| `status` | – | `connected`, `target`, `transport`, `info`, `error`, `queued`, `clients`, `riders` (list of rider settings) |
| `state` | – | the device as `Device::toArray()`, the `device` object of a preset file |
| `write` | `frames`: list of hex strings, command byte followed by the data | `{"queued": n}` |
| `connect` | `target` (optional, the current one otherwise) | status |
| `disconnect` | – | status |
| `refresh` | – | status |
| `subscribe` | – | `true`, then events |
| `ride` | `channel` (input 0..7), `settings` (`target`, `min`, `max`, `slew`, `average`, `silence`, `deadband`, `hold`, `boost`, missing keys take the defaults) | status. Starts the rider, or replaces the settings of a running one |
| `unride` | `channel` | status. The gain stays where it is |

Error codes are `invalid` (bad request) and `offline` (no device connection).
A subscribed client receives `{"event": "status"|"state"|"meters", "data": ...}`. Right after `subscribe`
it gets the current status and state. `meters` data is `{"levels": [17 × dB], "raw": "hex", "riders": [...]}`,
where every rider reports `{"channel", "gain", "average", "status"}`. The status is `waiting`, `silent`,
`steady`, `riding`, `limit` or `paused`.

## HTTP API

| Method | Path | Body | Answer |
|---|---|---|---|
| GET | `/api/status` | – | bridge status |
| GET | `/api/state` | – | device state |
| POST | `/api/write` | `{"command": "mute", "args": {"channel": 8, "on": true}}` or `{"commands": [...]}` | `{"queued": n}` |
| POST | `/api/connect` | `{"target": "192.168.68.10"}` | status |
| POST | `/api/disconnect` | – | status |
| POST | `/api/refresh` | – | status |
| POST | `/api/upload` | a preset file | `{"queued": n}` |
| GET | `/api/download` | – | the live state as a preset file |
| GET | `/api/events` | – | server-sent events `status`, `state`, `meters` |
| POST | `/api/discover` | – | `{"networks": [...], "devices": [{"target", "kind", "info", "busy", "connected"}]}` |
| POST | `/api/ride` | `{"channel": 0, "settings": {"target": -18, "min": -20, "max": 0}}` | status |
| POST | `/api/unride` | `{"channel": 0}` | status |
| | `/api/autoeq/*` | | the auto EQ steps, listed in [automation.md](automation.md#in-the-browser) |

`command` is the name of a builder in `App\Protocol\Commands` and `args` are its parameters by name, for
example `gainDb {channel, db}` or `peq {channel, band, gainRaw, freqIndex, qIndex, type, bypass}`.
The allowed builders are listed in `App\Bridge\CommandMap::COMMANDS`. Reads, the session commands and the
PIN commands (`unlock`, `setPin`) are not among them. Integers must fit their field. A value that the
builder would cut to one byte, or anything outside 0..65535, is rejected. Channel arguments must be a
channel index (`00`–`0F`, `12`, `13`).

Every write (any method except GET, HEAD and OPTIONS) needs the header `X-Trackdsp` with any value, or it
is refused with 403. A custom header forces a CORS preflight, which the app never allows, so another website
open in the same browser cannot write to the device. `TRACKDSP_TRUSTED_HOSTS` limits the host names the app
answers to, which guards against DNS rebinding.

`/api/discover` searches USB and the networks in `TRACKDSP_DISCOVERY_NETWORKS` (empty: the networks of the
local interfaces) for devices on TCP 9761. The device the bridge is connected to is listed without being
contacted again. A device whose connections are all in use, for example by the vendor editor, answers ping
but no TCP connect. It is listed as `busy` when the ARP table shows a host with its MAC prefix `00:04:a3`.

Errors are `{"error": "..."}` with status 400 (bad request), 409 (device not connected), 503 (bridge not
running) or 504 (no answer from the bridge in time).

`/api/events` starts with `retry: 1000` and sends a `: ping` comment when nothing happened for 15 seconds.
A stream ends after 300 seconds (`trackdsp.events_lifetime`), and `EventSource` reconnects by itself.
Without a bridge it sends one `status` event with `"bridge": false` and ends. The events are relayed from
the bridge socket rather than through a Mercure hub, so the same setup works in ddev and in the
FrankenPHP binary.
