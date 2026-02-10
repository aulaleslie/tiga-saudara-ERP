# Phase 2 — Final Requirements (Frozen)

Date frozen: 2026-02-08  
Feature: Re-receive previously returned serial numbers in purchase receiving flow

## Scope
### In-Scope
1. Allow purchase receiving to accept serial numbers with reusable status (`RETURNED`).
2. Keep blocking serials that are still in return process (`RETURN_IN_PROCESS` or `is_in_return_process=true`).
3. Enforce uniqueness only per `product_id + serial_number`.
4. Keep duplicate blocking inside the same form submission.
5. Remove cross-document pending-receiving duplicate validation (no “pending receiving” duplicate message).
6. Reactivate existing serial row (update), not create a new row, when re-receiving returned serial.
7. Show informational UI feedback when a previously-used returned serial is accepted.
8. Prevent concurrent/double approval for receiving approval on the same purchase; second concurrent request must fail immediately.
9. Keep old purchase serial visibility via history-first logic, and keep returned appearance red on old purchase.
10. Apply visibility change only to purchase show page.

### Out-of-Scope
1. Global search/global reporting behavior changes.
2. Import flow behavior for serial numbers.
3. `serial-numbers/update` endpoint behavior changes.
4. CI test-suite expansion to include `Modules/*/Tests` as default.
5. Large schema redesign removing `received_note_detail_id`.

## User Stories / Use Cases
1. As receiving staff, I can scan a serial that was returned to supplier and successfully use it in a new purchase receiving for the same product.
2. As receiving staff, I get a clear blocking message if scanned serial is still under return process.
3. As receiving staff, I cannot accidentally submit duplicate serials in one receiving form.
4. As approver, if another approval is running for the same purchase, my concurrent request fails immediately with conflict.
5. As auditor, I can still see old purchase serial history as returned (red) even after serial is reused in a new purchase.

## Functional Requirements (Numbered, Testable)
1. **FR-001 Reusable Status Rule**  
`RETURNED` serial is considered receivable again for purchase receiving validation.
2. **FR-002 Return-Process Block Rule**  
If serial status is `RETURN_IN_PROCESS` or `is_in_return_process=true`, validation must fail with explicit “serial under return process” message.
3. **FR-003 Same-Product Uniqueness Rule**  
Duplicate checks are scoped to `product_id + serial_number`; same serial text on different products is allowed.
4. **FR-004 In-Form Duplicate Guard**  
Within one receiving form submission, duplicate serial input for the same detail/product is rejected.
5. **FR-005 No Cross-Pending Duplicate Validation**  
Validation must not reject serials because they exist in other pending receiving documents.
6. **FR-006 Reactivation Behavior on Approval**  
When approving a receiving containing a reusable returned serial, system updates existing `product_serial_numbers` row instead of inserting a new row.
7. **FR-007 Reactivation Field Updates**  
On reactivation, system sets:
`status=ACTIVE`, `is_in_return_process=false`, `purchase_return_id=null`, `received_note_detail_id=<new detail>`, `location_id=<receiving location>`, `tax_id=<purchase detail tax>`.
8. **FR-008 History Recording**  
System appends `SerialNumberHistory::EVENT_RECEIVED` for reactivated serial on approval.
9. **FR-009 Info Notification on Reuse**  
UI shows non-error informational message when previously-used returned serial is accepted/reactivated.
10. **FR-010 Approval Concurrency Guard**  
For the same purchase, only one approval process can run at a time; concurrent attempt must fail immediately.
11. **FR-011 Conflict Response Contract**  
Concurrent approval conflict returns HTTP `409` with clear conflict reason.
12. **FR-012 First-Approved Wins**  
If same reusable serial appears in multiple pending documents, the first approved request wins; later approval fails deterministically.
13. **FR-013 Purchase Show History-First Rendering**  
Purchase show serial display must derive returned/red state from history-first rule:
serial has `RECEIVED` event for that purchase detail and later `PURCHASE_RETURNED` event in history.
14. **FR-014 Old Purchase Red Preservation**  
Old purchase must keep red returned indicator even after serial is reused in a new purchase.
15. **FR-015 Scope Boundary**  
History-first rendering changes are limited to purchase show page.

