# POS Phase 2 Gap Closure Implementation Plan

Date: 2026-03-12
Owner: POS Team

## Objective

Close all remaining Phase 2 gaps identified after implementation review so Phase 2 is operationally complete and contract-aligned.

Gaps to close:
1. Missing structured error code `TRANSACTION_EMPTY_BLOCKED` on cart mutation APIs.
2. Missing feature flag gate `pos.transactions.enabled`.
3. Missing `snapshot_hash` drift guard before draft load.
4. Incomplete regression closure because `POSCheckoutFinalizeIdempotencyTest` now fails on payment-method precondition.
5. Missing date filters on transaction list API (contract parity with plan table).

## Locked Decisions

1. Keep HTTP status `422` for loaded-transaction empty-block violations, but always include machine-readable `code=TRANSACTION_EMPTY_BLOCKED`.
2. Implement transaction feature flag as a dedicated setting column: `settings.pos_transactions_enabled` (boolean, default `false`).
3. `snapshot_hash` is computed server-side from persisted transaction header+lines+serials using deterministic canonical JSON.
4. Snapshot drift blocks load with `409 SNAPSHOT_DRIFT` (no auto-repair on load).
5. Test target remains SQLite (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) per current repository baseline.

## Scope

In scope:
1. Backend/API hardening for transaction empty-block error contract.
2. Feature flag middleware + route/UI gating for transactions.
3. Snapshot hash schema + generation + validation in save/load lifecycle.
4. Checkout idempotency regression fixture repair.
5. Transaction list date filtering (`date_from`, `date_to`) end-to-end.

Out of scope:
1. Phase 3 split-posting behavior changes.
2. Phase 4 serial modal redesign.
3. New transaction permissions beyond current Phase 2 set.

## Workstream A - Error Contract: `TRANSACTION_EMPTY_BLOCKED`

### Implementation

1. Add explicit POS cart mutation exception type with machine code support.
- New class: `Modules/Pos/Services/Exceptions/PosCartMutationException.php`
- Fields:
1. `errorCode` (string)
2. message
3. optional HTTP status (default 422)

2. Replace empty-block `DomainException` throw sites in `PosCartService`.
- Methods: `assertNotLastLineOfLoadedTransaction()` for both clear and remove-last-line paths.
- Throw `PosCartMutationException('TRANSACTION_EMPTY_BLOCKED', 'Transaksi yang dimuat tidak dapat dikosongkan.')`.

3. Update cart controller exception mapping.
- File: `Modules/Pos/Http/Controllers/PosSellController.php`
- For `cartUpdateLine`, `cartDestroyLine`, `cartClear`: catch `PosCartMutationException` before generic `DomainException`.
- Response shape:
```json
{
  "code": "TRANSACTION_EMPTY_BLOCKED",
  "message": "Transaksi yang dimuat tidak dapat dikosongkan."
}
```

### Tests

1. Update `POSTransactionEmptyBlockTest` assertions to include `code`.
2. Add focused controller-contract test if needed for all three mutation endpoints.

Acceptance criteria:
1. All loaded-transaction empty-block responses are `422` with `code=TRANSACTION_EMPTY_BLOCKED`.
2. Existing non-empty-block validation errors keep current behavior.

## Workstream B - Feature Flag: `pos.transactions.enabled`

### Data Model

1. Add migration in `Modules/Setting/Database/Migrations`:
- `add_pos_transactions_enabled_to_settings_table`
- Column: `pos_transactions_enabled` boolean default `false`, indexed if needed.

2. Update `Modules/Setting/Entities/Setting.php` casts:
- `'pos_transactions_enabled' => 'boolean'`

### Middleware + Routing

1. Add middleware:
- `Modules/Pos/Http/Middleware/PosTransactionsEnabledMiddleware.php`
- Behavior:
1. Resolve `setting_id` from session.
2. Check `settings.pos_transactions_enabled`.
3. If disabled: return `403` or redirect with warning (use same style as `PosEnabledMiddleware`).

2. Register alias in `app/Http/Kernel.php`:
- `'pos.transactions.enabled' => \Modules\Pos\Http\Middleware\PosTransactionsEnabledMiddleware::class`

3. Apply middleware to transaction routes in `Modules/Pos/Routes/web.php`:
- `POST /pos/sell/transactions/save-and-new`
- `GET /pos/transactions`
- `GET /pos/transactions/data`
- `GET /pos/transactions/{transaction}`
- `POST /pos/transactions/{transaction}/load`
- `POST /pos/transactions/{transaction}/cancel`

### UI Gating

1. Hide/disable transaction entry points when flag is off:
- `resources/views/layouts/menu.blade.php` (sidebar POS transaction menu)
- `Modules/Pos/Resources/views/sell.blade.php`:
1. `Transaksi POS` shortcut in dropdown
2. `Simpan dan Buka Baru` action button

Acceptance criteria:
1. Transaction endpoints are inaccessible when feature flag is off, while core POS sell remains available.
2. Transaction menus/buttons are not shown in disabled settings.
3. Enabling per setting immediately activates transaction features for that setting.

## Workstream C - Snapshot Drift Guard (`snapshot_hash`)

### Data Model

1. Add migration in `Modules/Pos/Database/Migrations`:
- Add nullable `snapshot_hash` (`char(64)`) to `pos_transactions`.
- Add index on `snapshot_hash` only if required for operations/debug; otherwise no extra index.

