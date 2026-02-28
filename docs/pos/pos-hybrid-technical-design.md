# POS Hybrid Technical Design (MVP Baseline)

Date: 2026-02-26  
Based on: `docs/pos/pos-requirements-discovery.md` Sections 4-6  
Companion docs:
- `docs/pos/pos-mvp-backlog-tests-first.md`
- `docs/pos/pos-mvp-test-matrix.md`

## 1. Purpose

This document translates the approved POS requirements baseline into a technical implementation design for MVP.

MVP architectural decision:

- POS uses a new cashier/session/cash-control domain.
- POS checkout posts into existing `sales` / `dispatch` / `sale_payments` as transactional source of truth (hybrid model).

This is a rebuild design. Dropped legacy POS schema is not treated as current capability and is not reused blindly.

## 2. Design Goals and Constraints

### 2.1 Goals

1. Immediate cashier checkout (`scan -> review -> pay`) with stock deduction on payment confirmation.
2. Preserve compatibility with existing reporting/accounting flows via shared sales/payment posting.
3. Support configured multi-location routing (including borrowed locations where configured).
4. Enforce POS session, safe drop, and reconciliation controls.
5. Maintain strong auditability and duplicate-submit safety.

### 2.2 Constraints

1. Existing sales flow is approval/dispatch based; POS needs immediate finalization.
2. Rollout scope is all businesses, but enablement must remain controllable per business.
3. POS phase 1 is not offline-first and not API-first.
4. Livewire/Laravel stack is preferred for MVP speed.

## 3. High-Level Architecture

### 3.1 Domain Split (Hybrid)

#### POS Domain (new / rebuilt)

Responsible for:

- terminal configuration and terminal runtime context
- POS sessions and cash events
- cashier UI state / checkout lifecycle
- supervisor approvals (PIN)
- POS-specific audit logs
- POS receipt print/reprint logging
- reconciliation cross-reference records

#### Existing ERP Domains (source of truth for posted transaction)

Responsible for:

- `sales` header + details
- stock deduction posting (`dispatch` + related details)
- `sale_payments`
- downstream existing reports/integrations that already consume sales/payment data

### 3.2 Proposed Module Layout (Laravel / Modules)

Recommended new module:

- `Modules/POS`

Suggested sub-areas:

- `Modules/POS/Http/Controllers` (screen entry, supervisor panels, reports)
- `Modules/POS/Livewire` (cashier screens, modals, session workflow)
- `Modules/POS/Services`
  - `Checkout`
  - `Session`
  - `CashControl`
  - `Approvals`
  - `Printing`
  - `Reconciliation`
- `Modules/POS/Domain` (DTOs, enums, value objects)
- `Modules/POS/Database/Migrations`
- `Modules/POS/Database/Seeders` (permissions)
- `Modules/POS/Tests/Feature`

Integration touchpoints (existing code to call/extract from):

- `app/Support/SalesLocationResolver.php`
- `Modules/Sale` posting and dispatch logic
- `Modules/Sale` payment logic
- `Modules/Setting` payment methods and sale-location configuration
- `Modules/People` customer lookup/default guest customer resolution

## 4. Data Flow Overview

### 4.1 Session Open / Cash Control

1. Cashier enters POS route.
2. POS feature flag and permission checks run for current `setting_id`.
3. Cashier opens session on terminal with opening float.
4. POS stores session + opening cash event.
5. Sell screen becomes available.

### 4.2 Checkout Finalization (MVP)

1. Cashier submits payment-confirm action with `idempotency_key`.
2. POS validates session state and cart integrity.
3. POS resolves stock allocations by allowed configured locations.
4. POS derives tax policy per deducted source owner business.
5. POS writes POS checkout snapshot records (pending/finalizing).
6. POS posts `sale` + details, `dispatch` + deduction details, and `sale_payment` inside one DB transaction.
7. POS updates session cash totals (for cash payment), logs audit events, and records cross-reference IDs.
8. POS commits and returns receipt payload.

### 4.3 Failure Behavior

