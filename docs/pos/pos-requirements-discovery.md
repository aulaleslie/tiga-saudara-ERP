# POS Rebuild Requirements Discovery (Draft)

## Purpose

This document is a requirements-gathering working draft for building a new POS from the ground up.

It is based on a codebase review of:

- `Modules/Sale`
- `Modules/Purchase`
- `Modules/Setting` (sale-location configuration)
- related shared components (`app/Livewire`, `app/Support`)

Status: `Draft / Discovery`

---

## 1) Current System Baseline (Code Study Summary)

### 1.1 Multi-business / tenant model

- The app is multi-business and commonly scopes data by `session('setting_id')`.
- Purchase and sale pages are protected by `auth` + `role.setting` middleware.
- Core purchase/sale records are stored with `setting_id`.

Implication for new POS:

- POS must explicitly define whether it is tenant-scoped only, or can operate across configured/borrowed sale locations.

### 1.2 Sale Location Configuration (POS-oriented location sharing)

What exists now:

- Each `Location` auto-creates a default sale assignment to its owner business in `setting_sale_locations`.
- A business can configure "active POS sale locations" via `sales-location-configurations` UI.
- A business can borrow a location from another business if that location is not currently borrowed by a third business.
- Borrowed locations can be returned to the owner business.
- Location order/priority is stored in `setting_sale_locations.position`.
- Ordered location IDs are cached by `SalesLocationResolver`.

Observed behavior:

- UI labels distinguish owned vs borrowed locations.
- Owned locations are always present and cannot be removed from configuration.
- Reordering is manual and persisted.

Important constraint in current non-POS sales:

- Standard sales dispatch does **not** use borrowed sale locations.
- Standard sales dispatch only accepts locations where `locations.setting_id == current setting`.
- Regression tests explicitly enforce rejection of borrowed locations in dispatch UI, dispatch submit, and serial AJAX validation.

Implication for new POS:

- We must decide whether the new POS:
  - uses `setting_sale_locations` (including borrowed locations), or
  - continues "owned locations only", or
  - supports both modes by business setting.

### 1.3 Sales module (current flow = order + dispatch + approval)

Current sales flow is not a cashier-first POS flow:

1. Create sale (draft) via Livewire product search/cart.
2. Send for approval.
3. Approve sale.
4. Dispatch goods (separate form).
5. Approve dispatch (stock deducted here).
6. Record payments (separate flow).

Notable behaviors:

- Sale creation uses idempotency token protection.
- Customer selection auto-syncs payment term and due date.
- PKP businesses enforce tax selection on every cart line.
- Sale totals are calculated from cart subtotal, shipping, and global discount.
- Sale details are aggregated by product + tax + bundle combination (`SaleCartAggregator`).
- Stock is validated at create time, but stock deduction happens on dispatch approval.
- Serial-number dispatch validates:
  - serial existence
  - active status
  - not already used / pending dispatch
  - tax compatibility (PPN vs non-PPN)
  - location validity (currently owned-location scope only)

Sales statuses in code include:

- `DRAFTED`
- `WAITING_APPROVAL`
- `APPROVED`
- `REJECTED`
- `DISPATCHED PARTIALLY`
- `DISPATCHED`
- `RETURNED`
- `RETURNED PARTIALLY`

Implication for new POS:

- Decide whether POS should:
  - create `Sale` directly and auto-complete dispatch/payment in one atomic flow, or
  - use a separate POS schema and post summarized results into sales tables, or
  - run a hybrid mode (POS immediate + standard sales order workflow).

### 1.4 Sales payments (separate settlement flow)

Current capabilities:

- Payment methods from `payment_methods`.
- Attachments supported.
- Partial payments supported.
- Customer credit application supported (via sales return credit).
- Payment updates sale `paid_amount`, `due_amount`, and `payment_status`.

Implication for new POS:

- Need clear decision on whether POS supports:
  - immediate full payment only,
  - partial payment,
  - split tender,
  - customer credit usage,
  - post-paid/invoice mode.

### 1.5 Purchase module (current flow = PO + receiving + approval)

Current purchase flow:

1. Create purchase (draft) via Livewire product cart.
2. Send for approval.
3. Approve purchase.
4. Receive goods (separate receiving form).
5. Approve receiving (stock added here).
6. Record purchase payments.

Notable behaviors:

- Purchase create uses idempotency token protection.
- Supplier selection auto-syncs payment term and due date.
- Duplicate purchase flow exists (prefill from an earlier purchase).
- PKP tax enforcement per cart line.
- Tax inclusion resolver used when duplicating purchases.
- Tags supported.
- Receiving creates `ReceivedNote` with `PENDING` status first.
- Stock increment and serial-number creation happen only on receiving approval.
- Over-receiving is checked at approval time against already approved receivings.
- Serial numbers can be reused only when prior status is `RETURNED`.

Purchase statuses in code include:

- `DRAFTED`
- `WAITING_APPROVAL`
- `APPROVED`
- `REJECTED`
- `RECEIVED PARTIALLY`
- `RECEIVED`
- `RETURNED`
- `RETURNED PARTIALLY`

Implication for new POS:

- POS receiving/stock synchronization rules must be defined if POS sells from locations supplied by this purchase flow.

### 1.6 Purchase receiving location behavior (important for stock correctness)

