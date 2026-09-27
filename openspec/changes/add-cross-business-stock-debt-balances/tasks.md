# Tasks

## 1. Schema and Domain Foundation

- [ ] 1.1 Add MySQL- and SQLite-compatible migrations for `transfer_business_debt_events` and `transfer_business_debt_balances`, using unsigned big integers for event quantity, signed big integers for delta/balance, the specified provenance foreign keys, canonical pair/product/condition uniqueness, and unique `receipt_allocation_id`; also make approval-allocation source nullable for incomplete progress, and verify with focused migration/schema tests only.
- [ ] 1.2 Add Eloquent entities and relationships for debt events and balances without changing legacy V1/V2 obligation models; verify focused model tests cover casts, provenance relationships, and canonical uniqueness.
- [ ] 1.3 Register `stockTransfers.view-business-debt` through the existing permission configuration and migration conventions, automatically grant it only to the existing Admin role, and require explicit assignment for other roles without granting unrelated action authority; verify focused permission synchronization and authorization tests.

## 2. Balance Ledger and Rehydration

- [ ] 2.1 Implement one domain service that canonicalizes a business pair and converts source-to-destination receipt direction into exact signed arithmetic; verify focused tests cover accumulation, partial settlement, exact zero, excess direction flip, both directions, and no floating-point coercion.
- [ ] 2.2 Implement transactional event posting and locked current-balance updates with deterministic key ordering and at-most-once receipt-allocation identity; verify focused tests cover duplicate posting, multiple allocations for one key, and rollback on a forced later failure.
- [ ] 2.3 Keep product and stock condition in the balance key while excluding serial identity and tax bucket from matching; verify focused tests prove that different products and conditions do not net and substitute serials of the same product contribute their received quantity.
- [ ] 2.4 Add idempotent `stock-transfers:rehydrate-business-debt` and its service, processing only completed cross-business V3 receipt allocations in stable order; implement read-only `--dry-run` counts for eligible, existing, missing, and divergent records, and verify missing events, repeated execution, same-business exclusion, V1/V2 exclusion, divergence reporting, and the current production-equivalent zero-result dataset.

## 3. Stock Transfer V3 Receipt Integration

- [ ] 3.1 Integrate debt-event posting into the existing locked `TransferV3ReceiptExecutor` transaction after deriving authoritative receipt allocations and before commit; verify focused receipt tests show inventory, serial custody, history, transfer completion, events, and balances commit together.
- [ ] 3.2 Preserve same-business V3 and all V1/V2 behavior without new debt effects; verify existing focused V3 receipt cases plus targeted legacy characterization tests remain green.
- [ ] 3.3 Verify receipt action replay does not duplicate inventory or debt and a debt persistence failure rolls back all earlier allocation effects using focused feature tests.
- [ ] 3.4 Add practical concurrency-focused coverage for two receipts affecting the same canonical balance and document any database-locking limitation of SQLite rather than substituting a full-suite or browser test.

## 4. Dashboard Projection and Authorization