- Any failure inside finalization transaction rolls back all ERP posting and POS snapshot mutations for that attempt (except pre-transaction request logs, if used).
- Duplicate submission with same `idempotency_key` returns the first successful response payload.
- Printer/drawer failures do not roll back financial posting; they are logged as operational failures.

## 5. Proposed POS Support Schema (Rebuilt MVP)

Notes:

- Names below are proposals and may be adjusted to match project naming conventions.
- Every table should include standard timestamps.
- Business scoping should be explicit (`setting_id`) on operational tables.

### 5.1 `pos_terminals`

Purpose:

- Register POS terminals per business/cashier-station context.

Proposed fields:

- `id`
- `setting_id` (business scope; required)
- `code` (unique per business; e.g., `COUNTER-01`)
- `name`
- `is_active` (bool)
- `printer_driver` (nullable; e.g., `network_thermal`)
- `printer_host` (nullable)
- `printer_port` (nullable)
- `drawer_enabled` (bool default false)
- `drawer_driver` (nullable)
- `metadata` (json nullable)
- `created_by` / `updated_by` (nullable user IDs)
- timestamps

Indexes / constraints:

- unique (`setting_id`, `code`)
- index (`setting_id`, `is_active`)

### 5.2 `pos_terminal_policies` (optional separate table; may be merged into `pos_terminals`)

Purpose:

- Store terminal-level behavior toggles without bloating terminal identity record.

Proposed fields:

- `id`
- `terminal_id`
- `require_session_open` (bool)
- `require_opening_float` (bool)
- `allow_total_only_float_input` (bool)
- `close_variance_approval_threshold` (decimal)
- `cash_threshold` (decimal nullable; falls back to business/store setting)
- `auto_open_drawer_on_session_open` (bool)
- `auto_open_drawer_on_cash_sale` (bool)
- `auto_open_drawer_on_pickup` (bool)
- `auto_open_drawer_on_close` (bool)
- `require_pickup_supervisor_approval` (bool)
- `metadata` (json nullable)
- timestamps

Indexes / constraints:

- unique (`terminal_id`)

### 5.3 `pos_sessions`

Purpose:

- Track cashier sessions and reconciliation lifecycle.

Proposed fields:

- `id`
- `setting_id`
- `terminal_id`
- `cashier_user_id`
- `status` (`OPEN`, `CLOSING`, `CLOSED`, `CANCELLED`)
- `opened_at`
- `closed_at` (nullable)
- `opened_by` (nullable; usually cashier user id)
- `closed_by` (nullable)
- `opening_float_total` (decimal) snapshot
- `expected_cash_total` (decimal) cached summary for fast UI (authoritative value still derivable from events)
- `counted_cash_total` (decimal nullable)
- `variance_total` (decimal nullable)
- `close_notes` (text nullable)
- `close_approved_by` (nullable user id)
- `close_approved_at` (nullable)
- `metadata` (json nullable)
- timestamps

Indexes / constraints:

- index (`setting_id`, `status`)
- index (`terminal_id`, `status`)
- index (`cashier_user_id`, `status`)
- unique partial constraint recommended for one active session per (`terminal_id`, `cashier_user_id`) when status in open/closing (if DB supports), otherwise application + transaction enforcement

### 5.4 `pos_session_cash_events`

Purpose:

- Append-only ledger for cash-affecting session events.

Proposed fields:

- `id`
- `setting_id`
- `pos_session_id`
- `event_type` (`OPEN_FLOAT`, `CASH_SALE_IN`, `CASH_REFUND_OUT`, `SAFE_DROP_OUT`, `MANUAL_ADJUSTMENT_IN`, `MANUAL_ADJUSTMENT_OUT`, `CLOSE_COUNT`)
- `direction` (`IN`, `OUT`, `NEUTRAL`) for query simplicity
- `amount` (decimal; positive absolute amount)
- `denominations` (json nullable)
- `reference_type` (nullable; e.g., `pos_checkout`, `sale`, `manual`)
- `reference_id` (nullable)
- `performed_by` (user id)
- `approved_by` (nullable user id)
- `notes` (nullable text)
- `metadata` (json nullable)
- `occurred_at`
- timestamps

