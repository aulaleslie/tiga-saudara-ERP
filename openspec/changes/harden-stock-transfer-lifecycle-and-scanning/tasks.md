## 1. Baseline and Data Integrity

- [ ] 1.1 Add focused characterization tests for the current transfer route permissions, pending creation, edit rendering failure, lifecycle status guards, dispatch/receive provenance, and historical terminal-state rendering before changing behavior.
- [ ] 1.2 Design and add SQLite/MySQL-compatible migrations for transfer revision, draft/completed/awaiting-return/archive state data, archive actor/reason/time, and required indexes/foreign keys without rewriting historical document numbers or terminal records.
- [ ] 1.3 Add the append-only `transfer_action_histories` schema, model, relationships, casts, action constants, and uniqueness/idempotency constraints.
- [ ] 1.4 Add normalized line-level return-obligation storage for required, return-dispatched, and return-received tax/broken quantities and exact serialized obligations, with indexes and referential constraints.
- [ ] 1.5 Add or tighten safe transfer/line database constraints for origin/destination references, actor references, positive quantities, and unambiguous duplicate lines where compatible with existing data.
- [ ] 1.6 Add model status helpers, revision handling, historical `RECEIVED`/`RETURN_RECEIVED` terminal compatibility, and relationships for action history and return obligations.

## 2. Permissions and Lifecycle Foundation

- [ ] 2.1 Add `stockTransfers.archive` to centralized permissions and role-management synchronization, and verify existing edit permission no longer grants approval/rejection.
- [ ] 2.2 Implement a transfer lifecycle policy/service that locks the transfer row and enforces active-tenant, permission, revision, and allowed-state rules for approve, reject, acknowledge, resubmit, archive, dispatch, receive, return dispatch, and return receipt.
- [ ] 2.3 Implement append-only action-history recording for creation/submission, edits, approval, rejection reason, acknowledgement, resubmission revision, archive reason, dispatch review, movement, return, and completion actions.
- [ ] 2.4 Route approval and rejection through the lifecycle service with `stockTransfers.approval`, origin tenancy, self-approval support, mandatory rejection reason, atomicity, and idempotency.
- [ ] 2.5 Add rejection acknowledgement to `DRAFT`, draft resubmission to a new `PENDING` revision, and approved pre-dispatch archival with reason.
- [ ] 2.6 Update transfer detail/list actions and lifecycle history UI so only valid permitted actions render for the active tenant and immutable/archived states are clear.
- [ ] 2.7 Add feature tests for creator self-approval, editor denial, non-origin denial, rejection acknowledgement/revision history, approved immutability, archive rules, repeated requests, and approve/reject/edit races.

## 3. Transfer Lookup, Scanning, and Allocation Preview

- [ ] 3.1 Implement a transfer-specific exact resolver using canonical product barcode identities, conversion barcodes, and active origin-location serials without POS price or sales-location assumptions.
- [ ] 3.2 Implement a stock-aware debounced text search query for stock-managed products at the selected origin without per-result stock queries.
- [ ] 3.3 Implement base-unit normalization for product and conversion scans, preserving unit/factor/scan-count context and rejecting non-positive, ambiguous, or fractional transfer conversions without truncation.
- [ ] 3.4 Implement server-authoritative serial scan selection, duplicate prevention, product/location validation, and exact tax/broken provenance derivation.
- [ ] 3.5 Implement the non-tax-first allocation preview service for separate normal and broken modes, using actual stock buckets rather than `is_pkp` to classify inventory.
- [ ] 3.6 Add tests for exact product, conversion, and serial scans; repeated scans; base-unit accumulation; fractional rejection; ambiguous search; duplicate serials; mixed-stock warnings; and normal-versus-broken isolation.

## 4. Unified Create and Edit Workflow