2. Update `Modules/Pos/Entities/PosTransaction.php` fillable/casts as needed.

### Hash Strategy

1. Add deterministic hash builder in `PosTransactionSnapshotMapper` (or dedicated `PosTransactionSnapshotHashService`).
2. Canonical payload should include:
1. `setting_id`, `owner_user_id`, `customer_id`
2. line items ordered by `line_no` (product, conversion, qty, unit_price, tax fields, discounts)
3. serials ordered lexicographically per line
4. snapshot totals (`subtotal`, `discount_total`, `tax_total`, `grand_total`)
3. Hash algorithm: SHA-256 over canonical JSON with stable key ordering.

### Lifecycle Integration

1. On save (`PosTransactionService::saveAndNew`):
1. Persist lines/serials.
2. Recompute and store `snapshot_hash`.

2. On load (`PosTransactionService::loadToCart`):
1. Recompute expected hash from current persisted rows.
2. Compare with stored `snapshot_hash`.
3. If mismatch: throw `PosTransactionConflictException('SNAPSHOT_DRIFT', 'Data transaksi berubah dan perlu disimpan ulang.')` and return `409`.

3. Optional maintenance command (future-safe, non-blocking for this phase):
- `pos:transactions:rebuild-snapshot-hash`

### Tests

1. Unit tests for deterministic hashing:
- same logical content => same hash
- serial order normalization => same hash
- line mutation => different hash

2. Feature tests:
- successful load when hash matches
- `409 SNAPSHOT_DRIFT` when persisted data is tampered

Acceptance criteria:
1. Every saved draft has non-null `snapshot_hash`.
2. Load is blocked when persisted content drifts from expected hash state.

## Workstream D - Regression Repair: `POSCheckoutFinalizeIdempotencyTest`

### Root Cause

`PosSessionLifecycleService::openSession()` now requires at least one enabled payment method for the active setting. The idempotency test opens session before enabling any method.

### Implementation

1. Update test fixture flow in `Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`:
1. Create payment methods + enable at least one for setting before calling `openSession()`.
2. Return enabled methods from `createCheckoutContext()` so each test reuses a valid method set.
3. Remove redundant per-test `seedPaymentMethods()` calls after context creation.

2. Ensure setting-payment assignment rows are enabled:
- Use `setting_pos_payment_methods` update/insert for generated methods.

### Test Commands (SQLite)

1. `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
2. `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test Modules/Pos/Tests/Feature/POSShellSessionGuardTest.php Modules/Pos/Tests/Feature/POSNavigationMenuVisibilityTest.php`

Acceptance criteria:
1. `POSCheckoutFinalizeIdempotencyTest` passes fully under SQLite.
2. No regression in session guard and menu visibility suites.

## Workstream E - Transaction List Date Filters

### API Contract

Add optional query params to `GET /pos/transactions/data`:
1. `date_from` (`YYYY-MM-DD`)
2. `date_to` (`YYYY-MM-DD`)

Behavior:
1. `date_from` => `created_at >= date_from 00:00:00`
2. `date_to` => `created_at <= date_to 23:59:59`
3. If both set and `date_from > date_to`, return `422 INVALID_DATE_RANGE`.

### Implementation

1. Validate query parameters in `PosTransactionController::data()` (or dedicated request class).
2. Extend `PosTransactionService::list()` filters to apply date range.
3. Update `Modules/Pos/Resources/views/transactions/index.blade.php`:
1. Add date-from and date-to fields.
2. Send query params in AJAX request.

### Tests

1. Extend `POSTransactionListTest`:
1. filters by date range
2. invalid range returns `422 INVALID_DATE_RANGE`

Acceptance criteria:
1. Transaction list supports date filtering correctly.
2. Existing status/owner/code filters continue to work.

## Delivery Sequence (PR Plan)

1. PR-1: Workstream A (`TRANSACTION_EMPTY_BLOCKED` contract) + tests.
2. PR-2: Workstream B (`pos.transactions.enabled` migration, middleware, route/UI gates) + tests.
3. PR-3: Workstream C (`snapshot_hash` migration + lifecycle validation) + tests.
4. PR-4: Workstream D (idempotency regression fixture repair) + rerun evidence.
5. PR-5: Workstream E (date filters API/UI/tests).

## Migration and Deployment Order

1. Deploy schema changes first:
1. `settings.pos_transactions_enabled`
2. `pos_transactions.snapshot_hash`

2. Deploy backward-compatible code with transaction flag default OFF.
3. Enable `pos_transactions_enabled` only for pilot settings.
4. Execute regression suite before wider enablement.

## Rollback Strategy

1. Immediate functional rollback: set `pos_transactions_enabled = false` for affected settings.
2. Keep new columns in place (non-destructive rollback).
3. If snapshot checks cause unexpected blocks, temporarily bypass via guarded hotfix while preserving audit logs.

## Definition of Done

1. Empty-block cart mutations return `422` + `code=TRANSACTION_EMPTY_BLOCKED`.
2. Transaction features are fully controlled by `pos.transactions.enabled` per setting.
3. `snapshot_hash` is written on save and validated on load with `409 SNAPSHOT_DRIFT` on mismatch.
4. `POSCheckoutFinalizeIdempotencyTest` passes under SQLite test target.
5. Transaction list supports `date_from/date_to` with validated range semantics.
6. Phase 2 targeted and regression test suites pass and are documented in PR notes.
