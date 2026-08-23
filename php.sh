#!/usr/bin/env bash
# Run a project CLI script under PHP 8.2+ (Linux / macOS / WSL).
#
# Set PHP_BIN to override the binary, e.g. when a distro ships an older default:
#   PHP_BIN=/usr/bin/php8.2 ./php.sh bin/migrate.php
#
# Usage: ./php.sh bin/migrate.php

set -euo pipefail

if [[ -z "${PHP_BIN:-}" ]]; then
  for candidate in php8.4 php8.3 php8.2 php; do
    if command -v "$candidate" >/dev/null 2>&1; then
      PHP_BIN="$(command -v "$candidate")"
      break
    fi
  done
fi

if [[ -z "${PHP_BIN:-}" ]]; then
  echo "PHP was not found. Install PHP 8.2+ or set PHP_BIN." >&2
  echo "See README.md for setup instructions." >&2
  exit 1
fi

version="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION;')"
if (( version < 802 )); then
  echo "PHP 8.2 or newer is required; $PHP_BIN reports $("$PHP_BIN" -r 'echo PHP_VERSION;')." >&2
  exit 1
fi

exec "$PHP_BIN" "$@"
