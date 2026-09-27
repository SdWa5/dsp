# Khadas VIM3 appliance

The VIM3 runs the bridge and the browser editor next to the rack. The 8x8 hangs on its USB port, and every
laptop or phone on the crew network opens the editor in a browser. The files in
[deploy/vim3/](../deploy/vim3/) are the configuration the board runs.

| | |
|---|---|
| Board | Khadas VIM3, 2 GB RAM, 16 GB eMMC |
| System | Armbian 26.8 trixie (Debian 13), aarch64, systemd-networkd through netplan |
| Hostname | `sdwa5-dsp`, also `sdwa5-dsp.local` through Avahi |
| Editor | `http://10.42.0.1/` and `http://192.168.68.11/` on the crew wifi `SdWa5`, `http://sdwa5-dsp.local/` on a normal LAN |
| SSH | user `khadas`, key only, sudo with password |
| App | `/srv/trackdsp`, owned by the system user `trackdsp` (groups `plugdev`, `audio`) |

## Services

| Unit | Job |
|---|---|
| `trackdsp-bridge.service` | `bin/console trackdsp:bridge --target=usb --resync-interval=60` as `trackdsp`, socket `var/bridge.sock`, restarts 3 s after every exit, also while the 8x8 is unplugged |
| `php8.4-fpm.service` | pool `trackdsp` on `/run/php/trackdsp.sock`, 16 workers. Every open editor tab holds one worker for its event stream |
| `caddy.service` | port 80, serves `public/`, passes PHP to the pool, leaves `/api/events` uncompressed |

All three start at boot without a login. After a reboot the editor answers within about 25 seconds.

## Installation

The packages come from Debian, no third-party repository is needed.

```bash
sudo apt-get install --no-install-recommends caddy php8.4-cli php8.4-fpm php8.4-mbstring php8.4-xml \
    php8.4-intl php8.4-opcache php8.4-zip php8.4-curl composer unzip git
```

Copy the app without the development files. On the laptop:

```bash
git archive --format=tar -o trackdsp.tar main -- . ':!captures' ':!legacy' ':!tools' ':!tests'
scp trackdsp.tar khadas@sdwa5-dsp.local:/tmp/
```

On the board. The user `trackdsp` needs the groups `plugdev` (USB) and `audio` (auto EQ recordings):

```bash
sudo useradd --system --home-dir /var/lib/trackdsp --create-home --shell /usr/sbin/nologin trackdsp
sudo usermod -aG plugdev,audio trackdsp
sudo install -d -o trackdsp -g trackdsp -m 755 /srv/trackdsp
sudo -u trackdsp tar -x -C /srv/trackdsp -f /tmp/trackdsp.tar
cd /srv/trackdsp
sudo -u trackdsp cp deploy/vim3/env.local.dist .env.local   # then set APP_SECRET
sudo -u trackdsp env HOME=/var/lib/trackdsp composer install --no-dev --classmap-authoritative --no-interaction
sudo -u trackdsp env HOME=/var/lib/trackdsp composer dump-env prod
sudo -u trackdsp php bin/console asset-map:compile
```

`composer install` runs `importmap:install`, which fetches the browser libraries from the internet.

USB access and the services:

```bash
sudo install -m 644 udev/99-trackdsp.rules /etc/udev/rules.d/
sudo udevadm control --reload-rules && sudo udevadm trigger
sudo -u trackdsp php bin/trackdsp info -t usb

sudo install -m 644 deploy/vim3/trackdsp-bridge.service /etc/systemd/system/
sudo install -m 644 deploy/vim3/php-fpm-trackdsp.conf /etc/php/8.4/fpm/pool.d/trackdsp.conf
sudo cp -a /etc/caddy/Caddyfile /etc/caddy/Caddyfile.dist
sudo install -m 644 deploy/vim3/Caddyfile /etc/caddy/Caddyfile
sudo install -m 755 deploy/vim3/16-trackdsp /etc/update-motd.d/
sudo install -m 755 deploy/vim3/sdwa5-wan-dhcp /usr/local/sbin/
sudo install -m 644 deploy/vim3/sdwa5-wan-dhcp.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now trackdsp-bridge sdwa5-wan-dhcp
sudo systemctl restart php8.4-fpm caddy
```

