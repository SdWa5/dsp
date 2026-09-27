#!/usr/bin/env bash
# Bestandsaufnahme der WSL-Umgebung (nur lesend)
echo "Benutzer: $(whoami)"
grep PRETTY_NAME /etc/os-release
python3 --version
python3 -c "import venv, ensurepip" 2>/dev/null && echo "venv: ok" || echo "venv: fehlt (python3-venv)"
sudo -n true 2>/dev/null && echo "sudo: ohne Passwort" || echo "sudo: braucht Passwort"
echo "DISPLAY=$DISPLAY WAYLAND_DISPLAY=$WAYLAND_DISPLAY"
ip -4 route | head -3
ping -c1 -W2 192.168.68.10 >/dev/null 2>&1 && echo "DSP 192.168.68.10: erreichbar" || echo "DSP 192.168.68.10: nicht erreichbar"
for lib in libEGL.so.1 libxkbcommon.so.0 libxcb-cursor.so.0 libfontconfig.so.1 libdbus-1.so.3 libGL.so.1; do
    ldconfig -p | grep -q "$lib" && echo "lib $lib: ok" || echo "lib $lib: fehlt"
done
