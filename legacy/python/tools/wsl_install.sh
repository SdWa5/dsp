#!/usr/bin/env bash
# Projekt nach ~/trackdsp-src kopieren, mit install-linux.sh installieren, Tests laufen lassen.
set -euo pipefail
SRC="${WIN_HOME:-/mnt/c/Users/$USER}/Projekte/T.rack"
DST="$HOME/trackdsp-src"
rm -rf "$DST"
mkdir -p "$DST"
cd "$SRC"
tar --exclude=.venv --exclude=captures --exclude=tools/shots --exclude='__pycache__' --exclude='*.egg-info' \
    --exclude=build -cf - . | tar -xf - -C "$DST"
cd "$DST"
chmod +x install-linux.sh
echo "==> install-linux.sh"
./install-linux.sh </dev/null 2>&1 | tail -15
echo
echo "==> Startbefehle"
ls -l "$HOME/.local/bin/trackdsp" "$HOME/.local/bin/trackdsp-gui"
ls -l "$HOME/.local/share/applications/trackdsp.desktop" "$HOME/.local/share/icons/hicolor/scalable/apps/trackdsp.svg"
echo
echo "==> Tests unter Linux"
"$HOME/.local/share/trackdsp/bin/pip" install -q pytest
QT_QPA_PLATFORM=offscreen "$HOME/.local/share/trackdsp/bin/python" -m pytest -q tests 2>&1 | tail -5
