# POS Rebuild Phase 2 - Requirements Frozen

## Freeze statement
This document freezes scope and behavior for the POS rebuild. Any change after this point must be handled as an explicit change request.

## Scope
### In-scope
- Replace current POS flow with a draft-first flow: Floor User creates draft, Cashier retrieves by code and completes payment.
- Keep POS and standard sales documents separated.
- Create `sales` and dispatch stock only when POS payment is successfully submitted.
- Keep `/sales-location-configurations` as POS stock source configuration.
- Remove `is_pos` usage from POS stock source logic in this delivery.
- Preserve standard sales flow behavior outside POS.
- Support split payment and cash overpay in POS.
- Support cashier-pay-only and cashier-manager (full modify) behavior.
- Support one POS transaction splitting into multiple `sales` documents by stock owner setting.
- Enforce multi-location allocation priority and serial rules.
- Enforce testable locking, reservation, and audit requirements.

### Out-of-scope
- Offline-first POS and sync queue.
- Post-payment void in POS (handled by sales return flow).
- Promotions/discount engine redesign (manual discount remains out of scope; existing tier pricing remains).
- Sequence overflow handling beyond operational assumption (monthly `>99999` treated as operationally impossible in this phase).
- Changes to standard sales location scoping and non-POS sales workflow.

## Personas and permissions
| Persona | Purpose | Required capabilities |
|---|---|---|
| Floor User | Build and submit draft cart | Create draft, edit own draft before submit, submit to payment queue (`Ajukan Pembayaran`), view/reprint draft code |
| Cashier (pay-only) | Process payment only | Retrieve draft by code, acquire checkout lock, submit payment, print receipt, no cart/customer/price/allocation edits |
| Cashier Manager (modify) | Process and correct checkout | All cashier capabilities plus full draft modification before payment submit (items, qty, price, customer, payment rows, allocation, serials), pre-submit void |
| Admin | Configuration and governance | Manage POS location assignments/order, role permissions, prefixes, monitoring, audit access, override controls |

## User stories and use cases
1. As a Floor User, I can save a draft and hand a short POS code to a customer.
2. As a Cashier, I can retrieve a draft by code and pay it without editing line items.
3. As a Cashier Manager, I can modify any draft field before payment submit.
4. As a Cashier, I can only process a draft if no other cashier holds the lock.
5. As a Manager/Admin, I can override lock with audit trace.
6. As a Cashier, I can split payment across multiple methods and give change only for cash overpay.
7. As the system, I create one POS receipt and one-or-more sales documents partitioned by stock owner setting.
8. As the system, I dispatch/decrement stock atomically with payment completion.
9. As Admin, I can configure POS stock source ordering in `/sales-location-configurations` without row-level POS toggles.
10. As Auditor, I can trace draft lifecycle, overrides, payment, and stock effects by POS code.

## Functional requirements
### Draft sale lifecycle and expiry
FR-001. The system shall persist a POS draft document when Floor User saves cart.
FR-002. User-facing lifecycle states are `Ajukan Pembayaran` and `Terbayar`.
FR-003. System terminal states `Dibatalkan` and `Kedaluwarsa` shall be supported for audit and validation.
FR-004. Draft expiry duration shall be configurable per setting; expiry countdown shall be based on draft last-update timestamp.
FR-005. Expired drafts shall be non-payable and return a deterministic error.
FR-006. No `sales` row, no `sale_payments` row, and no stock decrement/dispatch shall occur at draft save time.
FR-007. Void action shall be allowed only before payment submit and only by Cashier Manager/Admin.

### Code generation and lookup
FR-008. POS code format shall be `<pos_document_prefix>-YYYY-MM-00001`.
FR-009. `pos_document_prefix` shall come from setting configuration.
FR-010. POS code shall be generated at draft creation time.
FR-011. POS code used by Floor User lookup shall be the same final POS receipt number after payment.
FR-012. POS code sequence shall reset monthly and increment per setting.
FR-013. Generated code shall never be reused, including cancelled/expired/void drafts.
FR-014. Lookup by code shall return exactly one draft/receipt document in setting scope.

