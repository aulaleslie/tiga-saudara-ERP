# POS Rebuild Phase 1 - Requirements Brainstorm

## 1) Problem statement (rephrased)
Rebuild POS in two safe steps: first deprecate/remove current POS UI/components without breaking existing sales, then deliver a new POS flow optimized for two roles:
- Floor User creates draft cart and gives short transaction code.
- Cashier retrieves draft by code and completes payment with strict role permissions.

Core constraints to carry forward:
- Backward compatibility by default (existing routes/data/reporting must keep working unless explicitly changed later).
- Multi-location allocation must support serial and non-serial logic.
- Keep `/sales-location-configurations` as the POS stock source configuration page, but move away from `is_pos` toggling.
- Standard sale flow stays scoped by `locations.setting_id = current setting` (existing pattern in `Modules/Sale/Http/Controllers/SaleController.php`).
- POS and standard sales must use separated document numbering/identity.
- POS draft stage must not create `sales` documents; `sales` is created only when POS payment is completed, and stock dispatch/decrement happens immediately in the same completion transaction.

Codebase observations that shape this phase:
- POS location filtering currently depends on `setting_sale_locations.is_pos` in `app/Support/PosLocationResolver.php`.
- POS sales currently write `sales.reference = 'PSL'` in `Modules/Sale/Http/Controllers/PosController.php` (not a robust unique series strategy).
- Standard sale currently uses `Sale` + `SaleService` with setting-scoped context and dispatch locations filtered by current setting (`Modules/Sale/Http/Controllers/SaleController.php`).
- Settings already contain `pos_document_prefix` (`database/migrations/2025_12_01_083359_add_pos_document_prefix_to_settings_table.php`, `Modules/Setting/Http/Requests/StoreSettingsRequest.php`).

## 2) Clarifying questions (expanded, with options + recommendation per question)

### Q1. Draft sale lifecycle and expiry model
Current context: standard `Sale` statuses are approval/dispatch oriented (`DRAFTED`, `APPROVED`, etc.), while POS draft flow needs cashier retrieval semantics.

Options:
1. Reuse `sales.status` with new POS-specific values (`POS_DRAFT`, `POS_LOCKED`, `POS_EXPIRED`, `POS_CANCELLED`, `PAID`).
- Trade-off: fewer tables, but status domain gets mixed and riskier to maintain.
2. Keep `sales` lifecycle unchanged; add POS lifecycle state in `pos_receipts`/new `pos_drafts` state machine.
- Trade-off: cleaner separation, but more joins and migration work.

Recommendation:
- Choose option 2. Keep standard sale lifecycle stable and isolate POS draft lifecycle.

Answer: 2

Suggested default if unanswered:
- `Draft -> Locked -> Paid`, with `Cancelled` and `Expired` terminal states in POS domain, and **no `sales` row until `Paid`**.

### Q2. Draft expiry policy
Options:
1. Fixed expiry (e.g., 24h from last update).
- Trade-off: simple and predictable, but inflexible per tenant.
2. Configurable expiry per setting/business.
- Trade-off: flexible, slightly more settings UX and validation.
3. No automatic expiry.
- Trade-off: operational clutter and stale drafts.

Recommendation:
- Choose option 2.

Answer: 2

Suggested default if unanswered:
- 24h configurable with default 24h; expiry extends on edit.

### Q3. Short transaction code format and collision strategy
Options:
1. 6-digit numeric only.
- Trade-off: very cashier-friendly, but higher collision probability at scale.
2. 8-char alphanumeric (exclude confusing chars), optionally tenant/location prefix.
- Trade-off: lower collision risk, slightly less verbal-friendly.
3. Sequential per day per tenant (e.g., `A1-0241`).
- Trade-off: very human-friendly, but leaks volume and requires strict locking.

Recommendation:
- Choose option 2 with check-digit or collision retry loop.

Answer: Use format `<pos_document_prefix>-YYYY-MM-00001` (5-digit running number per month), and use the same number for draft lookup code and final POS receipt number.