Indexes:

- index (`pos_session_id`, `occurred_at`)
- index (`setting_id`, `event_type`, `occurred_at`)
- index (`reference_type`, `reference_id`)

### 5.5 `pos_checkouts`

Purpose:

- Record POS checkout attempts/results and map them to ERP posting IDs.

Proposed fields:

- `id` (internal POS checkout ID)
- `setting_id`
- `pos_session_id`
- `terminal_id`
- `cashier_user_id`
- `customer_id`
- `status` (`DRAFT`, `FINALIZING`, `POSTED`, `FAILED`, `VOIDED`)
- `idempotency_key` (string, unique within business/session scope)
- `cart_snapshot_hash` (nullable string)
- `currency_code` (nullable if app is single-currency)
- `subtotal`
- `discount_total`
- `tax_total`
- `grand_total`
- `paid_total`
- `change_total`
- `payment_method_code` (phase 1 single method snapshot)
- `payment_reference` (nullable)
- `sale_id` (nullable)
- `sale_payment_id` (nullable)
- `dispatch_ids` (json nullable; supports multi-dispatch split)
- `receipt_number` (nullable)
- `finalized_at` (nullable)
- `failure_code` (nullable)
- `failure_message` (nullable text)
- `metadata` (json nullable)
- timestamps

Indexes / constraints:

- unique (`setting_id`, `idempotency_key`)
- index (`pos_session_id`, `status`)
- index (`sale_id`)
- index (`receipt_number`)
- index (`finalized_at`)

### 5.6 `pos_checkout_lines`

Purpose:

- Preserve cashier-time line snapshots for audit/reconciliation/future decoupling.

Proposed fields:

- `id`
- `pos_checkout_id`
- `line_no`
- `product_id`
- `product_name_snapshot`
- `sku_snapshot` (nullable)
- `barcode_snapshot` (nullable)
- `qty`
- `uom_id` (nullable)
- `unit_price`
- `line_discount_type` (nullable)
- `line_discount_value` (nullable)
- `line_discount_amount`
- `tax_id_snapshot` (nullable)
- `tax_name_snapshot` (nullable)
- `tax_rate_snapshot` (nullable)
- `tax_included` (bool)
- `line_subtotal`
- `line_tax_total`
- `line_total`
- `is_serial_tracked` (bool)
- `bundle_parent_key` (nullable) for bundle grouping snapshot
- `metadata` (json nullable)
- timestamps

Indexes:

- unique (`pos_checkout_id`, `line_no`)
- index (`product_id`)

### 5.7 `pos_checkout_allocations`

Purpose:

- Preserve source-location allocations used for stock deduction and tax-source derivation.

Proposed fields:

- `id`
- `pos_checkout_line_id`
- `source_location_id`
- `source_setting_id` (owner business of source location)
- `allocated_qty`
- `tax_policy_snapshot` (json or normalized fields)
- `dispatch_id` (nullable, if one dispatch per source split is used)
- `dispatch_detail_id` (nullable)
- `metadata` (json nullable)
- timestamps

Indexes:

- index (`pos_checkout_line_id`)
- index (`source_location_id`)
- index (`source_setting_id`)
- index (`dispatch_id`)

### 5.8 `pos_checkout_serials`

Purpose:

- Track serial assignments selected in POS and map them to line/allocation posting.

Proposed fields:

- `id`
- `pos_checkout_line_id`
- `pos_checkout_allocation_id` (nullable until allocation resolved)
- `product_serial_number_id` (nullable if mapping later)
- `serial_number`
- `source_location_id` (nullable snapshot)
- `status` (`SELECTED`, `POSTED`, `FAILED`)
- `metadata` (json nullable)
- timestamps

Indexes:

- index (`pos_checkout_line_id`)
- index (`serial_number`)
- index (`product_serial_number_id`)

### 5.9 `pos_supervisor_approvals`

Purpose:

- Record PIN approval events for price/discount/void/pickup/close approval actions.