The wifi fallback is `/etc/netplan/30-wifi-sdwa5.yaml` (mode 600, see [Network](#network)) with the key of
`SdWa5` under `access-points."SdWa5".auth.password` and `key-management: psk`. Apply it with
`sudo netplan generate && sudo netplan apply`.

## Update

Copy the new archive as above, extract it over `/srv/trackdsp` (`.env.local`, `.env.local.php` and `var/` stay),
then run `composer install`, `asset-map:compile` and `sudo systemctl restart trackdsp-bridge php8.4-fpm`.

## Checks

```bash
curl -s http://sdwa5-dsp.local/api/status        # "connected":true, "transport":"usb:/dev/hidraw0"
curl -sN -m 4 http://sdwa5-dsp.local/api/events  # meters about three times a second
ssh khadas@sdwa5-dsp.local 'systemctl status trackdsp-bridge caddy php8.4-fpm --no-pager'
ssh khadas@sdwa5-dsp.local 'journalctl -u trackdsp-bridge -f'
```

The login banner shows the state of the bridge and the web server and every editor address.

## Network

Off grid the Deco X50 has no uplink. Its wifi works without one, but it bridges its wired LAN port only after
its WAN port holds a DHCP lease. The board covers both, so the editor needs neither an uplink nor a DHCP box.

```
t.racks 8x8 ──USB──► VIM3 ──Ethernet──► Deco WAN port   (B: the VIM3 serves the Deco a lease)
                      ))) wifi SdWa5 ))) Deco           (A: fallback, fixed address)
t.racks 8x8 192.168.68.10 ──LAN──► Deco LAN port        (vendor editor, works once B has run)
```

| Path | Board address | Editor from the crew wifi |
|---|---|---|
| B, Ethernet to the Deco WAN port | `10.42.0.1/24`, DHCP server, the Deco gets `10.42.0.10`–`.49` | `http://10.42.0.1/` through the Deco's NAT |
| A, wifi `SdWa5` (`/etc/netplan/30-wifi-sdwa5.yaml`, WPA2, country DE) | `192.168.68.11/22`, gateway and DNS `192.168.68.1`, route metric 600 | `http://192.168.68.11/` |

`sdwa5-wan-dhcp.service` ([deploy/vim3/sdwa5-wan-dhcp](../deploy/vim3/sdwa5-wan-dhcp)) decides the role of
Ethernet. `end0` starts as a DHCP client. With a link but no lease after 10 s it serves DHCP itself, and when
the link drops it returns to client mode. On a LAN with a router (at a venue, for example) the board therefore stays a
client and never serves DHCP there. Plug it into the Deco's WAN port only. On the LAN port it would find no
router while the Deco waits for a lease and serve DHCP inside `SdWa5`.

A laptop that also has another uplink needs a route for B, for example
`nmcli dev modify wlp0s20f3 +ipv4.routes "10.42.0.0/24 192.168.68.1"`. Phones need none.

Power cuts, measured 2026-09-27 with the Deco offline, until the editor and both paths answer again:

| Cut | Back after |
|---|---|
| Deco only | about 95 s |
| Deco and VIM3 on one power strip | about 100 s |
| VIM3 only | about 70 s |

The editor on `192.168.68.11` comes first, path B and the Deco LAN port follow about 10 s later. The Deco's boot
sets the pace, the board is ready after about 25 s.

The board has an RTC (`rtc-hym8563`) without a backup battery. Off grid its clock resumes from the last saved
time (fake-hwclock) and runs late, which shows in log times and auto EQ file names. A 3 V cell on the RTC
connector fixes that.

Settings of an earlier port on this board are kept disabled and archived:

- `/etc/systemd/network/05-trackdsp-ap.network.disabled` (access point `10.77.0.1` on `wlan0`)
- `/etc/systemd/network/10-netplan-all-eth-interfaces.network.d/20-fritz-dhcp.conf.disabled` (leases from
  `192.168.188.1` only)
- `/var/backups/gitiglitzer-trackdsp-port-2026-09-27.tar.gz`
