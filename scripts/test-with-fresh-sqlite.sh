#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DB="$ROOT_DIR/database/testing.sqlite"
LOCK_DIR="$ROOT_DIR/storage/framework/cache"
LOCK_FILE="$LOCK_DIR/testing-sqlite.lock"

mkdir -p "$LOCK_DIR"

exec 9>"$LOCK_FILE"
flock 9

rm -f "$TEST_DB"

cd "$ROOT_DIR"
php artisan test "$@"
