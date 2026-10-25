# Tasks

## 1. Evidence planning and read-only command

- [x] 1.1 Trace existing return/payment locks, privileged correction audit patterns, and command registration; record the chosen serialization and audit mechanisms in design.md and verify they cover all affected writers.
- [x] 1.2 Implement checkout-group evidence collection and minor-unit reconciliation for captured rows, owner allocation, completed non-reversed cash returns, payments and current headers; verify focused service tests for proven drift, correct rows, owner splits, partial returns, legitimate decimal values, and blocked ambiguity.
- [x] 1.3 Add and register `pos:repair-return-balances` with required business scope, repeatable explicit transaction IDs, dry-run default, classifications and before/after output; verify focused command tests for missing/invalid scope, mismatched business, unchanged selection, and zero dry-run writes.
- [x] 1.4 Document preview/apply syntax, supported evidence, block reasons and the distinction between stored rows and projected receipt rows; verify examples agree with command help and tests.

## 2. Atomic audited correction

- [x] 2.1 Implement actor/reason validation, stable locking, complete fresh evidence fingerprint comparison, and minimal header/due/status updates preserving paid amounts; verify focused tests for changed evidence, invalid actor, protected records and atomic rollback.
- [x] 2.2 Persist structured correction audit evidence using the mechanism chosen in 1.1, adding an additive migration only if required; verify focused tests for before/after values, actor/source IDs, audit rollback and idempotent no-op reruns.
- [x] 2.3 Integrate fresh current receipt/settlement projection checks inside the correction transaction; verify projection failure rolls back and multiple selected groups have explicit independent outcomes without duplicate group correction.
- [x] 2.4 Document operational review and evidence-based rollback limits; verify documentation makes clear implementation/testing never applies corrections to live transaction 9218.

## 3. Focused integration verification

- [x] 3.1 Add a synthetic reproduction of transaction 9218's three owner sales, 8+2 fully returned ink, blended marker price, Rp0.40 header drift and Rp375,000 payment; verify real receipt projection before/after repair, all fixture remaining commercial row totals at Rp100 multiples, preserved return history, Rp30,105,000 total and Rp29,730,000 due.
- [x] 3.2 Run only the new focused command/service tests and directly affected existing receipt-return projection tests with `php artisan test` paths or filters; record exact commands and outcomes, and run PHP syntax checks on changed PHP files. Do not schedule or run the full suite for this change.
- [x] 3.3 Run `openspec validate repair-pos-return-balance-drift --strict` and review the diff for unintended application changes; verify all artifacts agree on the narrow repair exception and preservation of completed returns.