Suggested default if unanswered:
- 8-char uppercase Crockford-like alphabet, unique by active POS setting + open draft scope.

### Q4. Concurrency/locking: one cashier can proceed payment
Options:
1. Pessimistic lock on retrieve (lock starts immediately when code found).
- Trade-off: strongest consistency, can cause abandoned locks.
2. Soft lock on entry to payment screen + heartbeat timeout.
- Trade-off: good UX and practical safety, requires lock refresh mechanism.
3. No lock; optimistic conflict at pay time.
- Trade-off: easiest technically, poor cashier UX/conflicts.

Recommendation:
- Choose option 2.

Answer: 2

Suggested default if unanswered:
- Lock starts when cashier opens payment view; TTL 120s with heartbeat; manager override permitted and audited.

### Q5. Cashier modification scope (pay-only vs modify-capable)
Options:
1. Pay-only: no cart change at all.
- Trade-off: safest control, but more rework loops to floor user.
2. Limited edits: qty + discount within thresholds; no product swap.
- Trade-off: balance between speed and control.
3. Full edits: add/remove/swap items, price override, tax override.
- Trade-off: fastest checkout, highest abuse risk.

Recommendation:
- Choose option 2 for default operational safety.

Answer: we have 2 role for this, cashier and cashier manager. cashier will only able to approve and proceed the payment. cashier manager will also be able to modify all even the price.

Suggested default if unanswered:
- Modify-capable cashier may change qty, line discount, add/remove items; no tax override, no price below minimum price rule.

### Q6. Price and discount rules ownership
Current context: product price rows exist per setting (`product_prices`), POS checkout already handles line calculations.

Options:
1. Floor user can apply discount; cashier can only approve/apply within cap.
- Trade-off: practical for floor flow, requires cap policy.
2. Only cashier can apply discounts.
- Trade-off: tighter control, slower assisted selling.
3. Automatic promo engine only; no manual discount.
- Trade-off: strong governance, may not fit real-world negotiation.

Recommendation:
- Choose option 1 with configurable discount caps by role.

Answer: N/A, we don't have discount for now. but we do have tier pricing based on selected customer

Suggested default if unanswered:
- Floor user max 5%, modify-cashier max 15%, above requires approval code.

### Q7. Drawer session lifecycle and permissions
Current context: POS session + cash movement exists (`PosSessionManager`, `CashMovementComponent`) but permissions are coarse.

Options:
1. Mandatory open drawer before any payment.
- Trade-off: strong audit, may block non-cash-only counters.
2. Required only if cash method used.
- Trade-off: practical, slightly more conditional logic.
3. Optional drawer tracking.
- Trade-off: weak control and reconciliation.

Recommendation:
- Choose option 2.

Answer: drawer idea is for initialize POS session. cashier can go rest or away from pos and cashier has option to pause the session, sign out, sign in, and resume session. session should be scoped per user.

Suggested default if unanswered:
- Drawer required when cash tender present; open/close, paid-in/out, and reconciliation all audited.

### Q8. Payment methods, split tender, partials, void/refund
Current context: POS request already supports multi-payment and cash-overpay checks (`StorePosSaleRequest`).

Options:
1. Keep current scope: cash/card/transfer methods configured in `payment_methods`, allow split, no POS refund/void in phase 1.
- Trade-off: fastest and lowest risk.
2. Add same-shift void and refund now.
- Trade-off: operationally useful, but higher accounting complexity.
3. Add partial payments + deferred settlement in POS phase 1.
- Trade-off: richer features, bigger receivable workflow impact.

Recommendation:
- Choose option 1 for initial rebuild.

Answer: we will support split payments and overpay. void only doable by cashier manager

Suggested default if unanswered:
- Split tender yes; overpay allowed only with cash; refund/void deferred to later milestone.

Atomic completion rule to freeze:
- On successful POS payment submission, create POS payment records, create sales document(s), and dispatch/decrement stock in one DB transaction.