## Non-Functional Requirements
1. Deterministic concurrency behavior for approval (no race-dependent double success).
2. Backward compatibility with existing schema and relations.
3. No full-table scan regressions for common receiving/approval path beyond current baseline.
4. Clear end-user messaging (explicit reason for blocked return-process serial).
5. Maintain auditability through serial history events.

## API Contracts (Request / Response / Events)
### A. `POST /serial-numbers/validate`
Request:
1. `product_id` (required int, exists)
2. `serial_number` (required string)

Response:
1. Success: `200 { "valid": true, "info_message": "<optional>" }`
2. Return-process blocked: `200 { "valid": false, "message": "Serial number sedang dalam proses retur." }`
3. Duplicate active/broken/not-reusable blocked: `200 { "valid": false, "message": "<reason>" }`

Notes:
1. Must not return pending-receiving duplicate message across documents.
2. `info_message` is optional and used for previously-used returned serial accepted path.

### B. `POST /purchases/{purchase}/receive`
Request:
1. Existing receive payload remains.
2. Duplicate checks scoped per `product_id + serial_number`.

Response:
1. Existing redirect success/error behavior remains.
2. Must not fail due to serial existing in other pending receiving documents.

### C. `POST /receivings/{receivedNote}/approve`
Response:
1. Standard success response unchanged on success.
2. Concurrent conflict: HTTP `409` with clear message/code indicating approval is already being processed for that purchase.
3. If serial already reactivated by first winner, later approval fails deterministically (validation/conflict error path).

### D. Domain Events
1. On reactivation approval, append `SerialNumberHistory::EVENT_RECEIVED`.
2. No new event type introduced for this phase.

## Validation & Error Handling Rules
1. Reject empty/invalid serial input as existing behavior.
2. Reject `RETURN_IN_PROCESS` / `is_in_return_process=true` with explicit return-process message.
3. Reject non-reusable statuses (e.g. active duplicate) for same product as duplicate/not-available.
4. Allow `RETURNED` status serial for same product.
5. Reject duplicate serial entries within the same submission.
6. Do not reject due to presence in other pending receiving docs.
7. Approval conflict must return HTTP `409` (not `422`) for concurrent processing on same purchase.
8. Later approval attempt on same serial conflict must fail cleanly without partial mutation.

## Data Model Changes
1. No mandatory schema migration required.
2. Keep `product_serial_numbers.received_note_detail_id` for compatibility.
3. Use serial history as primary source for old purchase returned visibility rule on purchase show.
4. Existing unique index `product_id + serial_number` remains authoritative.

## Security & Performance Considerations
1. Preserve existing auth/permission checks (`purchases.receive`, `purchaseReceivings.approval`).
2. Concurrency guard must be implemented server-side; UI disable is supplementary only.
3. Concurrency handling must avoid deadlocks and must not leave partially updated serial/state.
4. History-first purchase-show rendering should use targeted queries/index-aware patterns to avoid N+1 and heavy scans.

## Acceptance Criteria Checklist
- [ ] Returned serial (`RETURNED`) can be scanned and accepted for receiving for same product.
- [ ] Serial under return process is blocked with explicit “sedang dalam proses retur” message.
- [ ] Same serial text on different product IDs does not trigger duplicate rejection.
- [ ] Duplicate serial in same receive submission is blocked.
- [ ] No error is shown for “serial exists in pending receiving document”.
- [ ] Approval reactivates existing serial row (no duplicate insert).
- [ ] Reactivation updates status/flags/location/tax/receive-detail fields exactly as specified.
- [ ] `RECEIVED` history event is appended on reactivation.
- [ ] UI shows informational notice for previously-used returned serial acceptance.
- [ ] Concurrent approval on same purchase returns HTTP 409 for loser request.
- [ ] First-approved wins for same serial across multiple pending docs.
- [ ] Old purchase still shows serial as returned (red) after serial is reused.
- [ ] Purchase show uses history-first rule for returned state.
- [ ] No changes are introduced to global search behavior.
- [ ] No changes are introduced to import flow behavior.
- [ ] No changes are introduced to serial update endpoint behavior.

