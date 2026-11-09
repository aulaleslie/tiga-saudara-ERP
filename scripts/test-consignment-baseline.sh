#!/usr/bin/env bash
# Verified consignment baseline: the dedicated Consignment suite plus every other test file that
# references consignment (see phpunit.consignment.xml). Runs through the locking wrapper because the
# SQLite test database is shared. Extra args are passed through to `php artisan test`.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
exec bash scripts/test-with-fresh-sqlite.sh --configuration=phpunit.consignment.xml "$@"