### Locking and concurrency guarantees
FR-015. Checkout lock shall be acquired when cashier starts payment processing on a draft in `Ajukan Pembayaran`.
FR-016. Only one active lock shall exist per draft at any time.
FR-017. Lock scope shall be per draft + setting.
FR-018. Lock TTL and reservation TTL shall be 15 minutes.
FR-019. Lock heartbeat shall refresh lock expiry while checkout remains active.
FR-020. Lock shall release on successful payment, explicit unlock/cancel, timeout, or void.
FR-021. Lock override shall require Cashier Manager/Admin authority and generate audit entry.

### Allowed modifications (role-based)
FR-022. Cashier pay-only shall not modify items, quantities, prices, customer, allocation, serials, or payment rows.
FR-023. Cashier Manager shall be allowed to modify all draft fields before payment submit.
FR-024. Floor User shall be allowed to modify own draft before checkout lock/payment processing.
FR-025. After payment submit starts, draft mutation endpoints/actions shall reject edits.
FR-026. All manager edits shall record before/after diff in audit log.

### Drawer session lifecycle
FR-027. POS operations shall require active POS session (`active`, not `paused`).
FR-028. Session states shall be `active`, `paused`, `closed`.
FR-029. Session open shall capture opening cash (`cash_float`).
FR-030. Pause/resume shall be same-user + same-setting scoped, and cross-device resume is allowed.
FR-031. Session close shall capture actual cash and persist discrepancy against expected cash.
FR-032. Cash in/out movements shall be persisted with amount, type, actor, and reason.

### Payments
FR-033. Payment methods allowed in POS shall be limited to methods with `is_available_in_pos = true`.
FR-034. Split tender shall be supported.
FR-035. Overpay shall be allowed only when at least one cash method is present.
FR-036. Non-cash payments shall not exceed remaining balance at row level.
FR-037. Payment submit shall be idempotent (duplicate submit must not create duplicate financial documents).
FR-038. Successful payment shall produce POS payment summary and allocate payments to generated sales documents.
FR-039. Refund/after-paid cancellation shall be out of POS scope and handled by sales return workflow.

### Inventory reservation and decrement behavior
FR-040. Draft save shall not decrement stock.
FR-041. Soft reservation shall begin at lock acquisition and expire with lock timeout.
FR-042. Final payment commit shall atomically create sales docs, sale payments, dispatch records, and stock decrements.
FR-043. If stock validation fails at submit, transaction shall rollback fully and keep draft unpaid.
FR-044. Stock deduction shall respect non-tax and tax buckets by allocation result.

### Multi-location prioritization rules (explicit algorithm)
FR-045. POS stock source shall use all rows in `setting_sale_locations` for current setting.
FR-046. POS source ordering shall be `position ASC`, then `location_id ASC` as tie-breaker.
FR-047. `is_pos` flag shall not be used in POS source resolution.
FR-048. Standard sale flow shall remain scoped by `locations.setting_id = current setting` and shall not consume borrowed POS sources.
FR-049. For non-serial products, allocation engine shall consume in this strict priority:
1. Non-tax stock from same-owner setting as current setting.
2. Non-tax stock from different-owner settings in configured order.
3. Tax stock from same-owner setting.
4. Tax stock from different-owner settings in configured order.
FR-050. Within each priority bucket, allocation shall follow FR-046 ordering.
FR-051. If requested quantity cannot be fully allocated, system shall reject submit with shortage details.
FR-052. One POS payment may produce multiple `sales` documents partitioned by allocated location owner `setting_id`.

### Serial number rules
FR-053. Serial-required products shall require serial selection/scanning before payment submit.
FR-054. Serial authoritative metadata shall come from `product_serial_numbers`.
FR-055. Serial tax shall use `product_serial_numbers.tax_id`.
FR-056. Serial location shall use `product_serial_numbers.location_id` and must be in current POS source set.
FR-057. Serial status must be sellable (active and not in return process) at submit time.
FR-058. Serial count must exactly match sold quantity for serial-required lines.