Current receiving UI:

- Receiving form uses `LocationSearchDropdown`.
- That dropdown fetches locations scoped to current business ownership (`locations.setting_id == session('setting_id')`) unless custom options are injected.

Observed server-side validation gap:

- `storeReceive()` validates `location_id` exists, but does not explicitly enforce current-setting ownership in controller validation.
- In practice, UI dropdown scopes the choices, but backend hardening may be desirable.

Implication for new POS:

- If POS introduces cross-business sale-location usage, receiving and stock movement rules must be consistent and explicit server-side.

### 1.7 Permissions and menu structure (current user expectations)

Current navigation exposes separate areas for:

- Sales
- Purchases
- Purchase receivings
- Sales/Purchase returns
- Sale location configuration (under settings)

Sale location configuration permissions:

- `saleLocations.access`
- `saleLocations.edit`

Implication for new POS:

- We should define a dedicated POS permission set (cashier, shift lead, supervisor, manager, admin).

### 1.8 Existing/legacy POS traces

- Repo contains POS-related migrations and `public/js/pos-printer.js`.
- Some POS migrations appear later dropped/cleanup-related.
- No active POS module is clearly exposed in current route/menu flow.
- Dropped POS schema/migrations are **legacy reference only** for rebuild ideas and must not be treated as current system capability.

Implication for new POS:

- We should treat this as a fresh product design, but review legacy POS assumptions before schema design (optional follow-up).

---

## 2) Key Design Decisions You Need to Make (High Impact)

Please answer these first because they shape architecture:

1. Should the new POS use existing `sales` + `dispatch` tables/flow, or use a new POS transaction model and sync into sales later?
2. Should POS stock deduction happen immediately at checkout, or require approval?
3. Should POS be allowed to sell from borrowed sale locations (`setting_sale_locations`)?
4. Is POS intended for cash-and-carry only, or also invoice/tempo/partial payment?
5. Do you need offline capability (continue selling without internet/server connection)?
6. Will POS support serial-number products in phase 1?
7. Do you need multi-terminal concurrent cashiers on the same location/session?
8. Do you need shift opening/closing and cash reconciliation in phase 1?

### Accepted Defaults (Recommended Baseline, February 26, 2026)

These defaults were accepted as the current planning baseline and can be refined during final requirements sign-off.

1. `Data model strategy:` `Hybrid` (POS immediate flow, but post into existing `sales/dispatch/sale_payments` as source of truth for MVP)
2. `Stock deduction timing:` `On payment confirm` (POS flow), not supervisor approval
3. `Borrowed location policy:` `Allowed` via configured sale locations (`setting_sale_locations`)
4. `Payment scope:` `Cash-and-carry first` (`Full paid` default in MVP; invoice/tempo/partial later if needed)
5. `Offline capability:` `No offline` in MVP
6. `Serial-number products in phase 1:` `Yes`
7. `Multi-terminal concurrent cashiers:` `Yes`
8. `Shift opening/closing and cash reconciliation in phase 1:` `Yes`

---

## 3) Requirements Interview Questionnaire (Default Answers Accepted / Pending Specifics)

This section is pre-filled with accepted recommended defaults on **February 26, 2026**.

Items still marked `TBD` require business-specific values (pilot store, go-live date, success metrics, compliance details).

### 3.1 Business Goal and Scope

- `Primary goal of new POS:` speed + fewer cashier mistakes + stock/tax consistency across locations
- `Who will use it:` cashier, supervisor, owner/manager
- `Target stores/businesses in phase 1:` all businesses (using existing setting switching / multi-business context)
- `Go-live target:` `N/A` (not date-driven; rollout when requirements/implementation are ready)
- `Success metric after launch:` unit test scenarios passed (engineering validation gate; operational metrics may be added later)

### 3.2 POS Transaction Type

- `POS transaction model:` `Immediate sale`
- `Default payment behavior:` `Full paid` (MVP default)
- `Need quote/hold order from POS:` No (MVP)
- `Need cancel/void after payment:` Yes (with supervisor approval policy)
- `Need refund from POS screen:` No (phase 1; use existing return flow outside POS)

### 3.3 Customer Handling

- `Customer required for every sale:` No (support walk-in/default customer)
- `Walk-in customer support:` Yes
- `Customer search fields:` name / phone
- `Membership/loyalty needed:` No (phase 1)
- `Customer credit usage at POS:` No (phase 1; keep in back-office flow first)

### 3.4 Products, Pricing, Discount, Promotions

- `Product types in POS:` standard / serial-number / bundle
- `Serial number handling at checkout:` scan before pay
- `Pricing source:` customer-specific/tier + manual override (controlled)
- `Manual price override allowed:` Yes (supervisor approval)
- `Discount types needed:` line % / line fixed / bill % / bill fixed
- `Promo rules needed in phase 1:` `None`

### 3.5 Tax and Compliance (PKP / PPN)

- `Will POS be used by PKP businesses:` Both
- `Tax mode:` mixed by item
- `Can cashier change tax per line:` No
- `Need tax invoice number in POS flow:` No (phase 1)
- `Receipt/invoice compliance requirements:` receipt number (`receipt no`) should be handled in business configuration (per business/setting)

### 3.6 Inventory and Location Rules (Critical)

