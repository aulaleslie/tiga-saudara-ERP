# Tasks

## 1. Collection storage and authorization

- [ ] 1.1 Add Modules/Pos collection header, method, and allocation migrations/models with receiving session, nullable terminal, immutable receipt context, request hash, unique idempotency identity, and ordinary SalePayment links; verify focused SQLite migration/model tests and production-compatible schema definitions.
- [ ] 1.2 Register `pos.kasBon.collect`, update supported cashier/manager bundles, and protect collection routes with existing POS access conventions; verify focused permission tests reject direct unauthorized requests and leave global payment permissions unchanged.
- [ ] 1.3 Resolve and validate the actor's active-business OPEN receiving session server-side, including nullable terminals; verify focused tests for terminal and terminal-less sessions plus missing, other-actor, other-business, and closing/closed sessions.

## 2. Debt discovery and current bill review

- [ ] 2.1 Implement a paginated active-business Kas Bon candidate query using authoritative debt intent, posted completion, canonical live due, and existing return-aware generated-Sale eligibility; verify focused list tests for previous cashiers/sessions, paid and ordinary POS exclusion, cross-business exclusion, and split-owner inclusion.
- [ ] 2.2 Add search over every displayed text and monetary column with formatted/canonical amount normalization; verify focused search cases for transaction, customer, notes, original total, current total, paid amount, and debt.
- [ ] 2.3 Reuse or extract the current item monetary projection for read-only review with adjustments and a fingerprint of bill, mappings, settlement, and eligibility; verify focused changed-price/quantity, returns, totals, and ambiguous-mapping cases.

## 3. Atomic single-transaction settlement

- [ ] 3.1 Implement collection amount, ordered method, tender/change, and evidence validation using existing checkout rules and canonical amount formatting; verify focused zero/negative/excess allocation, insufficient tender, partial-with-change, transfer-only, and mixed-method cases.
- [ ] 3.2 Implement one atomic receiving-session collection service using deterministic locks and existing ownership/method priority to create ordinary Sale payments and method/allocation audit links; verify focused split-owner allocation, current-bill settlement, and unchanged checkout/stock facts.
- [ ] 3.3 Add durable request-hash-bound idempotency and locked fingerprint/session revalidation; verify focused identical retries, replay after session closure, changed-payload rejection, concurrent/stale settlement, and session-close race behavior.
- [ ] 3.4 Integrate method evidence persistence and rollback cleanup using existing media patterns; verify focused failure cases leave no partial payment, collection audit, cash events, or orphaned copied media.

## 4. Session cash and reconciliation

- [ ] 4.1 Add collection-specific cash tender events and exactly-once collection change outflow within settlement, then refresh expected cash; verify focused Rp100,000 tender/Rp50,000 payment/Rp50,000 change, mixed-method, non-cash-only, rollback, and replay invariants.
- [ ] 4.2 Extend terminal and terminal-less session summaries and reconciliation with separate net cash/non-cash collection totals, preserving new-sale totals; verify focused split-owner no-duplication and previous-origin-session cases.
- [ ] 4.3 Confirm pickup, thresholds, closing expected cash, and variance consume collection-inclusive ledger balance; verify focused pickup and closing integration cases with cash and non-cash collection.
- [ ] 4.4 Preserve collection cash events and recorded receipt facts when Sales invalidates linked Sale payments; verify focused open- and closed-session invalidation cases show reopened canonical debt while cash history stays intact, without implementing a new reversal flow.

## 5. POS modal and printable collection receipt

- [ ] 5.1 Add Bayar Kas Bon button, searchable paginated list, current item/bill review, and single-transaction selection; verify focused UI/component cases and a browser check that the current cart survives opening and cancelling collection.
- [ ] 5.2 Add isolated checkout-style partial/full payment state with existing payment methods, evidence handling, tender/change, formatting, stale-state refresh, and no Kas Bon option; verify focused UI/component cases plus a browser exercise of partial and mixed payment, including a valid terminal-less session.
- [ ] 5.3 Add printable immutable collection receipts and authorized reprinting with existing print-audit conventions; verify focused receipt cases for methods, amount, change, remaining debt, original POS reference, and stable content after later payment or price change.
- [ ] 5.4 Document permission setup, cashier steps, receipt behavior, session attribution, and the separate Sales invalidation/cash-correction boundary in the appropriate project documentation; verify instructions match the delivered UI and permissions.

## 6. Focused integration verification

- [ ] 6.1 Run targeted new test classes and selected adjacent global POS payment, projection, session cash, pickup, and reconciliation tests using `php artisan test` with explicit paths/filters; record commands and results, with no full-suite test run required.
- [ ] 6.2 Complete a focused browser walkthrough from debt search through current-bill review, partial cash with change, mixed payment, receipt, and receiving-session reconciliation; record observed totals and any remaining limitations.
