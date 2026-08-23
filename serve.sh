#!/usr/bin/env bash
# Development server (Linux / macOS / WSL).
#
# Unlike Windows, the built-in server here can fork workers:
#   PHP_CLI_SERVER_WORKERS=4 ./serve.sh
# That still is not a substitute for bin/concurrency_test.php, which exercises
# the booking transaction directly with a synchronised start.
#
# Usage: ./serve.sh [host:port]      default 127.0.0.1:8000

set -euo pipefail

cd "$(dirname "$0")"

HOSTPORT="${1:-127.0.0.1:8000}"
echo "Serving http://${HOSTPORT}/  (Ctrl+C to stop)"
exec ./php.sh -S "$HOSTPORT" -t public public/index.php