- `POS stock source:` configured sale locations (borrowed allowed)
- `Default POS location selection:` sales-location configuration priority (terminal has no fixed location binding)
- `Can one transaction pull stock from multiple locations:` Yes
- `If stock unavailable at default location:` allow fallback to next configured location
- `Should backend auto-separate sales by deducted source location:` Yes
- `Tax source for POS sale:` deducted location owner business (`location -> setting -> is_pkp`)
- `Can POS session active location be ignored for stock/tax posting (terminal-only identity):` Yes
- `Reserve stock before payment:` No
- `When stock is deducted:` on payment confirm
- `Negative stock allowed:` No
- `Broken/damaged stock handling in POS:` handled elsewhere

### 3.7 Payment Methods and Settlement

- `Payment methods in phase 1:` cash / transfer / QRIS
- `Split payment support:` No (phase 1)
- `Payment reference capture needed:` Yes
- `Overpayment handling:` change cash only
- `Cash drawer integration:` No (phase 1 hardware integration deferred)
- `End-of-shift cash reconciliation:` Yes

### 3.7A POS Session & Cash Drawer Control (Operational Control)

- `Must cashier open POS session before first sale:` Yes
- `Opening float required:` Yes
- `Opening float input mode:` both
- `Open drawer automatically when session opens:` Yes (when hardware available; may be deferred if hardware integration is not in MVP)
- `Session open authorization:` configurable by terminal
- `Print/log opening slip:` Yes (at minimum log; print when hardware available)
- `One active session per cashier per terminal:` Yes
- `Terminal assignment required before opening session:` Yes
- `Session close mode:` configurable by role (blind cashier count; expected visible to supervisor/manager)
- `Session close approval required:` Yes (supervisor for variance above threshold)

### 3.7B Cash Pickup / Safe Drop Monitoring (Cash Risk Control)

- `Need cash pickup (safe drop) workflow:` Yes
- `Cash exposure threshold per cashier/session:` `10,000,000` initial default (configurable per store)
- `Threshold behavior:` warn cashier + notify supervisor + monitor dashboard
- `Who can initiate pickup:` cashier / supervisor
- `Who can approve pickup:` supervisor
- `Pickup input mode:` both
- `Open drawer during pickup action:` Yes (when hardware available)
- `Print/log pickup slip:` Yes
- `Need real-time pickup monitor dashboard (who needs pickup now):` Yes
- `Need escalation alerts (e.g., threshold exceeded for N minutes):` Yes

### 3.8 Returns / Exchanges (POS Side)

- `Need POS returns in phase 1:` No
- `Need exchange flow in phase 1:` No
- `Return source rules:` `N/A` (phase 1; define in phase 2)
- `Refund method rules:` `N/A` (phase 1; define in phase 2)
- `Serial-number return validation required:` Yes (when returns are enabled)

### 3.9 Approvals and Controls

- `Need supervisor approval for:` discount override / price override / void / return
- `Approval mode:` PIN
- `Audit trail strictness:` detailed per action

### 3.10 Hardware and Store Ops

- `Devices:` desktop / touchscreen PC
- `Barcode scanner:` Yes
- `Receipt printer:` thermal network
- `Cash drawer:` No (phase 1)
- `Customer display:` No (phase 1)
- `Label printer:` No (phase 1)
- `Scale integration:` No (phase 1)

### 3.11 UX / Workflow Expectations

- `Max acceptable checkout steps:` 3
- `Keyboard-first operation required:` Yes
- `Touch-first operation required:` Yes
- `Must support product search by:` barcode / SKU / name
- `Need offline queue/retry UI:` No
- `Need dark mode:` No (phase 1)
- `Language(s):` Bahasa Indonesia (phase 1); English optional later

### 3.12 Reporting and Back Office Needs

- `POS reports needed in phase 1:` daily sales / cashier summary / payment method summary / item sales / voids
- `Real-time dashboard needed:` No (general dashboard in phase 1)
- `Need real-time cashier cash exposure monitor:` Yes
- `Need safe drop / pickup monitoring report:` Yes
- `Need integration to existing sales reports:` Yes
- `Need accounting journal posting from POS:` No (phase 1; later phase)

### 3.13 Technical / Implementation Constraints

- `Must reuse existing Laravel app auth/roles:` Yes
- `Preferred UI stack for POS:` Livewire
- `Need API-first POS architecture:` No
- `Offline-first PWA requirement:` No
- `Terminal/session concept required:` Yes (open/close session, cashier shift)
- `Data migration from old POS (if any):` No (rebuild from scratch)

### 3.14 Rollout Strategy

- `Pilot store/business first:` no single pilot; all businesses (continue using existing setting switching)
- `Parallel run with current process:` Yes
- `Training needed:` Yes
- `Fallback plan if POS fails:` current sales screen and/or manual invoice process

---

## 4) Finalized POS Requirements Baseline (Approved Discovery Output)

This section converts the discovery answers into a concrete requirements baseline for implementation planning.

Notes:

- `Legacy POS migrations` that were intentionally dropped are `reference ideas only` and are not evidence of current capability.
- `Source of truth for MVP transactions` uses a `hybrid model`: POS checkout flow is immediate, while posting continues to existing sales/dispatch/payment domains.
- `Rollout scope` is `all businesses` using existing setting switching.