### Tax determination rules
FR-059. For non-serial products, tax/non-tax determination shall be based on allocated quantity buckets (`quantity_non_tax`, `quantity_tax`).
FR-060. Non-tax quantity shall always be prioritized before tax quantity across configured locations.
FR-061. For serial products, tax shall come from serial row tax id.
FR-062. Taxable POS lines shall be treated as tax-included pricing.
FR-063. Non-tax lines shall store zero tax amount and no forced tax id.
FR-064. Tenant-level tax override logic is out of POS scope for this phase.

### Audit logs
FR-065. The system shall persist audit entries for draft create/edit/submit, lock acquire/release/override, void, payment submit success/fail, and session cash operations.
FR-066. Each audit entry shall include actor, setting, POS code, action, timestamp, and payload diff/context.
FR-067. Audit records shall be immutable after write.

### Receipts, printing, and traceability
FR-068. POS receipt print route shall remain available and tenant-scoped.
FR-069. Receipt document shall display POS code and linked sales references.
FR-070. Reprint-last functionality shall remain available for authorized users.
FR-071. POS transaction history shall remain available with session/date/cashier filtering.

## Non-functional requirements
### Performance
NFR-001. Product search response in POS shall be `<= 300ms` p95 for first page under normal load.
NFR-002. Draft lookup by code shall be `<= 200ms` p95.
NFR-003. Lock acquisition/refresh shall be `<= 150ms` p95.
NFR-004. Payment commit (including stock and document writes) shall be `<= 2s` p95 and `<= 5s` p99.

### Reliability
NFR-005. Payment submit shall be idempotent.
NFR-006. Finalization writes shall be atomic in one DB transaction.
NFR-007. On failure, no partial financial/stock side-effects are allowed.
NFR-008. Lock expiration and reservation release shall be deterministic without manual cleanup dependency.

### Security
NFR-009. All POS actions shall enforce RBAC by persona capability.
NFR-010. Tenant isolation shall apply to all draft, receipt, sale, and print access.
NFR-011. Lock override and manager edits shall require privileged permission and full audit.

### Observability
NFR-012. Structured logs shall include setting_id, user_id, pos_code, and request correlation id.
NFR-013. Metrics shall include draft_created, lock_acquired, lock_timeout, payment_success, payment_failed, void_count, stock_failures.
NFR-014. Error responses shall expose stable error codes and trace id.

## API contracts (existing and needed)
### Existing routes that remain
- `GET /app/pos/session`.
- `GET /app/pos`.
- `POST /app/pos/reprint-last`.
- `GET /pos-receipt/{receipt}/print`.
- `GET /pos-transactions`.

### Needed POS contracts for frozen flow
1. `POST /app/pos/drafts`
- Purpose: create draft and allocate POS code.
- Request: customer(optional), cart lines, customer-tier context, note.
- Response: `201` with `{code, status, expires_at, totals}`.

2. `GET /app/pos/drafts/{code}`
- Purpose: retrieve draft by POS code.
- Response: `200` with draft payload, lock status, allocation preview.

3. `PATCH /app/pos/drafts/{code}`
- Purpose: update draft prior to payment submit.
- Access: Floor User (own draft), Cashier Manager.

4. `POST /app/pos/drafts/{code}/lock`
- Purpose: acquire checkout lock.
- Response: lock owner + expires_at.

5. `DELETE /app/pos/drafts/{code}/lock`
- Purpose: release lock.

6. `POST /app/pos/drafts/{code}/submit-payment`
- Purpose: finalize payment and commit stock + document writes.
- Request: payments array, note, idempotency key.
- Response: `200` with `{receipt_id, receipt_number, linked_sales:[...], change_due}`.

7. `POST /app/pos/drafts/{code}/void`
- Purpose: void draft before payment submit.
- Access: Cashier Manager/Admin.

### Contract notes
- Existing `StorePosSaleRequest` payment rules remain baseline for method validity and overpay behavior.
- `sales.reference` for POS-generated `sales` must be generated by `Sale` entity logic (do not force `'PSL'`).