### Q9. Inventory reservation timing for drafts
Options:
1. Reserve stock at draft save.
- Trade-off: protects availability, risk of over-reservation and dead stock.
2. Reserve at lock/checkout start only.
- Trade-off: better stock fluidity, still avoids race at pay moment.
3. Deduct only at paid, no reservation.
- Trade-off: simplest, but high chance of checkout failure.

Recommendation:
- Choose option 2.

Answer: 2

Suggested default if unanswered:
- Soft reservation on lock with TTL tied to lock timeout; **actual dispatch + stock decrement only at payment completion**.

### Q10. POS stock-source definition from `/sales-location-configurations`
Your clarification is explicit: the menu/page at `/sales-location-configurations` is the POS stock source definition.

Options:
1. Use all current assignments in `setting_sale_locations` for the active setting as POS stock source (ordered by `position`), with no `is_pos` filter.
- Trade-off: directly matches current operational UI and keeps a single source of truth.
2. Keep `/sales-location-configurations` for sharing/order only, and create another POS stock-source config.
- Trade-off: more flexibility but duplicates configuration surfaces and increases operator confusion.

Recommendation:
- Choose option 1 (aligned with your clarification).

Answer: 1

Suggested default if unanswered:
- POS allocation and POS search must use all assigned rows from `/sales-location-configurations` for the active setting, ordered by `position`, then `id`.

### Q11. Standard sale boundary while POS uses `/sales-location-configurations`
Given Q10, we still need to freeze how standard (non-POS) sale behaves.

Options:
1. Keep standard sale unchanged: standard sale locations remain `locations.setting_id = current setting`; POS alone uses `/sales-location-configurations`.
- Trade-off: lowest regression risk and fully aligned with existing `SaleController` flow.
2. Make standard sale also use `/sales-location-configurations`.
- Trade-off: unifies location source, but is a major behavior change with dispatch/approval/test impact.

Recommendation:
- Choose option 1.

Answer: 1

Suggested default if unanswered:
- Standard sale remains setting-owned-location scoped; POS uses `/sales-location-configurations` list as stock source.

### Q12. Meaning of “first location” for non-serial priority steps
Current code uses `position` then `id` ordering.

Options:
1. Keep existing order (`position ASC`, `id ASC`).
- Trade-off: deterministic and backward-compatible.
2. Use explicit per-product ranking.
- Trade-off: more control, much more config overhead.

Recommendation:
- Choose option 1.

Answer: 1

Suggested default if unanswered:
- Tie-breaker remains `position ASC`, then `location_id ASC`.

### Q13. Serial number location binding and tax source
Business rule states serial contains location id context.

Options:
1. Strict parse: serial must encode valid location id and that must match serial master record location.
- Trade-off: strongest correctness, may reject legacy serial formats.
2. Prefer serial master record location; parse-from-string only fallback.
- Trade-off: compatible with mixed data quality.

Recommendation:
- Choose option 2 initially, with strict mode warning logs.

Answer: 2

Suggested default if unanswered:
- Authoritative source is `product_serial_numbers.location_id`; encoded location used as validation hint.

### Q14. Tax determination timing and scope
Options:
1. Tax fixed at draft time.
- Trade-off: stable customer quote, may drift from latest rules.
2. Tax revalidated at checkout lock; requires user acknowledgement if changed.
- Trade-off: compliance-safe, can surprise cashier.
3. Tax fixed at payment submit.
- Trade-off: latest compliance, weakest predictability.

Recommendation:
- Choose option 2.

Answer: 1. tax non tax will depends on quantity. not the business. there will be future development to set taxable per business. but it only in purchase and sale document. not in POS. POS will auto calculate tax based on quantity type. and tax alway included

Suggested default if unanswered:
- Recompute at lock and show “tax changed since draft” confirmation.

### Q15. Separate document identity between standard sale and POS sale
Current code already has `sale_prefix_document` and `pos_document_prefix` settings, but POS sale rows currently use fixed `reference = 'PSL'` in `PosController`.