### 4.1 Scope and Operating Model (Baseline)

- POS is a `speed-first cashier flow` for immediate checkout (`scan/search -> review -> pay`).
- POS is `not` an offline-first system in phase 1.
- POS is `not` API-first in phase 1; it will reuse the existing Laravel app stack (`Livewire`).
- POS phase 1 supports `all businesses` (multi-business via setting switching), not a single pilot tenant.
- POS phase 1 runs in `parallel` with the current process as fallback.
- POS requires `terminal/session` control and `cash shift` management.
- POS phase 1 excludes:
  - exchange workflow
  - POS returns workflow (use existing sales return module)
  - loyalty program
  - promo engine
  - split payment
  - offline queue/retry UI

### 4.2 Functional Requirements (FR)

#### FR-1 Transaction Processing (Immediate Sale)

- POS shall allow cashier to create a sale transaction from a barcode scan/search interface.
- POS shall support product search by `barcode`, `SKU`, and `name`.
- POS shall support `walk-in customer` transactions using a default/guest customer flow.
- POS shall support `standard`, `serial-number`, and `bundle` products in phase 1.
- POS shall require payment completion before finalizing a POS sale in phase 1 (`full paid` default policy).
- POS shall deduct stock `on payment confirmation` (not on cart add and not on later supervisor dispatch approval).
- POS shall prevent duplicate payment confirmation / duplicate transaction submission for the same checkout attempt.
- POS shall produce a receipt after successful transaction completion.

#### FR-2 Inventory and Location Routing

- POS shall source stock from configured sales locations for the active business, including borrowed locations where configuration permits.
- POS terminal shall represent cashier station identity only (code/name/policy), without fixed stock location binding.
- POS backend shall auto-route stock by configured location priority and current availability.
- POS shall allow a single checkout to deduct stock from multiple source locations when required by availability.
- POS shall attempt fallback to the next configured allowed location when stock is unavailable at the preferred location.
- POS shall prevent negative stock.
- POS shall not reserve stock before payment in phase 1.
- POS shall validate serial-number availability and ownership before payment confirmation.
- POS shall require serial scanning/selection before payment confirmation for serial-tracked products.
- POS shall handle broken/damaged stock via non-POS workflows (inventory/warehouse process), not cashier POS flow.

#### FR-3 Pricing, Discounts, and Tax

- POS shall calculate totals in real time, including quantity changes, discounts, and tax.
- POS pricing shall support customer-tier pricing / customer-specific pricing where existing pricing rules apply.
- POS shall support manual price override only through supervisor approval (PIN-based approval flow).
- POS shall support line-level and bill-level discounts.
- POS shall not include a promo engine in phase 1.
- POS tax handling shall support mixed behavior by item/source context when required by source-location business tax policy.
- POS shall not allow cashier to freely change tax per line in phase 1.
- POS shall determine tax policy (`PKP / non-PKP`) based on the deducted source location owner business setting.
- POS cashier flow shall not require tax invoice number entry in phase 1 unless later configured by policy.

#### FR-4 Payments, Receipt, and Settlement

- POS shall support phase 1 payment methods: `cash`, `transfer`, and `QRIS` (configurable per business).
- POS shall capture payment reference for non-cash payments.
- POS shall not support split payment in phase 1.
- POS shall not support customer credit application in POS phase 1.
- POS shall support cash overpayment change calculation for cash payments.
- POS shall disallow creating residual overpayment balances in phase 1 (no store credit from overpay in cashier flow).
- POS receipt numbering/format requirements shall be controlled by business configuration.
- POS shall support receipt printing to network thermal printer in phase 1.

#### FR-5 POS Session and Cash Control

- POS shall require a cashier to open a POS session before first sale.
- POS session open shall require terminal assignment.
- POS shall enforce one active session per cashier per terminal.
- POS shall require opening float at session open.
- POS shall support opening float input as:
  - total amount
  - denomination breakdown (preferred)
  - total-only fallback if configured
- POS shall support cash pickup / safe drop workflow in phase 1.
- POS shall calculate expected cash-in-drawer per session using:
  - opening float
  - cash sales
  - cash void/refund impacts (when applicable)
  - safe drops / pickups
  - permissioned manual adjustments
- POS shall support configurable per-store cash threshold monitoring.
- POS shall warn cashier and notify supervisor when threshold is exceeded.
- POS shall support session close with cash count and variance capture.
- POS shall support blind count for cashier and expected-value visibility for supervisor/manager.
- POS shall require supervisor approval for session close variance above configured threshold.
- POS shall support cash drawer open actions for supported hardware during session open / cash sale / pickup / close.

#### FR-6 Approvals, Roles, and Audit

- POS shall use existing application authentication and role/permission infrastructure.
- POS shall require supervisor approval (PIN) for:
  - discount override (beyond cashier allowance)
  - price override
  - void
  - return (when enabled in later phases)
- POS shall record detailed audit logs for cashier and supervisor actions.
- POS audit records shall include actor, action, target transaction/session, timestamp, and before/after values for sensitive changes where applicable.
- POS shall separate cashier UX from supervisor control actions to reduce accidental misuse.

#### FR-7 Reporting and Back-Office Integration

