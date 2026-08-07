#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DB="$ROOT_DIR/database/testing.sqlite"

# Clean up and create fresh database
rm -f "$TEST_DB"

cd "$ROOT_DIR"

# Export testing environment
export APP_ENV=testing
export DB_CONNECTION=sqlite

# Run migrations with a fresh database
php artisan migrate

# Run the preflight to show the report
echo "=========================================="
echo "Running product identity preflight..."
echo "=========================================="
php artisan product:identity-preflight

echo ""
echo "=========================================="
echo "All reconciliation workflow steps complete!"
echo "=========================================="