Options:
1. Keep POS identity only in `pos_receipts.receipt_number`; sale reference can be generic.
- Trade-off: minimal change, weaker traceability on `sales.reference`.
2. Generate unique POS sale references using `pos_document_prefix` per setting.
- Trade-off: best audit separation, requires controlled migration path.
3. Use dual identity: POS receipt as customer-facing, POS sale reference internal but unique.
- Trade-off: strongest accounting/audit clarity.

Recommendation:
- Choose option 3.

Answer: Keep existing sales reference behavior per `Sale` entity logic; POS identity remains on POS receipt, and one POS can map to multiple sales documents via `pos_receipt_id`.

Suggested default if unanswered:
- POS receipt number remains primary customer doc; POS sales also get unique setting-scoped POS reference series generated only at payment completion.

### Q16. Backward-compatible deprecation path for old POS UI
Options:
1. Hard cutover by replacing existing page immediately.
- Trade-off: faster, highest rollout risk.
2. Feature-flagged parallel UI behind same routes; old UI hidden but retained.
- Trade-off: safer rollout and rollback.
3. New routes for new POS, then swap links later.
- Trade-off: low risk, but temporary route duplication.

Recommendation:
- Choose option 2.

Answer: 1

Suggested default if unanswered:
- Keep route contracts and add feature flag to switch renderer/components.

### Q17. Audit logging depth for cashier operations
Options:
1. Minimal: paid transaction, amount, cashier.
- Trade-off: cheap, weak forensic capability.
2. Full: draft edits, lock/unlock, overrides, tender details, drawer movements, override actor.
- Trade-off: best compliance, more storage/noise.

Recommendation:
- Choose option 2.

Answer: 2

Suggested default if unanswered:
- Full audit for mutable actions and privileged overrides.

### Q18. Offline behavior expectation
Current stack is Livewire server-driven; no current offline queue architecture.

Options:
1. Online-only in phase 1 with resilience UX (retry, reconnect, lock refresh).
- Trade-off: realistic delivery speed and lower complexity.
2. Offline draft capture + sync queue.
- Trade-off: major architecture addition, conflict resolution required.

Recommendation:
- Choose option 1.

Answer: 1

Suggested default if unanswered:
- Explicit online-first; show connectivity state and safe retry patterns.

## 2A) Interpreted decisions from your answers
Consolidated from all lines that start with `Answer:`:
- POS draft lifecycle remains separate from `sales`, and `sales` documents are created only after successful POS payment.
- Draft expiry should be configurable per setting.
- Transaction code is `<pos_document_prefix>-YYYY-MM-00001` (monthly, 5-digit running number, unique per setting), and this code is used both as draft lookup code and final POS receipt number.
- POS number is allocated at draft creation and must not be reused (including cancelled/expired/void drafts).
- Checkout lock should use soft lock behavior.
- Role model is `cashier` and `cashier manager`; cashier manager can modify everything in POS (items, qty, price, etc.).
- No discount feature now; pricing uses customer tier pricing.
- POS session/drawer behavior is tied to session initialization with pause/resume and user-scoped session continuity.
- Split payment and overpay are required; void path is cashier-manager controlled.
- Inventory reservation is expected at checkout lock timing; dispatch/decrement at payment completion.
- POS stock source is `/sales-location-configurations` assignments (all assigned rows).
- Standard sale remains setting-scoped location flow.
- Allocation follows location order, while non-tax quantity is always prioritized over tax quantity for non-serial products.
- Serial location source should prefer serial master data.
- POS tax behavior is stock-quantity-type based for non-serial products and serial-table tax based for serial products.
- POS document identity is centered on POS receipt, and one POS transaction can produce multiple sales documents by stock-owner setting.
- Breaking changes are acceptable if strictly scoped to POS domain.
- Audit depth should be full.
- Offline mode is out of scope for now.
- `is_pos` should be removed in current delivery (not deprecate-first).
- Completion transaction boundary must be atomic.
- Existing sale reference behavior should remain as-is, and POS should stop forcing `'reference' => 'PSL'` so `Sale` entity reference generation applies.
- POS lifecycle labels are `Ajukan Pembayaran` and `Terbayar`.
- Void is only allowed before payment submit.
- Session enforcement remains same-user + same-setting only; no additional terminal edge-case handling is required for now.
- `/sales-location-configurations` should have no row actions; only order modification.
- `/sales-location-configurations` keeps add/remove assignment via separate non-row controls; row-level actions are removed.
- Monthly sequence overflow handling (`>99999`) is considered out-of-scope for Phase 1 (assumed impossible operationally).