- POS shall expose transaction outcomes for:
  - daily sales summary
  - cashier summary
  - payment method summary
  - item sales
  - void summary
- POS shall provide safe drop / pickup monitoring data and cashier cash exposure monitoring data.
- POS shall integrate POS transaction outcomes into existing sales reporting where feasible via shared source-of-truth posting.
- POS shall defer accounting journal auto-posting to a later phase.
- POS shall support reconciliation between POS session totals and posted sales/payment records.

#### FR-8 Returns, Voids, and Corrections (Phase Boundaries)

- POS phase 1 shall support `void` control (permissioned) for in-flow correction handling.
- POS phase 1 shall not implement full return workflow in cashier POS UI; returns remain in existing Sales Return module.
- POS phase 1 shall not implement exchange workflow in cashier POS UI.
- POS shall define clear fallback procedure to current sales screen and/or manual invoice process when POS is unavailable.

### 4.3 Non-Functional Requirements (NFR)

#### NFR-1 Reliability and Atomicity

- POS checkout finalization shall commit or fail as a single business operation across sale posting, stock deduction posting, and payment posting.
- POS shall use idempotency protection for checkout finalization requests to prevent duplicate posting from double-submit/retry.
- POS shall log failed posting attempts with sufficient detail for support diagnosis.

#### NFR-2 Performance and Usability

- POS cashier flow shall target `3-step checkout` for standard happy-path transactions.
- POS UI shall be usable with both keyboard-first and touch-first operation.
- Barcode scanning and product lookup shall prioritize low-latency response suitable for front-counter operation.
- POS shall surface routing/tax outcomes as compact status indicators rather than forcing cashier decisions by default.

#### NFR-3 Security and Control

- Sensitive actions shall require authenticated and permissioned users.
- Supervisor approvals shall use PIN-based confirmation in phase 1.
- POS shall record detailed audit logs for sensitive cashier/supervisor actions.

#### NFR-4 Configurability and Multi-Business Support

- POS behavior shall respect current setting switching and business-scoped configuration.
- Receipt number rules shall be business-configurable.
- Payment methods, tax behavior (within allowed policy), and cash thresholds shall be business/store configurable.
- Feature enablement should support business-level toggling even though rollout scope is all businesses.

#### NFR-5 Testability and Release Gates

- Unit test scenarios shall pass as a release gate (owner-defined success metric).
- Critical checkout, stock-routing, payment-posting, session close, and approval flows shall have automated test coverage before production enablement.

### 4.4 Role and Permission Baseline (Phase 1)

#### Cashier

- Open/close session
- Sell and receive payment
- Print/reprint receipt (if allowed)
- Initiate safe drop request / pickup flow
- Request supervisor override
- View only own session cash details (blind close until role policy allows more)

#### Supervisor

- Approve price/discount/void via PIN
- Approve safe drop / pickup
- Monitor active sessions and threshold breaches
- Review variances and approve session close above threshold
- View exception queue and audit logs (operational scope)

#### Owner/Manager

- View monitoring dashboards and summaries
- Review overrides/voids/variances
- Configure thresholds/policies (subject to admin role split)

#### Admin / Configuration Role

- Configure business-level POS settings, payment methods, receipt numbering, terminal policies, and feature flags

### 4.5 Core Workflow Definitions (Textual)

#### WF-1 Session Open

1. Cashier logs in and selects terminal.
2. Cashier enters opening float (total and optional denominations).
3. System validates no conflicting active session for cashier/terminal.
4. System opens POS session, records opening state, and triggers drawer open if supported.
5. Session becomes active and cashier may transact.

#### WF-2 Standard POS Sale (Immediate, Full Paid)

1. Cashier scans/searches products and builds cart.
2. System validates stock availability and serials (when required) across configured locations.
3. System computes totals, discounts, and tax using source-location policy.
4. Cashier proceeds to payment and selects one payment method (`cash/transfer/QRIS`).
5. System validates payment completeness and required reference fields.
6. On payment confirmation, system posts transaction using hybrid flow:
   - sale record
   - dispatch/stock deduction records (including multi-location split as needed)
   - payment record
7. System marks transaction complete and prints receipt.

#### WF-3 Safe Drop / Cash Pickup

1. Threshold alert appears (or cashier initiates pickup manually).
2. Cashier enters pickup amount (and optional denomination breakdown).
3. Supervisor approves via PIN.
4. System records pickup event and updates expected session cash.
5. System prints/logs pickup slip and opens drawer if supported.

#### WF-4 Session Close / Reconciliation

1. Cashier starts close session.
2. System shows close workflow and captures counted cash (blind count for cashier).
3. System computes variance against expected cash.
4. If variance exceeds threshold, supervisor approval is required.
5. System closes session and records reconciliation result.

#### WF-5 Exception / Fallback

- If POS checkout fails before final confirmation, transaction remains unposted and cashier retries.
- If POS is unavailable, store uses current sales screen and/or manual invoice process per existing fallback.

### 4.6 Open Items Intentionally Deferred (Later Phases)

- POS returns UI
- POS exchange UI
- Split tender / split payment
- Customer credit usage in cashier POS
- Loyalty / membership
- Promo engine
- Offline queue and sync
- Accounting journal auto-posting
- Customer display / label printer / scale integration

---

## 5) Phase Plan (MVP / Phase 2 / Phase 3)