- [ ] 4.1 Implement a product-by-debtor-business matrix projection using the fixed sign convention, returning one row per product with any nonzero authoritative debt, collapsed totals owed by each business across creditors/conditions, zeroes for businesses owing none, and creditor breakdowns whose sums equal the collapsed totals; verify both directions, multiple creditors, condition aggregation, exact settlement, and exclusion of products with no debt.
- [ ] 4.2 Add live in-transit aggregation only from V3 dispatch allocations whose transfer remains dispatched and which lack settling receipt/cancellation allocations; expose projections only for products already listed by authoritative debt, and verify pending receipt, cancellation, receipt settlement, exclusion of in-transit-only products, direction flip, multi-route documents, and intervening balance changes.
- [ ] 4.3 Add permission-gated routes/controllers or Livewire boundaries for balance list and provenance drill-down, returning only business, product, condition, quantity, projection, and permitted transfer evidence; verify focused authorization and response-shape tests cover denial, Super Admin bypass, and absence of protected stock/tax/route payloads.
- [ ] 4.4 Add the Stock Transfer submenu and a one-row-per-product matrix following the existing Stok Lintas Bisnis sticky-product and expandable-business-column interaction; collapsed business cells show total owed, expanded cells show creditor breakdown, and render-level feature/component tests verify headers, zero cells, sums, expansion state, and empty state without Chromium or automated browser tooling.
- [ ] 4.5 Keep transfer create, approval, receipt, cancellation, route configuration, and history actions gated by their existing permissions rather than the new dashboard permission; verify focused cross-permission tests.
- [ ] 4.6 Add one checkbox per listed product row with unrestricted cross-row selection and require both balance-view and `stockTransfers.create`; verify focused component/request tests accept products owed by different businesses and reject products without current debt, missing permissions, and forged selections.
- [ ] 4.7 Implement a permission-checked POST launch that stores only validated, deduplicated product IDs under a random one-time authenticated-session key, then have the existing V3 form consume and reauthorize that key before initializing zero-quantity rows without carrying business, quantity, condition, or route context; verify valid, duplicate, missing, reused, foreign-session, stale, inactive, malformed, and non-stock-managed cases.
- [ ] 4.8 Extend all V3 draft entry paths, including standard create and dashboard prefill, to retain zero-quantity rows and all-zero or mixed drafts without inventory or custody effects; verify focused service/feature tests cover create, reload, edit, serial preservation, standard entry, prefilled entry, and no operational side effects.
- [ ] 4.9 Preserve existing manual quantity and serial scanning behavior while enforcing submission-only positivity for every retained row; verify focused tests cover non-serialized entry, serialized scans, rejection when any row is zero, unchanged draft after failed submission, valid submission, and later allocation routes differing from dashboard context.

## 5. Resumable Approval Workspace

- [ ] 5.1 Refactor Simpan Progres to persist every explicit structurally valid incomplete allocation row and its order—including zero quantities, missing source or destination, mismatched totals, same-source-and-destination choices, and currently insufficient stock—without executable-plan validation, reservation, or inventory effects; verify focused allocation-service and controller tests for exact save and resume.
- [ ] 5.2 Preserve permission, aggregate ownership, manifest/configuration revision, supported-type, referential-safety, and stale-write checks on progress save; verify focused negative tests reject unsafe, malformed, cross-document, unauthorized, and stale payloads without overwriting saved work.
- [ ] 5.3 Keep all completeness, positive-quantity, exact-total, distinct-route, serial, and aggregate live-stock checks at Setujui dan Kirim; verify focused approval tests prove incomplete saved work is resumable but cannot dispatch until the entire locked plan is valid.

## 6. Focused Verification and Human Handoff

- [ ] 6.1 Run only the new and directly affected module/feature tests with targeted `php artisan test` paths or filters, record the exact commands and results, and do not run or plan the full test suite.
- [ ] 6.2 Run `stock-transfers:rehydrate-business-debt --dry-run` against the production-equivalent local database, verify the known snapshot reports zero eligible cross-business V3 receipt effects, and document the reviewed production rollout command without mutating production during implementation.
- [ ] 6.3 Create a concise manual browser checklist for a human developer covering menu visibility, permission denial, one-row-per-product rendering, collapsed debtor totals, zero cells, expanded creditor columns, condition detail in drill-down, unrestricted product-row selection, standard and prefilled zero-quantity drafts, saving/reopening all-zero and mixed drafts, zero-row submission rejection, unrestricted progress save, strict final approval, manual quantity, serial scanning, in-transit projection, receipt rebalancing, direction flip, and provenance drill-down.
- [ ] 6.4 Hand off browser verification exclusively to the human developer; implementation agents MUST NOT launch Chromium, Playwright, Selenium, or any automated browser, and MUST NOT attempt to reproduce UI issues through browser automation.