Proposed fields:

- `id`
- `setting_id`
- `action_type` (`PRICE_OVERRIDE`, `DISCOUNT_OVERRIDE`, `VOID`, `SAFE_DROP_APPROVAL`, `SESSION_CLOSE_VARIANCE_APPROVAL`)
- `target_type` (`pos_checkout`, `pos_session`, `pos_cash_event`, etc.)
- `target_id`
- `requested_by` (cashier user id)
- `approved_by` (supervisor user id)
- `approval_result` (`APPROVED`, `REJECTED`)
- `reason` (nullable)
- `context_snapshot` (json nullable)
- `occurred_at`
- timestamps

Indexes:

- index (`setting_id`, `action_type`, `occurred_at`)
- index (`target_type`, `target_id`)
- index (`approved_by`, `occurred_at`)

### 5.10 `pos_audit_logs`

Purpose:

- Detailed audit trail for sensitive POS actions and state changes.

Proposed fields:

- `id`
- `setting_id`
- `actor_user_id`
- `action_type`
- `target_type`
- `target_id`
- `before_payload` (json nullable)
- `after_payload` (json nullable)
- `ip_address` (nullable)
- `user_agent` (nullable text)
- `metadata` (json nullable)
- `occurred_at`
- timestamps

Indexes:

- index (`setting_id`, `occurred_at`)
- index (`target_type`, `target_id`)
- index (`actor_user_id`, `occurred_at`)

### 5.11 `pos_receipt_print_logs`

Purpose:

- Track print/reprint attempts separately from financial posting.

Proposed fields:

- `id`
- `setting_id`
- `pos_checkout_id`
- `pos_session_id`
- `terminal_id`
- `print_type` (`INITIAL`, `REPRINT`)
- `request_payload` (json nullable)
- `result_status` (`SUCCESS`, `FAILED`)
- `result_message` (nullable text)
- `performed_by`
- `occurred_at`
- timestamps

Indexes:

- index (`pos_checkout_id`, `occurred_at`)
- index (`terminal_id`, `occurred_at`)

## 6. Service Contracts (Application Layer)

Principle:

- Controllers/Livewire actions should call application services/DTOs.
- Do not call existing controller methods directly.
- Extract reusable sales/dispatch/payment posting logic into services where needed.

### 6.1 `OpenPosSessionService`

#### Request DTO (example)

- `setting_id`
- `terminal_id`
- `cashier_user_id`
- `opening_float_total`
- `opening_denominations` (nullable array)
- `notes` (nullable)

#### Response DTO (example)

- `pos_session_id`
- `status`
- `opened_at`
- `expected_cash_total`
- `drawer_action_requested` (bool)

#### Guarantees

- Validates terminal availability and POS feature flag.
- Enforces active-session uniqueness rules.
- Writes session + opening cash event atomically.

### 6.2 `RecordPosCashEventService`

Use for:

- safe drop / pickup
- manual adjustment
- close count capture

Request DTO (core fields):

- `pos_session_id`
- `event_type`
- `amount`
- `denominations` (nullable)
- `performed_by`
- `approved_by` (nullable)
- `notes` (nullable)
- `reference_type` / `reference_id` (nullable)

Guarantees:

- Appends event ledger row.
- Recomputes/updates cached session expected cash if event impacts cash.
- Logs audit event when action is sensitive.

### 6.3 `ApprovePosSupervisorActionService`

Use for:

- price override
- discount override
- void
- safe drop approval
- session close variance approval

Request DTO:

- `setting_id`
- `action_type`
- `target_type`
- `target_id`
- `requested_by`
- `supervisor_identifier` (PIN input context)
- `pin`
- `reason` (nullable)

Response DTO:

- `approval_id`
- `approved` (bool)
- `approved_by`
- `expires_at` (nullable, if short-lived token is used)
- `approval_token` (nullable)

Guarantees:

- Validates supervisor credentials and permission for action.
- Writes approval log with context snapshot.
- Does not mutate target state directly (caller service performs target mutation).

### 6.4 `ResolvePosStockAllocationsService`

