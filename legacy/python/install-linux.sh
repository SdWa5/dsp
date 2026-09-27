#!/usr/bin/env bash
# Installiert den t.racks-8x8-Editor für den aktuellen Benutzer unter Linux.
#   ./install-linux.sh            installieren
#   ./install-linux.sh --uninstall
set -euo pipefail

PREFIX="${HOME}/.local/share/trackdsp"
BIN="${HOME}/.local/bin"
APPS="${HOME}/.local/share/applications"
ICONS="${HOME}/.local/share/icons/hicolor/scalable/apps"
HERE="$(cd "$(dirname "$0")" && pwd)"

if [[ "${1:-}" == "--uninstall" ]]; then
    rm -rf "$PREFIX" "$BIN/trackdsp" "$BIN/trackdsp-gui" "$APPS/trackdsp.desktop" "$ICONS/trackdsp.svg"
    echo "Entfernt. (udev-Regel ggf. mit: sudo rm /etc/udev/rules.d/99-trackdsp.rules)"
    exit 0
fi

command -v python3 >/dev/null || { echo "python3 fehlt (z. B. sudo apt install python3 python3-venv)"; exit 1; }
python3 -c 'import sys; sys.exit(sys.version_info < (3, 10))' || { echo "Python >= 3.10 nötig"; exit 1; }

echo "==> Python-Umgebung in $PREFIX"
python3 -m venv "$PREFIX"
"$PREFIX/bin/pip" install --upgrade pip >/dev/null
"$PREFIX/bin/pip" install "$HERE"

mkdir -p "$BIN" "$APPS" "$ICONS"
ln -sf "$PREFIX/bin/trackdsp" "$BIN/trackdsp"
ln -sf "$PREFIX/bin/trackdsp-gui" "$BIN/trackdsp-gui"
sed "s|^Exec=.*|Exec=$BIN/trackdsp-gui|" "$HERE/trackdsp/data/trackdsp.desktop" > "$APPS/trackdsp.desktop"
cp "$HERE/trackdsp/data/trackdsp.svg" "$ICONS/trackdsp.svg"
command -v update-desktop-database >/dev/null && update-desktop-database "$APPS" || true

echo "==> USB-Zugriff ohne root (udev-Regel, benötigt sudo)"
if [[ ! -f /etc/udev/rules.d/99-trackdsp.rules ]]; then
    if sudo cp "$HERE/udev/99-trackdsp.rules" /etc/udev/rules.d/ && sudo udevadm control --reload-rules \
            && sudo udevadm trigger; then
        echo "    udev-Regel installiert – Gerät einmal aus- und wieder einstecken."
    else
        echo "    übersprungen – USB dann nur als root; LAN funktioniert trotzdem."
    fi
fi

echo
echo "Fertig. Start über das Anwendungsmenü („t.racks 8x8 Matrix Editor“) oder:"
echo "    trackdsp-gui                         (Verbindungsdialog)"
echo "    trackdsp-gui --target 192.168.68.10  (LAN)"
echo "    trackdsp-gui --demo                  (ohne Gerät)"
