## 1. Source ownership resolution

- [x] 1.1 Add a shared POS non-stock source resolver that returns the first enabled ordered `setting_sale_locations` entry and its owning setting for the terminal setting.
- [x] 1.2 Replace standalone non-stock parent split-planning ownership with the shared configured POS source resolver.
- [x] 1.3 Replace the stockless bundle-component first-non-PKP rule with the shared configured POS source resolver and preserve source-owner tax resolution.
- [x] 1.4 Return an actionable checkout validation failure when non-stock content has no enabled configured POS sales-location source.

## 2. Immediate audit dispatch posting

- [x] 2.1 Extend grouped checkout posting context so non-stock parent/component audit quantities retain product, bundle, tax, and configured source-location context.
- [x] 2.2 Persist approved audit-only DispatchDetails for non-stock POS content during inline checkout posting, while retaining immediate approved Dispatch and `DISPATCHED` Sale status.
- [x] 2.3 Ensure non-stock audit details bypass all stock validation, ProductStock/Product changes, serial handling/history, and inventory transaction writes using server-side persisted product classification.
- [x] 2.4 Preserve existing stock-managed parent/component allocation, serial, dispatch, deduction, and transaction behavior without regression.

## 3. Split mapping and downstream integrity

- [x] 3.1 Preserve one POS checkout's complete owner-group mappings, payment allocations, and exact monetary reconciliation when non-stock and stock content resolve to different owners.
- [x] 3.2 Verify receipt and reprint reconstruction retain complete bundle composition and do not expose split-owner internals or duplicate customer-facing amounts.
- [x] 3.3 Verify POS Return snapshot and eligibility paths can use approved non-stock audit DispatchDetails and all `pos_checkout_sales` mappings.
- [x] 3.4 Ensure idempotent replay does not duplicate non-stock Sales, approved Dispatches, or audit DispatchDetails.

## 4. Automated verification

- [x] 4.1 Add focused resolver/planner tests proving standalone non-stock parents use the first configured POS location's owner, not the terminal/current business.
- [x] 4.2 Add ordering-change and first-source-PKP tests proving the first configured source always owns future non-stock content and tax follows that source policy.
- [x] 4.3 Add service-only POS checkout coverage for immediate `DISPATCHED` status, approved audit DispatchDetail, no inventory effects, and idempotent replay.
- [x] 4.4 Add mixed stock/service checkout coverage for owner-split Sales, approved dispatch details, payment reconciliation, and unchanged stock allocation/deduction.
- [x] 4.5 Add non-stock service-parent plus stock-managed RAM bundle tests covering component quantity multiplication, no double counting, distinct-owner and same-owner split groups, audit detail creation, and one-time RAM stock mutation.
- [x] 4.6 Run focused POS, Sale Dispatch, receipt, and POS Return tests, then run the project SQLite test command required by repository guidance.
