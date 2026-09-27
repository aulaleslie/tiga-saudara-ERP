## Why

The `pos_checkout_payments` migration (`2026_03_17_000000`) runs **before** the `pos_checkouts` migration (`2026_08_13_000300`), causing `php artisan migrate:fresh --seed` to fail with:

> SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'pos_checkouts'

The foreign key `->constrained('pos_checkouts')` cannot be satisfied because the referenced table does not yet exist at that point in the migration sequence.

## What Changes

- Rename `2026_03_17_000000_create_checkout_payment_tables.php` to `2026_08_13_000400_create_checkout_payment_tables.php` so it runs **after** `pos_checkouts` is created at `2026_08_13_000300`.

## Capabilities

### New Capabilities

_None — this is a migration ordering fix only._

### Modified Capabilities

_None — no requirement-level changes._

## Impact

- **Migration order**: The `pos_checkout_payments` and `pos_checkout_payment_allocations` tables will now be created after `pos_checkouts`, resolving the FK constraint failure.
- **Existing databases**: No impact on databases that already have these tables. Only affects `migrate:fresh` (full reset) scenarios.
- **No code changes**: Only the migration filename changes; the migration content stays identical.
