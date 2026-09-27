# Rollout, Activation and Rollback

## What deploys

- Migration `2026_09_23_100000_add_v3_allocation_workflow_to_transfers`: additive only. Relaxes `transfers.origin_location_id` and `transfer_movements.origin_location_id` to NULL (foreign keys and indexes preserved), adds nullable v3 columns on `transfers` (`v3_reference_key` unique, `created_in_setting_id`, `current_request_revision_id`, `approval_configuration_revision`, `cancelled_by/at`, `cancellation_reason`), a nullable `transfer_movement_allocation_id` on `transfer_movement_serials`, and four new tables (`transfer_request_revisions`, `transfer_approval_allocations`, `transfer_approval_allocation_serials`, `transfer_movement_allocations`). No existing row is updated.
- Migration `2026_09_23_100001_register_stock_transfer_v3_permissions`: creates `stockTransfers.cancel-dispatch` and `stockTransfers.view-history` (also listed in `app/Config/Permissions.php`). Only the Admin role receives them automatically; every other role must be granted them explicitly.
- Config flag `stock_transfers.v3_creation_enabled` (`STOCK_TRANSFERS_V3_CREATION_ENABLED`, default `false`).

## Activation

1. Take a normal database backup and record a read-only lifecycle distribution (`SELECT workflow_version, status, COUNT(*) FROM transfers GROUP BY 1, 2`). This is a deployment instruction only.
2. Run `php artisan migrate`. With the flag off, creation stays legacy and every existing document keeps its version and services.
3. Grant roles explicitly: `stockTransfers.show` for every operator who creates/edits/submits (successful actions redirect to detail; no permission is implied by a mutation), `stockTransfers.approval` for allocators, `stockTransfers.receive` for receivers, `stockTransfers.cancel-dispatch` and `stockTransfers.view-history` as decided. Permissions apply in the active business; route-business membership is not required.
4. Run the human checklist in `manual-verification.md` on a disposable environment.
5. Set `STOCK_TRANSFERS_V3_CREATION_ENABLED=true`, then `php artisan config:clear` (or re-cache). From then on `transfers.create` renders the v3 form and the legacy `transfers.store` HTTP contract refuses to create documents.
6. Monitor `Stock transfer v3 action failed` warnings in the application log.

## Rollback

- Operational rollback is disabling creation: set `STOCK_TRANSFERS_V3_CREATION_ENABLED=false` and clear config. New transfers are created by the legacy form again. Already-created v3 documents remain fully readable and operable (approval, receipt, cancellation) because the v3 readers, routes and executors stay deployed.
- Do not roll back the code to a release without v3 support while v3 documents exist: legacy code cannot interpret their null headers.
- Do not run `migrate:rollback` for these migrations on populated data. The schema migration's `down()` refuses when any `workflow_version = 3` transfer exists and never re-tightens the relaxed origin columns.
- Never rewrite historical records: no status conversion, renumbering, version change, inventory repair, or deletion of dispatch/receipt/cancellation evidence or legacy return history.

## Verification performed (isolated in-memory SQLite, `phpunit.xml`)

| Command | Result |
| --- | --- |
| `php artisan test Modules/Adjustment/Tests --filter=TransferV3` | 53 passed (355 assertions) |
| `php artisan test --filter=TransferV3` (after the 2026-09-25 approval summary readiness fix and fallback validation gating) | 72 passed (583 assertions) |
| `node --test tests/js/transfer-v3-scan-coordinator.test.mjs` (v3 scan coordinator core: FIFO, ambiguity pause, single in-flight lock across scan/choice/cancel/retry, slow-without-timeout, recoverable vs fatal failure, save gating and intake freeze, CR/LF) | 28 passed |
| `node --test tests/js/transfer-v3-approval-allocation.test.mjs` (approval stock-line text, row renumbering, summary readiness and fallback) | 11 passed |
| `node --test tests/js/transfer-v3-livewire-transport.test.mjs` (Livewire adapter against a fake reproducing the v3.0.5 hook order: commit-fail before request-fail, error-modal suppression only for our requests, 419 default, network failure in search/quantity edit/queued scan → coordinator fatal) | 10 passed |
| `php artisan test --filter='TransferScan\|TransferSearchProduct\|TransferEntryInteraction'` (legacy scanner regression, 2026-09-24) | 1 failed, 44 passed — `unknown_exact_scan_does_not_fall_through_to_fuzzy_search` also fails with the committed (HEAD) resolver |
| `php artisan test Modules/Adjustment/Tests --filter=Transfer` (legacy regression, includes v3) | 2 failed, 343 passed — both failures pre-existing on the unmodified baseline (see below) |

Pre-existing failures, reproduced on the baseline with this change stashed:

- `TransferEntryInteractionCoordinatorTest::unknown_exact_scan_does_not_fall_through_to_fuzzy_search` — the legacy resolver's text fallback returns a candidate for `LENOVO`.
- `TransferStockConditionSegmentedControlTest::an_existing_transfer_renders_a_read_only_badge_with_no_buttons` — the legacy save buttons' `wire:target` attribute contains `selectStockCondition`.

The full application suite was not run, and nothing ran against the production replica.

## Known verification limit

SQLite cannot prove MySQL row-lock behavior. Receipt/cancellation mutual exclusion, dispatch serial-claim competition and replay are verified sequentially (terminal-state guards, the unique active-claim index and operation keys). No disposable MySQL test database was available, so a real concurrent locking race (two simultaneous requests on `SELECT ... FOR UPDATE` of the transfer row) remains **unverified on MySQL**. Run one on a disposable MySQL database before activation if that assurance is required.

## Known limitation: Livewire 3.0.5 network failures

In Livewire v3.0.5 (`composer.lock`), a network-level `fetch` rejection escapes `queueNewRequestAttemptsWhile` without firing any failure hook and leaves `sendingRequest` set, so the page cannot send further Livewire requests until reload. The v3 goods form detects this case for any Livewire request on the page, including modal searches, quantity edits and other components, not only scan/save requests. It then shows a reload instruction that lists the unrecorded scans (`resources/js/transfer/v3-livewire-transport.js`). Unsaved form rows are lost on reload. HTTP error responses do not have this problem and can be retried in place. Upgrading Livewire is out of scope for this change.

## Note: v3 Jumlah input is not `wire:model`-bound

On the real create/edit pages, `resources/js/bootstrap.js` puts a second Alpine instance (npm 3.15.2) on `window.Alpine`, next to the Alpine 3.13.0 bundled in Livewire 3.0.5. In a scratch reproduction (synthetic SQLite fixtures, headless browser), a `wire:model.blur` Jumlah input kept showing 1 after a second serial scan. Meanwhile the server state, the Livewire client state and the server-rendered hint were all 2. The same component without the app layout showed 2. The v3 Jumlah input is therefore server-rendered: it is keyed by its value and edits go through `setQuantity`. The app-wide dual-Alpine setup is unchanged and may affect other `wire:model` inputs whose values the server changes. That needs a separate review.
