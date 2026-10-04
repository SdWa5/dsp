#!/bin/bash
# Restarts the bridge daemon when its code is newer than the running process.
# The bridge is a long-running PHP process, so it keeps the code it was started with. Called by the
# post-exec and post-composer hooks in config.bridge.yaml, inside the web container from the project root.
# It does nothing while the code is unchanged, and it never restarts with device writes still queued.
set -u

pid=$(pgrep -fxo 'php bin/console sdwa5-dsp:bridge') || exit 0
elapsed=$(ps -o etimes= -p "$pid" | tr -d ' ')
[ -n "$elapsed" ] || exit 0
# etimes counts whole seconds and rounds down, so the computed start can lie up to a second early. A file
# changed in that second would count as newer forever, so only changes from one second later count.
started=$(( $(date +%s) - elapsed + 1 ))

changed=$(find src config bin/console composer.lock vendor/composer/installed.php .env .env.local \
    -newermt "@$started" -type f -print -quit 2>/dev/null)
[ -n "$changed" ] || exit 0

queued=$(curl -sf localhost/api/status | php -r 'echo json_decode(stream_get_contents(STDIN), true)["queued"] ?? 0;')
if [ "${queued:-0}" -gt 0 ] 2>/dev/null; then
    echo "sdwa5-dsp bridge: $changed changed, but $queued write(s) are queued. Not restarting; run it again later." >&2
    exit 0
fi

echo "sdwa5-dsp bridge: $changed is newer than the running bridge, restarting it." >&2
supervisorctl restart webextradaemons:sdwa5-dsp-bridge >/dev/null
