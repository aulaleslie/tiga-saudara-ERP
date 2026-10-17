# Design

## Context

See proposal.md for the confirmed receipt report. `buildReturnHistory` currently adds every persisted line to its items payload and translates `none` to `Lainnya`. Submission deliberately permits serialized context rows with no action; transaction 6878 has such a row without a linked Sales Return detail. Its original photo-paper quantity is five, completed cash return quantity one, current Sale quantity four, and current receipt projection four.

## Goals / Non-Goals

**Goals:** Correct customer-facing history for already persisted records through the shared projection, retaining existing effective return details.

**Non-Goals:** Change submission validation or persistence, delete historical context rows, change execution or quantity arithmetic, recalculate refunds, or add migrations.

## Decisions

### Filter at return-history item construction

Skip rows whose resolution is `PosReturnLine::RESOLUTION_NONE` in `buildReturnHistory` before constructing item labels and serial details. Keep the collection loaded for other consumers and preserve completed headers, order, and settlement calculation. Do not filter by positive amount or linked Sales Return detail: replacements can have no refund, and existing bundle/context provenance requires care. Restrict the fix to the confirmed no-action case rather than inventing new eligibility rules for other resolutions.

Filtering only in Blade would leave incorrect data for other consumers. Filtering the eager-loaded relation globally could affect quantity or serial resolution. Changing submission alone would not correct existing receipts and would expand lifecycle scope.

### Focused regression in existing receipt tests

Extend `Modules/Pos/Tests/Feature/POSReceiptReturnProjectionTest.php` with the reported mixed sequence using synthetic fixtures. Assert individual history items and serial pairs, preserved settlement fields, remaining quantity four, current value at the persisted price, and unchanged stored context evidence. Render the receipt and inspect the second return block so a laptop in the main purchase list or earlier replacement does not cause a misleading global absence assertion.

Run `php artisan test Modules/Pos/Tests/Feature/POSReceiptReturnProjectionTest.php` using the existing test database configuration. This focused file covers actual cash returns, replacements, serial chains, bundle components, and endpoint parity. No full suite or `composer test:fresh-sqlite` is planned or required.

## Risks / Trade-offs

- Overbroad filtering could hide legitimate zero-refund replacements or bundle details → filter only the explicit no-action resolution and retain existing focused receipt cases.
- An assertion against the whole receipt could mistake a valid main-list laptop for the unwanted history row → assert the second history entry and rendered block specifically.
- Context rows remain stored → intentional compatibility with existing audit and lifecycle behavior; printing does not rewrite history.

## Migration Plan

Deploy the projection change normally without data migration. Existing receipts benefit on regeneration. Rollback is an application-code revert; persisted financial and return evidence remains intact.