### 5.1 Phase 1 (MVP) - Immediate Checkout + Cash Control Baseline

Goals:

- Deliver a production-usable cashier POS flow with immediate stock deduction and payment posting.
- Preserve existing sales/reporting continuity through hybrid posting.
- Control cash risk through session open/close and safe drop monitoring.

In scope:

- Terminal/session open-close
- Opening float + close reconciliation
- Cash threshold monitoring + safe drop/pickup
- Sell screen (barcode/SKU/name)
- Standard/serial/bundle products
- Immediate sale (`full paid`)
- Payment methods: cash / transfer / QRIS
- Non-cash reference capture
- Receipt printing (network thermal)
- Multi-location auto-routing with fallback and tax-by-source-location policy
- Supervisor PIN approvals (price/discount/void)
- Audit logs (detailed)
- Phase 1 reports: daily sales, cashier summary, payment mix, item sales, voids
- Parallel run support and fallback procedure

Out of scope:

- POS returns/exchanges UI
- Split payment
- Customer credit in POS
- Loyalty/promo engine
- Offline mode
- Accounting auto-journal posting
- Advanced hardware integrations (drawer optional by store hardware, no customer display/scale/label printer)

Exit criteria:

- Unit test scenarios pass for critical flows
- End-to-end UAT on representative businesses/locations/serial+non-serial cases
- Reconciliation between POS sessions and posted sales/payments validated

### 5.2 Phase 2 - Customer and Exception Handling Expansion

Candidate scope:

- POS returns flow (integrated with current Sales Return policy)
- Exchange workflow
- Split payment / tender combinations
- Customer credit application in POS
- Expanded exception queues and supervisor tooling
- Reprint/search receipt improvements
- Optional general real-time dashboards

### 5.3 Phase 3 - Optimization and Deep Integrations

Candidate scope:

- Loyalty / membership integration
- Promo engine
- Accounting journal auto-posting
- Additional hardware integrations (cash drawer standardized support, customer display, label printer, scale)
- Offline queue/sync (only if operationally justified)
- API-first extraction (if future channel expansion requires it)

### 5.4 Rollout Strategy (All Businesses, Controlled Enablement)

- Rollout target is `all businesses` (no single pilot tenant).
- Despite all-business scope, implementation shall include `per-business feature enablement` for controlled activation and rollback.
- Use parallel run during stabilization period.
- Keep current sales screen/manual invoice fallback available throughout rollout.
- Training is required before enabling cashier teams.

---

## 6) Hybrid Technical Design Baseline + Risk Register + Migration Plan

### 6.1 Architecture Decision (Approved)

Decision:

- Use a `hybrid POS model`:
  - POS provides a new immediate-checkout UI and session/cash-control workflows.
  - Existing sales/dispatch/payment domains remain the MVP transaction posting source of truth.

Rationale:

- Reuses proven business logic and existing reporting integrations where possible.
- Reduces accounting/reporting rework for MVP.
- Allows POS-specific UX and cash control to be built without replacing the whole sales domain immediately.

Non-goal (phase 1):

- Building a fully separate POS transactional ledger with parallel reconciliation to sales.

### 6.2 Posting Model (Hybrid MVP)

#### Source-of-Truth Posting Targets

- `sales` (commercial transaction header)
- related sale detail records (cart lines)
- `dispatch` / dispatch detail records for stock deduction (including multi-location splits)
- `sale_payments` for payment settlement

#### POS-Specific Domain (New/Rebuilt)

POS-specific tables/services should be rebuilt for operational control and UI state, not for replacing sales truth in phase 1:

- terminals / terminal policy (business-scoped)
- POS sessions
- session cash events (opening float, safe drop, manual adjustment, close)
- POS audit trail / approval logs
- POS receipt print log / reprint log (optional if not already covered)
- idempotency keys / checkout request ledger (recommended)

Note:

- Old dropped POS schema names/structures may inspire field design, but they are not adopted blindly.

### 6.3 Transaction Finalization Flow (MVP)

On `payment confirm`, POS application service performs:

1. Validate active POS session + terminal policy.
2. Validate cart, customer (walk-in/default allowed), pricing/discount permissions, tax policy.
3. Resolve source locations using configured sales-location priority and availability.
4. Validate serial assignments (before payment confirm).
5. Create/post sale and line items.
6. Create/post dispatch and dispatch detail(s) to deduct stock immediately.
7. Create/post payment (`full paid` only in phase 1).
8. Record POS receipt metadata / print request.
9. Update POS session expected cash (for cash payments) and audit logs.
10. Commit transaction and return receipt payload.

Implementation requirements:

- Use DB transaction boundaries for the posting sequence.
- Use idempotency token keyed to checkout attempt to prevent duplicate finalization.
- If multi-location stock deduction logic requires multiple dispatch detail records, keep sale/payment result tied to one POS checkout ID for traceability.

### 6.4 Multi-Location and Tax Policy Handling (Approved Baseline)

- POS terminal/session location is an operational context, not the authoritative tax source.
- Stock is auto-routed across configured allowed locations (including borrowed when configured).
- A single checkout may split deduction across multiple locations.
- Tax policy follows the deducted source location owner business setting.
- Posting layer must preserve source-location attribution for each deducted line/serial so reporting and tax reconciliation remain auditable.