### Events (needed)
- `pos.draft.created`
- `pos.draft.updated`
- `pos.draft.locked`
- `pos.draft.lock_released`
- `pos.draft.voided`
- `pos.payment.submitted`
- `pos.payment.failed`
- `pos.payment.completed`

## Validation and error handling rules
### Standard error payload
All POS API errors shall return:
`{ code: string, message: string, details: object|null, trace_id: string }`

### Frozen error codes
- `POS_DRAFT_NOT_FOUND` (404)
- `POS_DRAFT_EXPIRED` (409)
- `POS_DRAFT_ALREADY_PAID` (409)
- `POS_DRAFT_VOIDED` (409)
- `POS_DRAFT_STATE_INVALID` (422)
- `POS_LOCK_CONFLICT` (409)
- `POS_LOCK_FORBIDDEN_OVERRIDE` (403)
- `POS_PERMISSION_DENIED` (403)
- `POS_SESSION_REQUIRED` (409)
- `POS_SESSION_PAUSED` (409)
- `POS_PAYMENT_METHOD_INVALID` (422)
- `POS_NON_CASH_OVERPAY` (422)
- `POS_PAYMENT_IDEMPOTENCY_CONFLICT` (409)
- `POS_STOCK_INSUFFICIENT` (409)
- `POS_SERIAL_INVALID` (422)
- `POS_SERIAL_UNAVAILABLE` (409)
- `POS_SERIAL_LOCATION_NOT_ALLOWED` (422)
- `POS_LOCATION_SOURCE_EMPTY` (409)
- `POS_REFERENCE_GENERATION_FAILED` (500)

## Data model changes (frozen target)
DM-001. Remove `setting_sale_locations.is_pos` from read/write paths and schema in this delivery.
DM-002. Keep `setting_sale_locations.position` and use it as primary ordering signal.
DM-003. Ensure `settings.pos_document_prefix` is available and used for POS code generation.
DM-004. Extend POS document persistence to support draft lifecycle before payment submit.
DM-005. Persist draft line items and allocation/serial payload in POS domain tables (new or extended) so draft survives session/device changes.
DM-006. Keep and use `sales.pos_receipt_id` and `sale_payments.pos_receipt_id` links.
DM-007. Keep `Sale` reference generation behavior; remove POS hardcoded `reference` assignment.
DM-008. Add/adjust indexes for draft lookup (`code`, `setting_id`, `status`, `expires_at`) and lock fields.
DM-009. Persist POS audit trail entries for all privileged/state-changing actions.

## Acceptance criteria checklist
- [ ] Floor User can create draft and receive POS code format `<prefix>-YYYY-MM-00001`.
- [ ] Draft code equals final receipt number after successful payment.
- [ ] `sales` documents are not created during draft stage.
- [ ] Payment submit creates one POS receipt and one-or-more `sales` docs by owner setting.
- [ ] Sales references are generated by existing `Sale` entity logic, not hardcoded POS value.
- [ ] Stock decrement and dispatch happen only at successful payment commit.
- [ ] Payment commit is atomic and idempotent.
- [ ] Non-serial allocation uses frozen priority order with deterministic tie-breaker.
- [ ] Serial tax/location comes from serial table and is validated at submit.
- [ ] Cashier pay-only cannot edit draft content.
- [ ] Cashier Manager can edit all draft fields before payment submit.
- [ ] Void is rejected after payment submit starts.
- [ ] Lock enforces single active cashier with 15-minute timeout.
- [ ] Lock override is restricted and audited.
- [ ] POS session gating (`active` required) is enforced.
- [ ] Pause/resume same-user+same-setting works across devices.
- [ ] `/sales-location-configurations` supports ordering and separate non-row add/remove controls with no row-level POS actions.
- [ ] Standard sale location behavior remains unchanged and setting-scoped.
- [ ] POS history and receipt print remain tenant-safe and available.
- [ ] Error payload and error codes are consistent across POS endpoints.
- [ ] Required logs/metrics/audit data are emitted for critical flows.