- [ ] 4.1 Define one normalized transfer form state/DTO for locations, requested base quantities, normal/broken mode, conversion scan context, selected serial IDs, and allocation preview.
- [ ] 4.2 Implement an atomic transfer draft service that authoritatively reloads tenant/location/product/conversion/serial/stock data and creates, updates, or resubmits complete transfer headers and lines.
- [ ] 4.3 Replace raw Eloquent `existingProducts` edit hydration with a deterministic form-state mapper that preserves quantity buckets, scan context, serials, and validation errors.
- [ ] 4.4 Refactor create and edit to use the same Livewire form/table behavior, pending/draft edit rules, scanner focus recovery, exact-scan feedback, text fallback, and persistent line-level tax-return warnings.
- [ ] 4.5 Make the resource controller store/update paths delegate to the same draft service or remove unreachable duplicate writes while preserving required route compatibility.
- [ ] 4.6 Enforce origin-tenant and editable-state authorization in both HTTP/Livewire boundaries and the draft service, and prevent client stock snapshots or tax metadata from becoming authoritative.
- [ ] 4.7 Add Livewire and feature tests for create/edit hydration, atomic rollback, direct endpoint authorization, duplicate normalization, stale/tampered state, serial persistence, resubmission, and idempotent creation.

## 5. Authoritative Dispatch Allocation

- [ ] 5.1 Implement dispatch allocation that locks stock rows in deterministic order and consumes normal non-tax before normal tax or broken non-tax before broken tax according to approved line mode.
- [ ] 5.2 Implement locked serialized dispatch validation and actual provenance derivation from authoritative serial records, including exact serial movement and history.
- [ ] 5.3 Persist immutable actual dispatched bucket quantities and serial snapshots separately from requested/preview quantities, and record matching inventory transactions.
- [ ] 5.4 Implement approved-preview comparison and allocation hashing, returning a line-level dispatch review when actual taxed or mandatory return exposure increases.
- [ ] 5.5 Implement hash- and revision-bound dispatch acknowledgement that recalculates under locks and rejects stale confirmation.
- [ ] 5.6 Refactor initial dispatch into one atomic service transaction covering header state, line provenance, stock/product totals, serial locations/history, inventory transactions, return obligations, and action history.
- [ ] 5.7 Add tests for non-tax-only allocation, mixed spillover, PKP mixed historical stock, insufficient normal stock despite broken availability, intentional broken allocation, stale acknowledgement, rollback, concurrency, and duplicate dispatch.

## 6. Receiving and Cross-Tenant Tax Return

- [ ] 6.1 Refactor destination receiving to lock authoritative state and add exactly actual dispatched bucket quantities and serials rather than planned line quantities.
- [ ] 6.2 On receipt, complete same-tenant and cross-tenant non-tax-only transfers, and transition cross-tenant transfers with outstanding actual taxed obligations to `AWAITING_RETURN`.
- [ ] 6.3 Refactor return dispatch to remove only outstanding normal-tax/broken-tax quantities and exact obligated taxed serials from destination stock while leaving non-tax quantities in place.
- [ ] 6.4 Refactor return receipt to restore exactly return-dispatched taxed provenance at the origin, close obligations, and complete only when nothing mandatory remains outstanding.
- [ ] 6.5 Update transfer detail/list presentation to show requested, previewed, actually dispatched, retained non-tax, obligated taxed, return-dispatched, and return-received provenance with exact serials.
- [ ] 6.6 Add tests for same-tenant completion, cross-tenant non-tax completion, mixed taxed obligation, taxed broken return, exact taxed serial return, non-tax serial retention, insufficient return stock, rollback, concurrency, and duplicate receipts.
- [ ] 6.7 Add compatibility tests ensuring historical `RECEIVED` and `RETURN_RECEIVED` transfers render as terminal and are not silently assigned new obligations.

## 7. Hardening, Performance, and Verification

- [ ] 7.1 Audit transfer queries and relationships for N+1 behavior, select only needed lookup/list columns, add supporting indexes, and verify scanner/search query plans at representative product/stock scale.
- [ ] 7.2 Normalize error handling and user feedback so validation conflicts remain actionable while unexpected exceptions are logged with transfer, revision, actor, tenant, and action context without leaking sensitive details.
- [ ] 7.3 Verify every state-changing transfer endpoint uses CSRF protection, permission and tenant checks, idempotency where applicable, locked state validation, and atomic transactions.
- [ ] 7.4 Run focused transfer, barcode, serial, permission, stock-projection, and Livewire test suites and resolve regressions.
- [ ] 7.5 Run `composer test:fresh-sqlite` and verify all new migrations and statuses behave consistently under SQLite.
- [ ] 7.6 Run the project formatting/static checks applicable to changed PHP and Blade files and document any pre-existing unrelated failures.
- [ ] 7.7 Execute UAT for product/conversion/serial scanning, edit after rejection, creator self-approval, archive, dispatch drift, same-tenant receipt, cross-tenant non-tax completion, and mixed-tax mandatory return.