## 2B) Contradiction analysis (answers vs answers, and answers vs current constraints)

### C1. Draft code = final receipt number vs current creation timing
Resolved decision:
- Number is allocated at draft creation and reused as final receipt number.
Codebase implication:
- Current receipt number generation occurs on `PosReceipt::create()` in payment flow.
Why this matters:
- Draft persistence and number allocator must move earlier than final payment.

### C2. Sales reference behavior vs current POS override
Resolved decision:
- Keep existing `Sale` reference generation behavior.
Codebase implication:
- Current POS code still sets `'reference' => 'PSL'` and must be removed to honor `Sale::boot()` generation.
Why this matters:
- Prevents non-unique/generic references across POS-generated `sales` documents.

### C3. `is_pos` removal in current delivery vs active dependencies
Resolved decision:
- Remove `is_pos` now and rely on `/sales-location-configurations` ordering as the POS source.
Codebase implication:
- Resolver, controller queries, configuration controller, and tests depend on `is_pos`.
Why this matters:
- Migration, query refactor, UI cleanup, and test rewrites must be delivered together.

### C4. Lifecycle and void boundary
Resolved decision:
- Lifecycle labels: `Ajukan Pembayaran -> Terbayar`; void allowed only before payment submit.
Codebase implication:
- Draft state model/validation must enforce editability and void boundary before final payment write.
Why this matters:
- Avoids inconsistent behavior between cashier edits and cancellation flow.

## 2C) Re-clarification questions (open-ended, no A/B)
Answers captured:
1. Confirmed: code format uses `<pos_document_prefix>-YYYY-MM-00001` and POS prefix comes from settings.
2. Confirmed: code allocated at draft creation and never reused.
3. Overflow case treated as impossible for current phase.
4. Confirmed: POS should stop forcing `'reference' => 'PSL'` and use existing `Sale` reference generation.
5. Confirmed lifecycle labels: `Ajukan Pembayaran`, `Terbayar`.
6. Confirmed: cashier manager edit scope includes all requested fields.
7. Confirmed: void allowed only before payment submit.
8. Confirmed: non-serial allocation exhausts non-tax first, then tax; serial uses tax id from serial table.
9. Confirmed: keep same-user + same-setting enforcement as-is.
10. Confirmed: no row actions on `/sales-location-configurations`; only order modification.
11. Confirmed: keep add/remove assignment controls as separate non-row actions.

## 3) Proposed new POS UX concept (screens + flow)

### A) Floor User mode
Flow:
1. `Floor Home` (search-first): fast search field, barcode scan input, category chips, recent products.
2. `Draft Cart`: line items with qty/discount controls (per allowed role), customer optional panel, subtotal/tax preview.
3. `Save Draft`: one CTA generates short code + printable mini-slip.
4. `Draft Receipt Card`: shows short code, expiry countdown, and status badge.
5. `Draft List (My Drafts)`: filter by status (`Draft`, `Locked`, `Expired`, `Cancelled`, `Paid`), quick reopen/edit/reprint.

UX notes grounded in current stack:
- Keep Livewire component boundaries similar to existing POS (`search`, `product list`, `checkout`) to reduce rewrite risk.
- Preserve scanner-first keyboard flow from current `SearchProduct` and serial picker patterns.
- Default assumption: online-only; if connection drops, freeze save/lock actions with explicit recovery prompts.