#### Request DTO

- `setting_id`
- `terminal_location_id`
- `cart_lines` (product/qty/serials)
- `allow_borrowed_locations` (bool; derived from configuration)

#### Response DTO

- `allocations[]` by cart line:
  - `source_location_id`
  - `source_setting_id`
  - `allocated_qty`
  - `serial_assignments[]` (if serial tracked)
  - `tax_policy_snapshot`
- `unfulfilled_lines[]` (if any)

#### Guarantees

- Uses configured sales-location priority.
- Applies fallback behavior.
- Returns deterministic allocation order.
- Does not deduct stock (resolution only).

### 6.5 `FinalizePosCheckoutService` (Core MVP Service)

#### Request DTO

- `setting_id`
- `pos_session_id`
- `terminal_id`
- `cashier_user_id`
- `idempotency_key`
- `customer_id` (resolved walk-in/default if omitted)
- `cart_lines` (normalized checkout lines)
- `discounts` (line + bill snapshots)
- `payment`:
  - `method_code` (`cash`, `transfer`, `qris`)
  - `amount_paid`
  - `reference` (required for non-cash)
- `approval_tokens` (optional; price/discount overrides)
- `client_context` (optional metadata: device timestamp, UI version)

#### Response DTO

- `pos_checkout_id`
- `status` (`POSTED`)
- `receipt_number`
- `sale_id`
- `dispatch_ids[]`
- `sale_payment_id`
- `change_total`
- `print_payload`

#### Behavior Guarantees

- Idempotent per (`setting_id`, `idempotency_key`)
- Validates active session and permissions
- Validates pricing/discount approvals and serial completeness
- Resolves allocations and tax snapshots before posting
- Posts to ERP sales/dispatch/payment inside one DB transaction
- Records POS snapshots, audit logs, and cash event (`CASH_SALE_IN` for cash)

#### Failure Contract

- `validation_error` (cart/payment/session/serial problems)
- `conflict` (duplicate finalization already in progress / idempotency conflict)
- `posting_failure` (internal error, rolled back)

Recommended response semantics:

- validation: `422`
- conflict/idempotency in-progress: `409`
- success: `200/201` (team choice)

### 6.6 `ClosePosSessionService`

Request DTO:

- `pos_session_id`
- `cashier_user_id`
- `counted_cash_total`
- `counted_denominations` (nullable)
- `notes` (nullable)
- `variance_approval_token` (nullable)

Response DTO:

- `pos_session_id`
- `status` (`CLOSED`)
- `expected_cash_total`
- `counted_cash_total`
- `variance_total`
- `closed_at`

Guarantees:

- Enforces blind-count behavior at UI/service boundary.
- Requires supervisor approval when variance threshold exceeded.
- Appends close-count event and final reconciliation metadata.

## 7. ERP Integration Design (Hybrid Posting)

### 7.1 Integration Rule

POS should integrate through extracted reusable services, not by invoking controller actions from `Modules/Sale`.

### 7.2 Recommended Extraction Targets (from existing sales flow)

1. `Sale creation posting` (header + details)
2. `Dispatch posting + stock deduction` logic that currently depends on approval flow
3. `Sale payment posting` logic

Benefits:

- POS and existing sales UI can share domain logic.
- Behavior becomes testable without HTTP/controller coupling.
- Reduces divergence between POS and non-POS posting rules.

### 7.3 Immediate Deduction vs Existing Approval Flow

Current sales flow deducts on dispatch approval. POS needs deduction on payment confirm.

Recommended approach:

1. Extract a stock deduction service that can be called from:
   - existing dispatch approval flow
   - POS finalization flow
2. Preserve audit/history semantics expected by existing inventory tracking.
3. Introduce explicit source markers (`origin=POS`) in metadata where possible for traceability.

### 7.4 Cross-Reference Strategy

Store cross-links in `pos_checkouts`:

- `sale_id`
- `sale_payment_id`
- `dispatch_ids[]`
- `receipt_number`

This enables:

- reconciliation reports
- support/debugging
- future migration to a more independent POS ledger if needed

