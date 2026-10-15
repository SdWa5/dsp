#!/usr/bin/env bash
# Install one release archive member only after its committed SHA-256 matches.
# Usage: bash install-ci-tool.sh URL SHA256 ARCHIVE_MEMBER DESTINATION
set -euo pipefail

url="${1:?Expected a release URL}"
checksum="${2:?Expected a SHA-256}"
member="${3:?Expected an archive member}"
destination="${4:?Expected an installation path}"

work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

curl --fail --silent --show-error --location -o "$work/archive.tar.gz" -- "$url"
printf '%s  %s\n' "$checksum" "$work/archive.tar.gz" | sha256sum --check --status
tar -xzf "$work/archive.tar.gz" -C "$work" -- "$member"
install -m 755 -- "$work/$member" "$destination"