### B) Cashier mode
Flow:
1. `Retrieve by Code` screen with large keypad-friendly input + scanner support.
2. `Draft Validation` screen:
- Stock revalidation
- Price/promo delta checks
- Tax delta checks
- Permission-gated edit actions
3. `Checkout Lock` indicator:
- lock owner, countdown, override option (if allowed)
4. `Payment` screen:
- tender rows, split tender totals, change due for cash
- pay-only cashier sees immutable items
- modify-capable cashier sees constrained edit actions
5. `Completion`:
- success state, receipt print/reprint actions
- audit event confirmation
- atomic commit: create `sales` document + create payments + create dispatch + decrement stock

### C) Drawer session
Flow:
1. `Start Shift` (opening cash).
2. `In-Shift Movements` (paid-in/paid-out + notes/docs).
3. `End Shift` (counted cash, expected cash, discrepancy note).
4. `Supervisor Monitor` (existing style from `SessionMonitor`): active sessions, idle/cash alerts, lock override visibility.

Permissions:
- Cashier opens/closes own drawer.
- Supervisor/admin can view all sessions and perform controlled overrides.
- Every override/action writes audit entry.

### D) Multi-location + serial handling
UI behavior:
- Each cart line shows source allocation summary (single/multi-location chips).
- For serial products:
  - scan serial -> validate availability + location + tax consistency
  - show serial badges and location metadata
- For non-serial products:
  - allocation engine applies priority sequence and exposes a compact "allocation result" panel

Insufficient stock behavior:
- Show deterministic reason: which priority level failed and remaining shortage.
- Offer actions:
  - reduce qty
  - switch/override location (if role allows)
  - save as pending draft

### E) Error and edge-case handling
Explicit states and UX responses:
- Code not found -> prompt re-entry + “recent drafts” helper.
- Code expired -> offer duplicate/recreate draft.
- Already paid -> show receipt and block payment.
- Locked by another cashier -> show lock owner + timeout + override policy.
- Price changed since draft -> show diff and require confirmation (or manager approval per rule).
- Stock not available -> show precise shortage by location.
- Serial mismatch -> hard block and explain expected source.
- Tax mismatch across tenants -> require revalidation and explicit confirmation path.

## 4) Solution approaches grounded in current codebase

### Approach 1: Minimal-risk incremental replacement (feature flag / parallel run)
Architecture shape:
- Keep existing routes (`Modules/Sale/Routes/web.php`) and preserve route names.
- Introduce new Livewire components under `app/Livewire/PosV2/*` while retaining `app/Livewire/Pos/*`.
- Add feature flag at route/controller/view composition layer to switch old/new renderer.
- Keep current persistence endpoint contract (`PosController::store`) initially, then gradually delegate to new services.

Pros:
- Lowest rollout risk and fastest rollback.
- Minimal break risk to navigation/reporting/tests.

Cons:
- Temporary duplicate UI logic.
- Old data-shape assumptions remain longer.

Trade-offs:
- Speed and safety over architectural purity.

### Approach 2: Clean module rewrite behind stable interfaces (adapter layer)
Architecture shape:
- Build a dedicated POS application layer (service classes + DTO/contract boundary) and keep legacy route contracts as adapters.
- Keep external contracts stable (`app.pos.*`, receipt print/history).
- Move allocation, draft lifecycle, locking, and payment orchestration to isolated domain services.
- Existing controllers (`PosController`) become thin adapter/facade.

Pros:
- Strong long-term maintainability.
- Clean place to enforce role/drawer/locking/multi-location invariants.
- Easier tests-first decomposition by domain service.

Cons:
- Higher upfront design effort.
- Requires careful adapter compatibility testing.

Trade-offs:
- More initial complexity for better correctness and future change velocity.

### Approach 3: Event-driven/offline-capable expansion (optional)
Architecture shape:
- Add command/event workflow for draft-save, lock, pay, stock-reserve with persistent event/audit trail.
- Optional client-side offline queue for floor draft capture.

