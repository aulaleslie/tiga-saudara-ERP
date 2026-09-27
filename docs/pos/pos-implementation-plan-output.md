# POS Implementation Plan (Phase-Based)

## 1) Phase Map (Overview Table)

| Phase | Objective | Main Deliverables | Dependencies | Rollout Risk | Exit Criteria |
|---|---|---|---|---|---|
| Phase 1 | Enforce restricted cart actions with granular permission + async approval request/check | New permissions (`clear/remove/reduce`), async approval request/check APIs, supervisor queue, sell-page action-state flow for clear/remove/reduce | Existing POS sell APIs in `Modules/Pos/Routes/web.php`, `Modules/Pos/Http/Controllers/PosSellController.php`, cart mutations in `Modules/Pos/Services/PosCartService.php`, supervisor audit table/service | Medium | Cashier without permission can request/check/consume approval token (TTL 10 min, single-use); direct action still works for authorized cashier |
| Phase 2 | Introduce persistent non-completed POS transaction (save/list/load/edit) | New transaction tables, save-and-new backend, transaction list screen, load-to-cart (empty-cart only), creator+override edit scope, hard block empty transaction | Phase 1 action controls, existing POS menu in `resources/views/layouts/menu.blade.php`, existing sell UI button `#pos-save-draft` in `Modules/Pos/Resources/views/sell.blade.php` | Medium | Draft can be saved/listed/loaded; transaction cannot become empty; no cart merge is possible |
| Phase 3 | Split checkout posting into multiple sales/payments by ownership/source/tax bucket while preserving cashier flow | Split planner, new posting adapter, grouped sales/payments, checkout-to-sales mapping table, backward-compatible finalize response | Existing finalize flow in `Modules/Pos/Services/FinalizePosCheckoutService.php`, current adapter `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, stock resolver `Modules/Pos/Services/ResolvePosStockAllocationsService.php` | High | One checkout can create multiple sales+payments+dispatches; totals reconcile exactly; idempotent replay returns same split |
| Phase 4 | Refine serial handling UX in current POS shell | Scanner-friendly serial modal, qty-area integrated serial controls, improved serial chip layout/alignment, remove browser `prompt()` | Existing serial APIs in `PosSellController`, current UI in `Modules/Pos/Resources/views/sell.blade.php` | Low | Serial add/remove fully in-app modal flow; no `prompt()` path remains; desktop/mobile usability validated |
| Phase 5 | Hardening, cutover, observability, and regression guardrails | Feature-flag rollout gates, reconciliation jobs/reports, performance/index tuning, UAT sign-off pack | All prior phases | Medium | Pilot and full rollout completed with no critical regression, reconciliation clean, UAT checklist passed |

---

## 2) Detailed Plan Per Phase

## Phase 1 - Restricted Cart Actions + Async Approval

### Scope
- Add granular permissions for cart clear, line remove, and qty reduce.
- Keep direct execution for authorized users.
- Implement async request/check approval flow for unauthorized users (cashier side) and supervisor queue approve/reject flow.
- Enforce per-row/per-action approval with TTL-based single-use token (default 10 minutes).
- Add row-level action affordance (`Aksi` concept) in POS cart interaction model.

### Non-Scope
- Persistent draft transaction tables and save/load lifecycle.
- Checkout split posting behavior.
- Serial UX modal redesign (Phase 4).

### Architecture Changes (modules/classes/services)
- Update routes in `Modules/Pos/Routes/web.php`:
  - Add cashier approval request/check/cancel endpoints under existing sell middleware.
  - Add supervisor approval queue list/approve/reject endpoints under `can:pos.supervisor.approval`.
- Extend `Modules/Pos/Http/Controllers/PosSellController.php`:
  - Keep existing mutation endpoints (`DELETE /cart`, `DELETE /cart/lines/{lineId}`, `PATCH /cart/lines/{lineId}`), but accept optional approval token payload/header.
- Add controller(s):
  - `Modules/Pos/Http/Controllers/PosCartApprovalController.php` (request/check/cancel for cashier).
  - `Modules/Pos/Http/Controllers/PosSupervisorApprovalQueueController.php` (queue list + decision for supervisor).
- Add services:
  - `Modules/Pos/Services/PosCartActionAuthorizationService.php` (permission vs approval gating).
  - `Modules/Pos/Services/PosApprovalRequestService.php` (request lifecycle).
  - `Modules/Pos/Services/PosApprovalTokenService.php` (issue/validate/consume single-use token).
- Extend `Modules/Pos/Services/PosCartService.php`:
  - Allow qty decrease only when permission OR valid approval token is present.
  - Apply same gate for line remove and cart clear.

### Data Model + Migration Changes
- Add table `pos_action_approval_requests`:
  - `id`, `setting_id`, `pos_session_id`, `action_type`, `target_type`, `target_id`, `request_payload` (json), `requested_by`, `status`, `decided_by` nullable, `decided_at` nullable, `decision_reason` nullable, timestamps.
  - Indexes: `(setting_id,status,created_at)`, `(requested_by,status)`, `(pos_session_id,status)`, `(action_type,target_type,target_id)`.
- Add table `pos_action_approval_tokens`:
  - `id`, `approval_request_id` (unique), `token_hash`, `expires_at`, `consumed_at` nullable, `consumed_by` nullable, `consumed_context` (json) nullable.
  - Indexes: `(expires_at,consumed_at)`, unique `(token_hash)`.
- Keep `pos_supervisor_approvals` as immutable audit log (`Modules/Pos/Database/Migrations/2026_08_13_000200_create_pos_supervisor_approvals_table.php`), add new action constants in `Modules/Pos/Entities/PosSupervisorApproval.php` for cart clear/remove/reduce entries.

### Permission/Approval Changes
- Add new permissions in `Modules/User/Database/Seeders/PermissionsTableSeeder.php`:
  - `pos.cart.clear`
  - `pos.cart.line.remove`
  - `pos.cart.line.reduce`
  - `pos.approval.requests.view`
- Add these into role management views:
  - `Modules/User/Resources/views/roles/create.blade.php`
  - `Modules/User/Resources/views/roles/edit.blade.php`
- Approver rule (locked): supervisor must have `pos.supervisor.approval` + action-specific permission.

### API/UI Contract Changes
- New cashier endpoints:
  - `POST /pos/sell/approval-requests` (create request for action+target)
  - `GET /pos/sell/approval-requests/{id}` (check status/token readiness)
  - `POST /pos/sell/approval-requests/{id}/cancel`
- New supervisor endpoints:
  - `GET /pos/supervisor/approval-requests`
  - `GET /pos/supervisor/approval-requests/data`
  - `POST /pos/supervisor/approval-requests/{id}/approve`
  - `POST /pos/supervisor/approval-requests/{id}/reject`
- Existing cart mutation endpoints remain, now optionally accept `approval_token`.
- Update `Modules/Pos/Resources/views/sell.blade.php`:
  - Clear button state labels: `Permohonan ...` -> `Cek Persetujuan` -> `Lanjutkan/Batalkan`.
  - Per-row action control for remove/reduce with same state model.

### Test Plan
- Feature tests (new files under `Modules/Pos/Tests/Feature`):
  - `POSCartApprovalAsyncFlowTest` (request/check/approve/consume/single-use/expiry).
  - `POSCartPermissionEnforcementTest` (direct path with permission, request path without permission).
  - `POSCartReduceQtyWithApprovalTest` (decrease blocked unless permission/token).
- Unit tests:
  - `PosApprovalTokenServiceTest` (TTL + hash + consume semantics).
- Regression targets to rerun:
  - `POSCartTotalsDisplayTest.php`
  - `POSSafeDropWorkflowTest.php`
  - `POSSessionCloseWorkflowTest.php`

### Risk / Rollback Notes
- Risk: accidental hard-block on cart operations for cashier.
- Mitigation: feature flag `pos.cart_action_async_approval.enabled` default OFF in first deploy.
- Rollback: toggle OFF to restore permission-only behavior; additive tables remain unused safely.

### Concrete Acceptance Criteria
- Cashier with `pos.cart.line.reduce` can reduce qty directly.
- Cashier without permission can create request and cannot mutate until approved token is consumed.
- Approved token expires after 10 minutes and fails after first use.
- Supervisor queue only shows pending requests for current setting and logs decision to `pos_supervisor_approvals`.

---

## Phase 2 - Save and Reopen Transaction (Persistent Draft)

### Scope
- Implement `Simpan dan Buka Baru` behavior from `Modules/Pos/Resources/views/sell.blade.php`.
- Persist non-completed transactions (header + lines + serial assignments).
- Add POS transaction list screen + load operation.
- Enforce `load only when cart empty` (no merge).
- Enforce edit scope: creator + privileged override.
- Enforce hard-block so transaction cannot become empty.

### Non-Scope
- Multi-document split posting logic.
- Serial modal redesign details (Phase 4).

### Architecture Changes (modules/classes/services)
- Add routes in `Modules/Pos/Routes/web.php` for transaction CRUD-lite:
  - save, list, detail, load, cancel/archive.
- Add controller:
  - `Modules/Pos/Http/Controllers/PosTransactionController.php`.
- Add services:
  - `Modules/Pos/Services/PosTransactionService.php` (save/list/load lifecycle).
  - `Modules/Pos/Services/PosTransactionSnapshotMapper.php` (session cart <-> persistent model).
  - `Modules/Pos/Services/PosTransactionPolicyService.php` (creator/override guard).
- Extend `Modules/Pos/Services/PosCartSessionStore.php`:
  - store `active_transaction_id` in session cart context when loaded from draft.
- Extend `Modules/Pos/Services/PosCartService.php`:
  - before clear/remove/reduce, enforce non-empty policy if `active_transaction_id` is set and action would remove last line.
- Add menu link in `resources/views/layouts/menu.blade.php` (POS dropdown).
- Add view(s):
  - `Modules/Pos/Resources/views/transactions/index.blade.php`
  - `Modules/Pos/Resources/views/transactions/show.blade.php` (optional detail panel)

### Data Model + Migration Changes
- Create `pos_transactions`:
  - `id`, `setting_id`, `code`, `status` (`DRAFT`,`LOADED`,`COMPLETED`,`CANCELLED`), `created_by`, `owner_user_id`, `last_saved_by`, `customer_id` nullable, `source_pos_session_id`, `completed_checkout_id` nullable, `snapshot_totals` json, `metadata` json, timestamps.
  - Unique `(setting_id, code)`.
- Create `pos_transaction_lines`:
  - `id`, `pos_transaction_id`, `line_no`, `product_id`, `product_name_snapshot`, `product_code_snapshot`, `conversion_id` nullable, `qty`, `unit_price`, `tax_id` nullable, `tax_name_snapshot` nullable, `tax_rate_snapshot`, `line_discount_type`, `line_discount_value`, `line_meta` json nullable.
  - Unique `(pos_transaction_id, line_no)`.
- Create `pos_transaction_line_serials`:
  - `id`, `pos_transaction_line_id`, `serial_number`, unique `(pos_transaction_line_id, serial_number)`.
- Add nullable link in checkout:
  - `pos_checkouts.pos_transaction_id` (for traceability from draft to posted checkout).

### Permission/Approval Changes
- Add permissions:
  - `pos.transactions.view`
  - `pos.transactions.save`
  - `pos.transactions.load`
  - `pos.transactions.edit.any`
- Role rule:
  - Creator can edit own non-completed transaction.
  - Non-creator must have `pos.transactions.edit.any`.

### API/UI Contract Changes
- New endpoints:
  - `POST /pos/sell/transactions/save-and-new`
  - `GET /pos/transactions`
  - `GET /pos/transactions/data`
  - `GET /pos/transactions/{transaction}`
  - `POST /pos/transactions/{transaction}/load`
  - `POST /pos/transactions/{transaction}/cancel`
- Behavior:
  - Save-and-new writes draft, clears current session cart, returns draft code and ID.
  - Load returns `409 CART_NOT_EMPTY` if current cart has any line.
  - Remove/clear on loaded transaction returns `422 TRANSACTION_EMPTY_BLOCKED` if action empties transaction.

### Test Plan
- Feature tests:
  - `POSTransactionDraftPersistenceTest`
  - `POSTransactionLoadGuardTest`
  - `POSTransactionAuthorizationTest`
  - `POSTransactionNonEmptyPolicyTest`
- Regression tests to rerun:
  - `POSShellSessionGuardTest.php`
  - `POSNavigationMenuVisibilityTest.php`
  - `POSCheckoutFinalizeIdempotencyTest.php`

### Risk / Rollback Notes
- Risk: draft data drift vs session cart snapshot.
- Mitigation: deterministic mapper and checksum (`snapshot_hash`) before load.
- Rollback: feature flag `pos.transactions.enabled` OFF; existing sell flow remains session-only.

### Concrete Acceptance Criteria
- Clicking `Simpan dan Buka Baru` persists draft and opens clean cart in same cashier flow.
- Authorized user can open list, load one non-completed transaction only when cart empty.
- Unauthorized user cannot edit other user draft.
- Loaded transaction cannot be reduced to empty cart.

---

## Phase 3 - Completion Split Posting (Multi-Sale + Multi-Payment)

### Scope
- Keep one cashier checkout action (`POST /pos/sell/checkout/finalize`).
- Split generated documents by key: `source_setting_id + source_location_id + tax_bucket`.
- Create multiple `sales`, `dispatches`, and `sale_payments` proportionally.
- Keep backward compatibility for finalize payload and `pos_checkouts` legacy fields.
- Apply tax fallback: default tax else latest active tax.

### Non-Scope
- Multi-tender checkout UI.
- Replacing full checkout UX flow.

### Architecture Changes (modules/classes/services)
- Add new posting adapter:
  - `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php`.
- Add split planner service:
  - `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`.
- Add payment allocator service:
  - `Modules/Pos/Services/PosCheckoutPaymentSplitService.php`.
- Update interface `Modules/Pos/Services/Contracts/PosCheckoutPostingAdapter.php` to support grouped payload while preserving legacy keys.
- Update `Modules/Pos/Providers/PosServiceProvider.php` binding to new adapter behind feature flag.
- Update `Modules/Pos/Services/FinalizePosCheckoutService.php`:
  - Persist split summary and include grouped results in response payload.
  - Preserve `sale_id`, `sale_payment_id`, `dispatch_ids` as first-group compatibility fields.
- Extend `Modules/Pos/Services/ResolvePosStockAllocationsService.php` output contract to include explicit `tax_bucket` meta.

### Data Model + Migration Changes
- Create `pos_checkout_sales`:
  - `id`, `pos_checkout_id`, `split_key`, `source_setting_id`, `source_location_id`, `tax_bucket`, `sale_id`, `sale_payment_id`, `dispatch_ids` json, `subtotal`, `tax_total`, `grand_total`, `paid_total`, timestamps.
  - Unique `(pos_checkout_id, split_key)`.
- Add split metadata support to `pos_checkouts`:
  - `split_summary` json nullable (or store in `metadata['split_summary']`).
- Keep existing `sale_id`/`sale_payment_id` columns for backward-compatible reads.

### Permission/Approval Changes
- No new approval model changes.
- Reuse `pos.sell` + existing checkout auth.

### API/UI Contract Changes
- `POST /pos/sell/checkout/finalize` response extension (backward compatible):
  - Existing fields retained.
  - New fields: `sales[]`, `sale_payments[]`, `split_groups[]`.
- Receipt behavior:
  - Existing receipt route still works using first sale reference.
  - Add optional split-aware receipt selector endpoint in later hardening step.

### Test Plan
- Unit tests:
  - `PosCheckoutSplitPlannerServiceTest`
  - `PosCheckoutPaymentSplitServiceTest`
- Feature tests:
  - `POSCheckoutSplitPostingTest` (mixed serial + non-serial, multi-source).
  - `POSCheckoutSplitIdempotencyReplayTest` (repeat finalize returns same split map).
  - `POSTaxFallbackPolicyTest` (default tax then latest active fallback).
- Regression tests to rerun:
  - `POSCheckoutFinalizeIdempotencyTest.php`
  - `POSSerialValidationCheckoutTest.php`
  - `POSTaxBySourceSnapshotTest.php`
  - `POSCriticalPathCrossReferenceTest.php`

### Risk / Rollback Notes
- Risk: posting mismatch and reconciliation gaps across grouped documents.
- Mitigation: per-checkout split mapping table + deterministic ordering + reconciliation query.
- Rollback: feature flag `pos.checkout.split_posting.enabled` OFF returns to `InlinePosCheckoutPostingAdapter`.

### Concrete Acceptance Criteria
- Checkout with lines from multiple source setting/location/tax bucket generates multiple sales.
- Sum of group totals equals checkout grand total exactly (minor-unit-safe rounding).
- Payment splits sum exactly to paid total; idempotent replay returns same split outputs.
- Legacy clients still read `sale_id` and `sale_payment_id` from finalize payload.

---

## Phase 4 - Serial UI Refinement (Scanner-Friendly Modal)

### Scope
- Replace `prompt()` serial input in `Modules/Pos/Resources/views/sell.blade.php` with modal.
- Integrate serial controls tightly with qty area.
- Improve serial chips alignment and remove affordance.
- Keep existing POS shell and current serial APIs.

### Non-Scope
- Backend serial allocation policy changes.
- Checkout split logic changes.

### Architecture Changes (modules/classes/services)
- Update sell view only:
  - `Modules/Pos/Resources/views/sell.blade.php`.
- Add modal state handling in existing page JS block:
  - open/focus/autosubmit on Enter/scanner input.
  - optimistic UI refresh from `POST /serials/append` and `DELETE /serials/{serial}` responses.
- Optional extraction (if maintainability needed):
  - `Modules/Pos/Resources/js/pos-sell-serial-modal.js` and include in Blade.

### Data Model + Migration Changes
- None.

### Permission/Approval Changes
- No new permission.
- Reuse Phase 1 reduce/remove rules when serial line qty is changed.

### API/UI Contract Changes
- Reuse existing endpoints:
  - `GET /pos/sell/serials/search`
  - `POST /pos/sell/cart/lines/{lineId}/serials/append`
  - `DELETE /pos/sell/cart/lines/{lineId}/serials/{serial}`
- UI behavior:
  - `+ Serial` opens modal instead of browser prompt.
  - Modal supports scanner bursts and Enter submit.
  - Chips align consistently; remove button remains accessible on mobile.

### Test Plan
- Feature/API tests remain for serial append/remove path.
- Add browser/UAT script for scanner behavior:
  - modal opens, auto-focus, Enter submits, success updates chips count.
- Regression tests to rerun:
  - `POSSerialIncrementalAssignmentTest.php`
  - `POSSerialValidationCheckoutTest.php`
  - `POSScanResolveEndpointTest.php`

### Risk / Rollback Notes
- Risk: scanner focus regressions causing slower cashier workflow.
- Mitigation: fallback focus on search input after modal close; modal feature flag `pos.serial.modal.enabled`.
- Rollback: disable modal flag to temporarily restore previous click path.

### Concrete Acceptance Criteria
- No `prompt()` usage remains for serial add path.
- Scanner users can add serial with keyboard-only flow.
- Serial chips visually aligned and removable without layout shift.

---

## Phase 5 - Hardening, Rollout, and Operational Guardrails

### Scope
- Stage-by-stage cutover with per-feature flags.
- Add reconciliation checks and observability for approvals, drafts, split posting.
- Add performance indexes after real data profile.
- Complete UAT sign-off.

### Non-Scope
- New domain features outside defined goals.

### Architecture Changes (modules/classes/services)
- Add scheduled tasks/commands:
  - `pos:expire-approval-tokens`
  - `pos:reconcile-checkout-splits`
  - `pos:cleanup-stale-transactions` (if required by policy)
- Extend reporting surface (optional lightweight):
  - add approval queue aging and split reconciliation summaries in POS report area.

### Data Model + Migration Changes
- Add operational indexes after observing query plans:
  - pending approvals by setting/status/time,
  - transaction list by status/updated_at,
  - checkout split map lookup by checkout_id.
- No destructive migration in this phase.

### Permission/Approval Changes
- Finalize role templates and seed defaults for pilot org units.

### API/UI Contract Changes
- Add internal health endpoints/log events (if used) for deployment monitoring.
- Keep external POS API stable.

### Test Plan
- Full regression matrix (see Coverage Map below).
- Load/perf smoke for split finalize and transaction list pagination.
- UAT runbook by phase.

### Risk / Rollback Notes
- Risk: phased features enabled together causing compounded edge cases.
- Mitigation: stagger flag enablement, observe metrics 24h each step.
- Rollback: disable highest-risk flags first (`split_posting`, then `transactions`, then `approval_async`).

### Concrete Acceptance Criteria
- All phase acceptance criteria passed in production-like UAT.
- No unresolved P1 defects.
- Reconciliation reports clean for pilot period.

---

## 3) Cross-Phase Consolidated Artifacts

### 3.1 Permission Matrix (roles/actions/endpoints)

| Role Archetype | Action | Permission(s) | Endpoint(s) | Execution Mode |
|---|---|---|---|---|
| Cashier Basic | Sell and checkout | `pos.access`, `pos.sell` | `GET /pos/sell`, `POST /pos/sell/checkout/finalize` | Direct |
| Cashier Basic | Clear cart (no direct perm) | `pos.access`, `pos.sell` | `POST /pos/sell/approval-requests`, `GET /pos/sell/approval-requests/{id}`, `DELETE /pos/sell/cart` | Request/check/token |
| Cashier Basic | Remove row (no direct perm) | `pos.access`, `pos.sell` | `POST /pos/sell/approval-requests`, `DELETE /pos/sell/cart/lines/{lineId}` | Request/check/token |
| Cashier Basic | Reduce qty (no direct perm) | `pos.access`, `pos.sell` | `POST /pos/sell/approval-requests`, `PATCH /pos/sell/cart/lines/{lineId}` | Request/check/token |
| Cashier Privileged | Clear cart direct | `pos.cart.clear` | `DELETE /pos/sell/cart` | Direct |
| Cashier Privileged | Remove line direct | `pos.cart.line.remove` | `DELETE /pos/sell/cart/lines/{lineId}` | Direct |
| Cashier Privileged | Reduce qty direct | `pos.cart.line.reduce` | `PATCH /pos/sell/cart/lines/{lineId}` | Direct |
| Cashier Privileged | Save draft and open new | `pos.transactions.save` | `POST /pos/sell/transactions/save-and-new` | Direct |
| Cashier Privileged | Load own draft | `pos.transactions.load` | `POST /pos/transactions/{id}/load` | Direct (empty cart guard) |
| Supervisor | View approval queue | `pos.supervisor.approval`, `pos.approval.requests.view` | `GET /pos/supervisor/approval-requests`, `GET /pos/supervisor/approval-requests/data` | Direct |
| Supervisor | Approve clear request | `pos.supervisor.approval`, `pos.cart.clear` | `POST /pos/supervisor/approval-requests/{id}/approve` | Decision issues token |
| Supervisor | Approve remove request | `pos.supervisor.approval`, `pos.cart.line.remove` | `POST /pos/supervisor/approval-requests/{id}/approve` | Decision issues token |
| Supervisor | Approve reduce request | `pos.supervisor.approval`, `pos.cart.line.reduce` | `POST /pos/supervisor/approval-requests/{id}/approve` | Decision issues token |
| Supervisor | Reject request | `pos.supervisor.approval` + action permission | `POST /pos/supervisor/approval-requests/{id}/reject` | Final reject |
| POS Admin/Manager | View all transactions | `pos.transactions.view` | `GET /pos/transactions`, `GET /pos/transactions/data` | Direct |
| POS Admin/Manager | Edit/load any transaction | `pos.transactions.edit.any`, `pos.transactions.load` | `POST /pos/transactions/{id}/load` | Direct |
| POS Admin/Manager | Access POS reports/reconciliation | `pos.reports.access`, `pos.reconciliation.access` | Existing report/reconciliation endpoints | Direct |

### 3.2 Endpoint Contract Table

| Method | Path | Purpose | Request Payload (key fields) | Success Response (key fields) | Error Cases |
|---|---|---|---|---|---|
| POST | `/pos/sell/approval-requests` | Create approval request for clear/remove/reduce | `action_type`, `target_type`, `target_id`, `requested_payload` | `request_id`, `status=PENDING`, `expires_at` | `422 INVALID_TARGET`, `403 FORBIDDEN` |
| GET | `/pos/sell/approval-requests/{id}` | Check approval status/token readiness | none | `status`, `token_ready`, `approval_token` (if approved), `expires_at` | `404 NOT_FOUND`, `410 EXPIRED` |
| POST | `/pos/sell/approval-requests/{id}/cancel` | Cancel own pending request | optional `reason` | `status=CANCELLED` | `409 NOT_PENDING`, `403 FORBIDDEN` |
| GET | `/pos/supervisor/approval-requests` | Queue page | query filters | html page | `403 FORBIDDEN` |
| GET | `/pos/supervisor/approval-requests/data` | Queue list data | `status`, `action_type`, `requested_by` | `data[]`, `pagination` | `403 FORBIDDEN` |
| POST | `/pos/supervisor/approval-requests/{id}/approve` | Approve request and issue token | optional `note` | `status=APPROVED`, `token_ttl_seconds` | `409 ALREADY_DECIDED`, `403 MISSING_PERMISSION` |
| POST | `/pos/supervisor/approval-requests/{id}/reject` | Reject request | `reason` | `status=REJECTED` | `409 ALREADY_DECIDED`, `422 REASON_REQUIRED` |
| DELETE | `/pos/sell/cart` | Clear cart | optional `approval_token` | `cart_snapshot` | `422 APPROVAL_REQUIRED`, `422 TOKEN_INVALID_OR_EXPIRED` |
| DELETE | `/pos/sell/cart/lines/{lineId}` | Remove line | optional `approval_token` | `cart_snapshot` | `422 APPROVAL_REQUIRED`, `422 TOKEN_ALREADY_USED` |
| PATCH | `/pos/sell/cart/lines/{lineId}` | Update line qty | `qty`, optional `approval_token` | `cart_snapshot` | `422 QTY_REDUCE_FORBIDDEN`, `422 APPROVAL_REQUIRED` |
| POST | `/pos/sell/transactions/save-and-new` | Save current cart as draft and reset cart | optional `note` | `transaction_id`, `transaction_code`, `status=DRAFT`, `cart_snapshot_empty` | `422 CART_EMPTY`, `403 MISSING_PERMISSION` |
| GET | `/pos/transactions` | Transaction list page | query status/date/cashier | html page | `403 FORBIDDEN` |
| GET | `/pos/transactions/data` | Transaction list API | pagination/filter | `data[]` | `403 FORBIDDEN` |
| POST | `/pos/transactions/{id}/load` | Load non-completed transaction to session cart | none | `cart_snapshot`, `transaction_context` | `409 CART_NOT_EMPTY`, `403 OUT_OF_SCOPE` |
| POST | `/pos/transactions/{id}/cancel` | Cancel non-completed transaction | `reason` optional | `status=CANCELLED` | `409 ALREADY_COMPLETED` |
| POST | `/pos/sell/checkout/finalize` | Finalize checkout (split posting enabled) | existing + unchanged payment payload | existing fields + `sales[]`, `split_groups[]` | existing `422/409/500` codes |

### 3.3 State Machines

#### A) Approval Flow State Machine (ASYNC)

| State | Event | Next State | Notes |
|---|---|---|---|
| `IDLE` | Cashier submits request | `PENDING` | One active request per action-target-requester recommended |
| `PENDING` | Supervisor approves | `APPROVED_TOKEN_ISSUED` | Token expires in 10 minutes |
| `PENDING` | Supervisor rejects | `REJECTED` | Final for that request |
| `PENDING` | Cashier cancels | `CANCELLED` | Final for that request |
| `APPROVED_TOKEN_ISSUED` | TTL elapsed | `EXPIRED` | Token unusable |
| `APPROVED_TOKEN_ISSUED` | Cashier executes protected action with valid token | `CONSUMED` | Single-use token; action executes atomically |
| `APPROVED_TOKEN_ISSUED` | Cashier cancels pre-use | `CANCELLED` | Optional policy; token invalidated |

#### B) POS Transaction Lifecycle State Machine

| State | Event | Next State | Notes |
|---|---|---|---|
| `SESSION_CART` | Save and open new | `DRAFT` | Persist to `pos_transactions*`; session cart cleared |
| `DRAFT` | Load to cart (cart must be empty) | `LOADED` | `active_transaction_id` set in session context |
| `LOADED` | Save changes | `DRAFT` | Revision update; remains non-completed |
| `LOADED` | Finalize checkout success | `COMPLETED` | Link to `pos_checkouts` and split sales mapping |
| `DRAFT/LOADED` | Cancel by authorized user | `CANCELLED` | Immutable terminal state |
| `LOADED` | Attempt to remove last line | `LOADED` (blocked) | Hard-block policy: transaction cannot become empty |

### 3.4 Final Migration Ordering and Deployment Sequence

| Order | Migration/Change | Deploy Step |
|---|---|---|
| 1 | Create `pos_action_approval_requests` | Deploy code with feature flags OFF, run migration |
| 2 | Create `pos_action_approval_tokens` | Same release window |
| 3 | Add new POS permissions in `PermissionsTableSeeder` and role views | Run seeder after migration |
| 4 | Create `pos_transactions` | Phase 2 migration window |
| 5 | Create `pos_transaction_lines` | Phase 2 migration window |
| 6 | Create `pos_transaction_line_serials` | Phase 2 migration window |
| 7 | Add `pos_transaction_id` to `pos_checkouts` | Before Phase 3 enablement |
| 8 | Create `pos_checkout_sales` | Before split posting feature flag ON |
| 9 | Add optional split summary/indexes | Post-pilot hardening |

Recommended deployment sequence:
1. Deploy migrations + backward-compatible code, keep all new flags OFF.
2. Seed permissions and update role assignments.
3. Enable Phase 1 flag for pilot setting.
4. Enable Phase 2 flag for pilot setting.
5. Enable Phase 3 split flag after reconciliation dry-run passes.
6. Enable Phase 4 UI flag.
7. Run Phase 5 monitoring/hardening tasks and expand rollout.

### 3.5 Regression Coverage Map

| Critical Flow | Existing Coverage (current repo) | New Coverage to Add |
|---|---|---|
| Cart mutation and totals | `Modules/Pos/Tests/Feature/POSCartTotalsDisplayTest.php` | Async approval path tests for clear/remove/reduce |
| Checkout idempotency | `Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php` | Split posting idempotent replay and split mapping consistency |
| Serial assignment/validation | `Modules/Pos/Tests/Feature/POSSerialValidationCheckoutTest.php`, `POSSerialIncrementalAssignmentTest.php` | Modal UX UAT + edge tests for remove serial in loaded transaction |
| Stock allocation behavior | `Modules/Pos/Tests/Feature/POSStockAllocationResolverTest.php` | Split grouping and tax bucket reconciliation tests |
| Permission/menu behavior | `Modules/Pos/Tests/Feature/POSPermissionRoleMappingTest.php`, `POSNavigationMenuVisibilityTest.php` | New permission matrix coverage for transaction + approval queue menus |
| Session workflow approvals | `Modules/Pos/Tests/Feature/POSSafeDropWorkflowTest.php`, `POSSessionCloseWorkflowTest.php` | Ensure new async cart approvals do not regress existing supervisor workflows |

---

## 4) Checkout Split Strategy

### 4.1 Split Algorithm (`source_setting_id + source_location_id + tax_bucket`)
1. Build posting units per cart line.
2. For serial lines, derive source from serial record (`location_id`, owner setting via location); tax source is serial/location tax snapshot.
3. For non-serial lines, use `ResolvePosStockAllocationsService` allocations (already non-tax-first for taxable lines).
4. Determine `tax_bucket` per unit:
- `NON_TAX` if effective tax is null.
- `TAX:{tax_id}` if tax applies.
5. Group units by key: `source_setting_id + source_location_id + tax_bucket`.
6. For each group, create one `Sale`, corresponding `Dispatch/DispatchDetail`, and one `SalePayment` allocation slice.
7. Persist each group mapping to `pos_checkout_sales` for reconciliation and replay.
8. In checkout payload, include grouped details while preserving legacy top-level IDs.

### 4.2 Tax and Stock Deduction Policy
- Serial products:
  - Ownership/source is explicit from assigned serial location.
  - Stock deduction follows serial location and serial tax context.
- Non-serial products:
  - Taxable lines allocate non-PPN stock first, then PPN stock (`ResolvePosStockAllocationsService` behavior retained).
  - Non-tax lines allocate only non-tax stock.
- Tax fallback policy on taxable sales line without resolved tax:
  - First use `taxes.is_default = true`.
  - If none, use latest active tax.
- Source business PKP guard:
  - If source business is non-PKP, effective tax forced to non-tax bucket.

### 4.3 Idempotency and Reconciliation Implications
- Keep one idempotency key per checkout (`pos_checkouts.setting_id + idempotency_key` unique remains authoritative).
- Replay must return exact same split groups/order.
- Store deterministic `split_key` and rounded minor-unit allocations to avoid replay drift.
- Reconciliation checks per checkout:
  - `sum(pos_checkout_sales.grand_total) == pos_checkouts.grand_total`
  - `sum(pos_checkout_sales.paid_total) == pos_checkouts.paid_total`
  - every mapped `sale_id`, `sale_payment_id`, and `dispatch_ids` exists.

---

## 5) Final Delivery Sequence

### 5.1 Phased PR Sequence

| PR | Scope | Key Files/Areas |
|---|---|---|
| PR-1 | Permission scaffolding + feature flags + route placeholders | `Modules/User/Database/Seeders/PermissionsTableSeeder.php`, role views, POS route skeleton |
| PR-2 | Async approval backend (request/check/queue/token consume) | `Modules/Pos/Routes/web.php`, new approval controllers/services, approval migrations |
| PR-3 | Sell UI state machine for clear/remove/reduce (`Permohonan` -> `Cek` -> `Lanjutkan/Batalkan`) | `Modules/Pos/Resources/views/sell.blade.php`, `PosSellController`, `PosCartService` |
| PR-4 | Draft transaction persistence backend + list/load APIs + menu | transaction migrations/entities/services/controllers, `resources/views/layouts/menu.blade.php` |
| PR-5 | `Simpan dan Buka Baru` UX + transaction screens + non-empty hard block | sell view + transaction views + cart service guards |
| PR-6 | Split checkout posting engine + mapping table + backward-compatible payload | `FinalizePosCheckoutService.php`, adapter contract/provider binding, split services |
| PR-7 | Serial modal UX refinement and chip alignment polish | `Modules/Pos/Resources/views/sell.blade.php` |
| PR-8 | Hardening: reconciliation commands, indexes, rollout docs/UAT pack | commands/reports/tests/docs |

### 5.2 Cutover Strategy and Regression Guardrails
- Use per-feature flags and per-setting rollout (pilot first):
  - `pos.cart_action_async_approval.enabled`
  - `pos.transactions.enabled`
  - `pos.checkout.split_posting.enabled`
  - `pos.serial.modal.enabled`
- Enable sequence: Phase 1 -> Phase 2 -> Phase 3 -> Phase 4.
- Guardrails before each enable:
  - feature test suite pass,
  - migration health check pass,
  - permission assignment checklist complete,
  - pilot UAT pass for that phase.
- Rollback sequence (if needed): disable latest enabled flag first, then previous.

### 5.3 UAT Checklist Aligned to Phases

| Phase | UAT Scenario | Expected Result |
|---|---|---|
| 1 | Cashier without clear permission clicks clear | Request/check flow shown; action blocked until approved token consumed |
| 1 | Supervisor approves reduce-qty request | Cashier can execute once within 10 min; second reuse fails |
| 2 | Click `Simpan dan Buka Baru` with populated cart | Draft created, cart reset, transaction appears in list |
| 2 | Load draft while cart not empty | Load blocked with explicit message (no merge) |
| 2 | Try removing last line from loaded transaction | Hard-blocked; transaction remains non-empty |
| 3 | Checkout cart with mixed source/location/tax buckets | Multiple sales/payments created; totals reconcile exactly |
| 3 | Repeat finalize with same idempotency key | Replay response returned; no duplicate sales/payments |
| 4 | Add serial using scanner in modal | Serial appended without prompt; chip visible and aligned |
| 4 | Remove serial chip and re-add | Counts and validations remain correct |
| 5 | Full pilot-day cashier operation | No critical regression in sell, session, and checkout workflows |

---

## Assumptions Requiring Business Confirmation
- Definition of "latest active tax": current `taxes` schema has `is_default` but no explicit `is_active`; plan assumes "active" = latest existing tax row (highest `id`/latest `created_at`) when no default exists.
- Payment split rounding tie-breaker: plan assumes deterministic largest-remainder allocation with stable ordering by `split_key`.
- Draft code format (`POS-TRX-YYYYMMDD-XXXX`) can be finalized by business/ops for audit readability.