## 8. Idempotency and Concurrency Design

### 8.1 Idempotency Scope

Key: (`setting_id`, `idempotency_key`)

Rules:

1. Client generates a unique key per payment-confirm attempt.
2. Server stores result payload for successful finalization.
3. Duplicate request with same key returns stored result.
4. In-progress duplicate returns conflict (`409`) or waits briefly (team decision).

### 8.2 Concurrency Hotspots

1. Double-click / repeated payment confirm
2. Concurrent cashier actions on same session checkout
3. Stock competition on same product/location
4. Serial number race between terminals
5. Session close while checkout finalization is in progress

### 8.3 Concurrency Controls (Recommended)

1. Row-level locks on stock records / serial rows during finalization (where current schema supports it).
2. Unique idempotency constraint at DB level.
3. Finalization state transition in `pos_checkouts` (`DRAFT -> FINALIZING -> POSTED/FAILED`) inside transaction.
4. Session close should refuse while active checkout finalization exists for that session.

## 9. Tax and Multi-Location Handling Design

### 9.1 Source-of-Truth Rule

- Tax policy follows deducted source location owner business (`source_setting_id`), not terminal session business context alone.

### 9.2 Snapshot Rule

Persist line/allocation snapshots at finalization time:

- tax mode / included-excluded
- tax rate and tax ID/name
- source location and source owner business

Rationale:

- Prevent historical drift if settings change later.

### 9.3 Reporting Implications

1. POS-facing receipt can show aggregate totals only (cashier-friendly).
2. Back-office reporting/reconciliation must preserve split attribution by source location and source owner business.
3. Tax reporting must not recompute from current settings for posted POS transactions.

## 10. Hardware Integration Design (MVP Baseline)

### 10.1 Printer Adapter

Interface behavior:

- `printReceipt(printPayload, terminalConfig): PrintResult`

Requirements:

1. Non-blocking to financial posting (log failure, allow manual reprint).
2. Terminal-configured network thermal support in MVP.
3. Reprint actions logged with actor and timestamp.

### 10.2 Cash Drawer Adapter

Interface behavior:

- `openDrawer(trigger, terminalConfig): DrawerResult`

Triggers:

- session open
- cash sale complete
- safe drop
- session close

Requirements:

1. Optional per terminal policy.
2. Failures logged as operational events only.
3. Must not alter posted totals/session state if command fails.

## 11. Migration / Implementation Sequence (Technical)

1. Add POS module scaffolding and permissions.
2. Add feature flags and terminal configuration.
3. Add session + cash event schema/services.
4. Add supervisor PIN approval + audit infrastructure.
5. Add checkout snapshot + idempotency schema/services.
6. Extract reusable sales/dispatch/payment posting services from existing modules.
7. Implement stock allocation resolver and tax snapshot logic.
8. Implement POS sell/payment screens and finalization flow.
9. Add printer/drawer adapters and logging.
10. Add reporting/reconciliation queries and screens.
11. Harden with test matrix and UAT.

## 12. Technical Open Questions (Implementation-Level, Not Product Scope)

These do not change the approved product requirements, but need engineering decisions during implementation.

1. Should `pos_terminal_policies` be separate or merged into `pos_terminals` for simpler reads?
2. Should `dispatch_ids` remain JSON in `pos_checkouts`, or should a normalized `pos_checkout_dispatch_links` table be used?
3. Can current stock deduction code be safely extracted without changing existing dispatch approval behavior?
4. Which DB engine/version is in production, and can we use partial unique indexes for active session constraints?
5. Should PIN approvals issue short-lived tokens for client-side UX, or should approvals be consumed immediately server-side per action?

## 13. Implementation Guardrails

1. Do not call web controllers from POS services.
2. Do not bypass existing permission middleware/authorization checks; reapply at service boundaries.
3. Do not let hardware adapter failures roll back ERP posting.
4. Do not rely on dropped legacy POS migrations as implementation blueprint.
5. Do not enable POS globally without feature flags, even if final rollout target is all businesses.