Technical caution:

- Mixed tax outcomes inside a single cashier checkout require explicit line-level tax snapshots at posting time to avoid later configuration drift affecting history.

### 6.5 Permission and Approval Technical Notes (MVP)

- Supervisor approval uses PIN-based confirmation bound to authenticated supervisor identity.
- Approval events must be logged as separate records (who approved, what action, target transaction/session, timestamp).
- Cashier UI should never silently mutate price/discount beyond policy; all overrides must produce audit events.

### 6.6 Reporting and Reconciliation Design (MVP)

- Reporting should prefer existing sales/payment reporting sources where hybrid posting already lands data.
- POS-specific reports (cash exposure, safe drop monitor, session reconciliation) should read from POS session/cash event data.
- Provide cross-reference identifiers between:
  - POS checkout ID
  - sale ID
  - dispatch ID(s)
  - payment ID
  - POS session ID

### 6.7 Risk Register (Initial)

#### High Risk

- `Multi-location auto-routing + tax-by-source split` may cause posting/reporting inconsistencies if line attribution is incomplete.
- `Immediate deduction on payment confirm` may conflict with current sale/dispatch assumptions if existing services are tightly coupled to approval-based flow.
- `All-business rollout` increases blast radius if feature flags and rollback controls are weak.

#### Medium Risk

- `Hardware variability` (network thermal printers, drawers) may differ by store and slow support.
- `Supervisor PIN UX` may create cashier bottlenecks if approval flows are too frequent.
- `Receipt numbering config` differences per business may create edge cases across setting switching.

#### Low/Operational Risk

- Training gaps causing misuse of safe drop/session close procedures.
- Parallel run causing duplicate sales if store SOP is unclear.

Risk mitigations:

- Implement per-business feature flags even with all-business target.
- Add automated tests for multi-location/tax split scenarios (serial and non-serial).
- Add idempotency and duplicate-submit protections before live rollout.
- Validate printer and drawer compatibility per store before activation.

### 6.8 Migration and Rollout Plan (Rebuild Path)

#### Migration Principles

- Rebuild POS support schema/services from scratch.
- Do not restore dropped legacy POS schema as-is.
- Reuse existing sales/dispatch/payment domains as transaction truth for MVP.

#### Build Sequence

1. Introduce POS feature flags and business/terminal configuration.
2. Build POS session/cash-control backend and audit model.
3. Build cashier sell/pay flow UI with hybrid posting service.
4. Implement supervisor PIN approval flow and monitors.
5. Add POS-specific reports (session/cash exposure/safe drop).
6. Add printer integration and store-specific hardware configuration handling.
7. Execute UAT + reconciliation test pack across representative businesses.
8. Enable production flags per business in controlled sequence (even if final scope is all businesses).

#### Data Migration / Backfill

- No legacy POS transactional data migration is required for MVP (rebuild approach).
- Existing business settings remain the configuration source; add only required POS settings/extensions.

#### Rollback Plan

- Disable POS feature flag per business/store/terminal.
- Continue operations using current sales screen and/or manual invoice process.
- Preserve posted sales/payment data; rollback only affects POS UI/session entry path, not historical records.

#### Release Validation Checklist

- Unit test scenarios passed (owner-defined success metric)
- Critical integration tests passed (checkout -> stock deduction -> payment -> receipt)
- Session open/close and safe drop reconciliation scenarios passed
- Multi-location + tax-source test matrix passed
- Receipt numbering config verified per business

### 6.9 Companion Implementation Artifacts (This Iteration)

The following documents convert this discovery baseline into executable engineering work:

1. `docs/pos/pos-mvp-backlog-tests-first.md`
   - MVP epics/TODOs with tests-first execution, dependencies, and acceptance criteria
2. `docs/pos/pos-hybrid-technical-design.md`
   - hybrid architecture design, rebuilt POS support schema draft, and service contracts
3. `docs/pos/pos-mvp-test-matrix.md`
   - critical-path automated/UAT scenarios for checkout, routing, tax, session, approvals, and reconciliation

---

## 7) UI Reference and Screen Planning Appendix (Planning Only)

This section is not a visual design spec yet. It defines UI reference direction and required screens so implementation stays focused on speed and operational control.

### 7.1 UI Reference Philosophy

- `Cashier UI` should follow speed-first POS patterns (large tap targets, minimal branching, keyboard + barcode first).
- `Supervisor UI` should follow control-first operations patterns (alerts, queues, quick approvals, exception visibility).
- `Owner UI` should follow decision-first monitoring patterns (threshold alerts, summaries, trends, drill-downs).

Avoid in phase 1:

- Overloaded cashier screens with accounting/tax decisions
- Deep nested flows for common payment actions
- Manual stock source selection for every line by default

### 7.2 Core POS Screen Set (Phase 1)

#### A. Cashier Screens (Speed-first)

1. `POS Session Open`
   - Terminal selection (if applicable)
   - Opening float input (total and/or denominations)
   - Open drawer action
   - Session confirmation (cashier, time, terminal)
2. `POS Sell Screen`
   - Product scan/search
   - Cart lines
   - Qty/discount quick actions
   - Running totals
   - Payment shortcut
   - Stock-routing status badge (system-managed, low cognitive load)