Pros:
- Best offline and audit resilience.
- Good fit for eventual multi-terminal scale.

Cons:
- Current stack is Livewire server-driven; this is a large architectural jump.
- Highest delivery and operational complexity.

Trade-offs:
- Capability and resilience vs timeline and complexity.

## 5) Recommendation
Recommended primary approach: **Approach 2 (clean rewrite behind stable interfaces) with direct POS-scoped cutover**.

Why this fits this repo:
- Existing POS logic is highly concentrated in large classes (`app/Livewire/Pos/Checkout.php`, `Modules/Sale/Http/Controllers/PosController.php`), making adapter-based extraction practical and high-value.
- Route/menu/history coupling is strong (`resources/views/layouts/header.blade.php`, `resources/views/layouts/menu.blade.php`, `Modules/Sale/Http/Livewire/PosTransactions.php`), so even with POS-scoped breaking allowance we should preserve non-POS contracts.
- You require clear domain separation (sale location behavior and document separation), which is easier to enforce with explicit POS domain services than incremental patches inside current monolith flows.

## 6) Open decisions / assumptions (A/B options with impact + suggested default)

### D1. POS stock source scope under `/sales-location-configurations`
- Option A: all assignments for active setting in `setting_sale_locations` (owned + borrowed/shared) are POS stock sources.
- Option B: only a subset of assignments are POS stock sources via new extra marker/config.
- Impact: allocation determinism, admin UX complexity, and backward compatibility.
- Suggested default: Option A (aligned with your clarification).

Answer: A

### D2. Remove `is_pos` physically now vs deprecate first
- Option A: drop column immediately and refactor all references.
- Option B: deprecate in phase 1 (ignored in logic), drop in later migration.
- Impact: migration risk + rollback safety.
- Suggested default: Option B.

Answer: A

### D3. POS document separation enforcement level
- Option A: separate POS receipt only.
- Option B: separate POS receipt + POS sale reference series.
- Impact: accounting traceability and reconciliation clarity.
- Suggested default: Option B.

Answer: 1 POS can have multiple sales documents separated by location owner setting, while `sales.reference` follows existing per-document sale reference logic.

### D4. Lock override authority
- Option A: supervisor/admin only.
- Option B: any modify-capable cashier.
- Impact: security and fraud surface.
- Suggested default: Option A.

Answer: A

### D5. Draft edit rights after code generated
- Option A: floor user only (own drafts).
- Option B: modify-capable cashier can edit after retrieval.
- Impact: operational speed vs accountability.
- Suggested default: Option B with full audit + lock ownership.

Answer: cashier manager can modify everything in POS (items, quantity, price, etc.).

### D6. Reservation release policy
- Option A: release on timeout automatically.
- Option B: release only on explicit cancel/unlock.
- Impact: stock availability vs conflict safety.
- Suggested default: Option A with explicit timeout warning.

Answer: A

### D7. Price-change policy at checkout
- Option A: auto-apply latest prices.
- Option B: freeze draft price unless manager override.
- Impact: margin control vs customer expectation.
- Suggested default: Option A with mandatory “price changed” confirmation log.

Answer: A

### D8. Standard sale + POS cross-document linking
- Option A: keep only `pos_receipt_id` link.
- Option B: add explicit `source_channel`/`source_doc_no` fields for reporting clarity.
- Impact: reporting and downstream integrations.
- Suggested default: Option B if schema change is approved in frozen requirements.

Answer: A

### D9. POS completion transaction boundary
- Option A: atomic transaction (create sales document + persist payment + dispatch/decrement stock + link receipt) in one commit.
- Option B: staged commits with compensating rollback jobs.
- Impact: data consistency, operational recovery complexity, audit reliability.
- Suggested default: Option A (aligned with your requirement).

Answer: A

---
Stop here for Phase 1. Waiting for your answers/decisions before producing Phase 2.