3. `Payment Screen`
   - Full payment quick buttons
   - Split tender flow (phase 2)
   - Non-cash reference capture
   - Change calculation (cash)
   - Final confirmation
4. `Receipt / Post-Sale Actions`
   - Print receipt
   - Reprint
   - Email/WA later (optional phase 2)
   - New sale
5. `Cash Pickup / Safe Drop`
   - Suggested pickup based on threshold
   - Amount input
   - Approver PIN (if required)
   - Drawer open
   - Slip/log confirmation
6. `Session Close / Cash Count`
   - Expected vs counted (role-based visibility)
   - Variance capture
   - Notes
   - Supervisor approval (if required)

#### B. Supervisor Screens (Control-first)

1. `Live Session Monitor`
   - Active sessions by cashier / terminal / store
   - Current sales total
   - Expected cash in drawer
   - Threshold status
2. `Pickup Alert Monitor`
   - Cashiers exceeding cash threshold
   - Time since threshold exceeded
   - Quick action to open pickup workflow
3. `Exception Queue`
   - Voids
   - Overrides
   - Failed payments
   - Sync conflicts (future/offline)
4. `Approvals Panel`
   - Discount overrides
   - Price overrides
   - Returns/no-receipt returns (phase 2)
   - Session close approvals

#### C. Owner / Manager Monitoring (Decision-first)

1. `Cash Exposure Dashboard`
   - By cashier / session / terminal / store
   - Threshold breaches
   - Pickup compliance
2. `Sales Performance Dashboard`
   - Net sales
   - Payment method mix
   - Transaction count / average basket
3. `Location-Split Monitoring`
   - Auto-separated sales by deducted source location
   - Tax split (PKP / non-PKP) by source location owner
4. `Audit & Risk Summary`
   - Voids
   - Overrides
   - Refunds
   - Variances

### 7.3 UX Workflow Principles (Phase 1)

- `3-step checkout target`: scan/search -> review -> pay
- `Keyboard + scanner first`: all critical actions shortcut-capable
- `Touch-ready`: large controls for common actions
- `System decides routing`: backend auto-routes stock by configured priority and availability
- `Cashier sees outcomes, not complexity`: routing/tax logic surfaced as confirmation badges/labels
- `Fail safely`: prevent oversell, duplicate submit, ambiguous payment completion

### 7.4 UI Planning Questions (Design Alignment)

- `Which store profile is primary for UI layout:` high-volume counter / service desk / mixed
- `Do cashiers use keyboard heavily, touch heavily, or both equally?`
- `How visible should stock-routing details be on cashier screen:` hidden / compact badge / expandable detail
- `Should safe-drop alerts appear to cashier, supervisor, or both?`
- `Should cashier be blocked when threshold exceeded until pickup completes?`

---

## 8) Owner-Assumed Strategic Answers Addendum (Session/Cash/UI)

This addendum captures the owner-default stance for the newly expanded planning questions.

Status update (February 26, 2026): these defaults are accepted as the current planning baseline and will be finalized in the next requirements iteration.

### 8.1 POS Session Open / Drawer / Float

- `Must cashier open POS session before first sale:` Yes
- `Opening float required:` Yes
- `Opening float input mode:` Both (total + denomination breakdown; total-only fallback allowed if speed issue)
- `Open drawer automatically when session opens:` Yes (when hardware supports it)
- `Session open authorization:` Cashier login/PIN; supervisor approval optional by terminal policy
- `Print/log opening slip:` Yes
- `One active session per cashier per terminal:` Yes
- `Terminal assignment required before opening session:` Yes
- `Session close mode:` Blind count for cashier; expected values visible to supervisor/manager
- `Session close approval required:` Yes for variance above threshold

### 8.2 Cash Pickup / Safe Drop Monitoring

- `Need cash pickup (safe drop) workflow:` Yes
- `Cash exposure threshold per cashier/session:` Yes (example starting threshold: `10,000,000`, configurable per store)
- `Threshold behavior:` Warn cashier + notify supervisor + show in monitor dashboard
- `Who can initiate pickup:` Cashier or supervisor
- `Who can approve pickup:` Supervisor
- `Pickup input mode:` Total + optional denomination breakdown
- `Open drawer during pickup action:` Yes
- `Print/log pickup slip:` Yes
- `Need real-time pickup monitor dashboard:` Yes
- `Need escalation alerts:` Yes (if threshold remains exceeded beyond configured time)

### 8.3 Multi-Location Auto-Split and Tax Source (Owner Hot Take)

- POS should use configured sale locations from `sales-location-configurations`, including borrowed locations where configured.
- Backend should auto-route stock by configured priority and availability.
- A single checkout may deduct from multiple source locations.
- Backend should auto-separate/post sales records based on actual deducted source locations.
- Tax policy (`PKP / non-PKP`) should follow the deducted location owner business setting (`location -> setting -> is_pkp`).
- POS session active location should be treated as terminal/session context, not the authoritative tax/stock source for posting.

### 8.4 UI Direction (Owner Priority)

- Cashier UI optimizes speed and mistake prevention.
- Supervisor UI optimizes monitoring and intervention.
- Owner UI optimizes cash exposure and operational risk visibility.
- Cashier should not manually resolve tax or location-routing logic except through permissioned override flows.
