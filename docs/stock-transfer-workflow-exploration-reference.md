# Stock Transfer Workflow — Exploration Reference

**Status:** Pre-proposal reference; no implementation is authorized by this document  
**Captured:** 2026-09-11  
**Purpose:** Preserve the current-state investigation, agreed requirements, recommended architecture, open questions, and smallest delivery order for future exploration and proposal work.

## 1. Background

The stock-transfer workflow is an existing Laravel/Livewire feature in `Modules/Adjustment` with form components under `app/Livewire/Transfer`. It already supports draft entry, transfer approval, dispatch, receipt, and a limited cross-business tax-return lifecycle.

The desired workflow expands this into a permission-aware, scanner-first, independently approved movement process. It must support blind recounting, full auditability, tax classification across PKP/non-PKP businesses, mandatory round trips in specified cases, good and broken stock, and serialized products.

This document records exploration only. Each delivery slice should receive its own OpenSpec proposal, specification, design, and tasks before implementation.

## 2. Terminology

- **Business:** An ERP `Setting`/tenant.
- **Location:** A stock location owned by a business.
- **PKP business:** A business whose `settings.is_pkp` value is true.
- **Non-PKP business:** A business whose `settings.is_pkp` value is false.
- **Good transfer:** A transfer using only saleable/good-stock buckets.
- **Broken transfer:** A transfer using only broken-stock buckets.
- **Manifest:** The approved product quantities and serials for one physical movement.
- **Blind recount:** Entry performed without exposing the expected quantities or expected serials.
- **Forward leg:** Origin dispatch followed by destination receipt.
- **Return leg:** Destination return dispatch followed by origin return receipt.

## 3. Existing Behavior Confirmed

### 3.1 Drafting and editing

- A user with `stockTransfers.create` can save a new transfer as `DRAFT`.
- A draft may be saved without a destination.
- `stockTransfers.edit` is required to edit or submit a transfer.
- The edit controller accepts `DRAFT` and `PENDING` transfers owned by the active origin business.
- The shared Livewire form supports saving separately from submission.
- Approved and later lifecycle states reject ordinary editing.

Known UI/semantic gaps:

- The transfer list exposes its edit action only for `PENDING`, so a saved `DRAFT` is not conveniently reopenable even though the backend route accepts it.
- Editing a `PENDING` request needs explicit revision semantics. The recommended behavior is to return it to `DRAFT` and require resubmission so an approver never approves a silently changed revision.

### 3.2 Entry search and scanning

The existing transfer form already supports:

- Exact primary product-barcode scans.
- Exact unit-conversion barcode scans.
- Whole base-unit normalization for conversion factors.
- Exact serial-number scans.
- Adding or incrementing non-serialized product quantities.
- Selecting serialized products without duplicating a serial.
- Origin-aware stock eligibility.
- Good-versus-broken serial eligibility.
- Tokenized product search through `Product::globalSearch()`.

Tokenized search requires every nonempty whitespace-separated token to match at least one of product name, product code, barcode, category, or brand.

This capability should be regression-tested and hardened rather than rebuilt.

### 3.3 Existing lifecycle

The current high-level lifecycle is:

```text
DRAFT → PENDING → APPROVED → DISPATCHED → RECEIVED/AWAITING_RETURN
                                               │
                                               └→ RETURN_DISPATCHED → COMPLETED
```

Transfer request approval uses `stockTransfers.approval`. Dispatch and receipt use `stockTransfers.dispatch` and `stockTransfers.receive`, but these actions immediately mutate inventory. They do not have separate preparation and approval stages.

### 3.4 Existing cross-business return behavior

Current behavior differs materially from the desired rule:

- Every different-business transfer is considered cross-tenant.
- Only actually dispatched taxed quantities create return obligations.
- Non-tax quantities may remain at the destination.
- Taxed serialized obligations require the exact same serial to return.
- Receipt currently preserves the dispatch tax/non-tax allocation instead of classifying stock for the receiving business.

The existing cross-tenant return implementation therefore cannot simply be reused unchanged.

## 4. Agreed Requirements

### 4.1 One explicit stock condition

Every transfer has exactly one stock condition for its entire lifecycle:

- `GOOD`: quantities come only from `quantity_tax + quantity_non_tax`.
- `BREAKAGE`: quantities come only from `broken_quantity_tax + broken_quantity_non_tax`.

Good and broken stock cannot be mixed in one new transfer. All forward and return movement validation must preserve the selected condition.

For broken transfers, the workflow, permissions, approvals, scanning, blind recount, PKP policy, mandatory return, substitute-serial rule, discrepancy handling, and audit requirements are identical to good transfers. Only the source and destination inventory buckets differ.

### 4.2 Stock-condition control styling

The current **Kondisi Stok** selector is not adequately styled. It should become a clear toggle/segmented control consistent with the adjustment/breakage interface and visibly represent:

- Barang Baik.
- Barang Rusak.
- Active selection.
- Disabled state before a valid origin is selected, if origin gating is retained for this control.
- Validation/error state.

Accessibility requirements should include a real form control or equivalent keyboard-operable semantics, visible focus, labels, and non-color-only active/error indication.

Changing condition after lines exist must use an explicit confirmation and clear all incompatible product/serial rows. A single historical mixed-condition transfer may be viewed but must not be silently rewritten.

### 4.3 Stock visibility

Introduce a dedicated `stockTransfers.view-system-stock` permission.

A user without this permission must not receive or see system stock expectations during any transfer phase, including:

- Create.
- Edit.
- Transfer detail/view.
- Forward dispatch preparation.
- Forward receipt recount.
- Return dispatch preparation.
- Return receipt recount.
- Rejection/correction screens.
- Livewire public state and serialized wire payloads.
- Validation messages, maximum attributes, badges, summaries, exports, logs intended for the browser, or other indirect disclosures.

Protected information includes:

- Exact available quantity.
- Tax/non-tax bucket quantities.
- Good/broken bucket quantities outside the operator's own entry.
- Expected dispatch or receipt quantities.
- Expected serial numbers.
- Allocation previews that reveal stock composition.
- Remaining-to-enter or shortage messages containing expected/available numbers.

Blind operators may see and revise values they personally entered in the current draft. In receipt workflows, their entry starts from zero and must be reentered/scanned; it is not pre-populated from the approved dispatch manifest.

Server-side stock and serial validation remains authoritative even when its details are hidden. User-facing errors for blind operators must be neutral and non-quantitative.

### 4.4 Entry behavior

Create and edit must support one main input for:

- Product barcode.
- Supported unit-conversion barcode.
- Serial number.
- Tokenized product search fallback.

For a non-serialized product:

- Product barcode increments by one base unit.
- Conversion barcode increments by its supported whole base-unit factor.
- Repeated scans accumulate deterministically.

For a serialized product:

- Serial scan adds the product when necessary and selects that serial once.
- Quantity derives from the number of valid selected serials.
- Duplicate scans do not increment quantity.
- Good mode accepts only currently available good serials.
- Broken mode accepts only currently available broken serials.

Every server mutation boundary must revalidate origin ownership, product eligibility, stock condition, serial availability, conversion factor, and duplicate status.

### 4.5 Transfer request approval

- `stockTransfers.create` creates a draft.
- `stockTransfers.edit` edits and submits a complete draft.
- `stockTransfers.approval` approves or rejects the transfer request.
- A transfer may not dispatch until the request is approved.
- A user may prepare and approve the same record when they hold both permissions; separation of duties is not mandatory.
- Editing an already pending request should return it to draft and require resubmission.
- Approval should snapshot the approved revision and route policy.

### 4.6 Separate movement permissions

Use separate preparation and approval permissions:

- `stockTransfers.dispatch.create`
- `stockTransfers.dispatch.approval`
- `stockTransfers.receive.create`
- `stockTransfers.receive.approval`

The same permission families may govern both forward and return legs. Tenant/location ownership and the movement type determine which party can act.

Legacy role migration should be deliberately designed. A compatibility candidate is:

- Existing `stockTransfers.dispatch` holders receive both new dispatch permissions.
- Existing `stockTransfers.receive` holders receive both new receipt permissions.
- Stock-visibility permission is not granted automatically unless an existing role already has an agreed equivalent visibility authority.

### 4.7 Approved movement boundaries

Every physical movement has its own draft, submission, approval/rejection, lines, serials, actors, timestamps, and history.

Inventory changes only on movement approval:

- Forward dispatch approval deducts origin inventory.
- Forward receipt approval adds destination inventory.
- Return dispatch approval deducts destination inventory.
- Return receipt approval adds origin inventory.

Rejected movement submissions return to their corresponding editable movement draft and do not reopen or mutate the original approved transfer request.

### 4.8 Blind forward receipt

After approved forward dispatch, the destination recipient must recount from zero.

- Expected quantities are hidden without stock-visibility permission.
- Expected serials are hidden without stock-visibility permission.
- The recipient reenters products through barcode, conversion-barcode, serial scan, or tokenized selection.
- The recipient sees only their own receipt draft.
- Submission does not add stock.
- A receipt approver receives a privileged expected-versus-recounted comparison.
- Any discrepancy blocks approval and requires rejection/correction; partial or discrepancy acceptance is not part of the smallest safe version.

Validation must detect:

- Missing products.
- Unexpected products.
- Quantity shortage or excess.
- Wrong, missing, unexpected, or duplicate serials.
- Serial belonging to another product.
- Serial that became unavailable or reserved.
- Good/broken condition mismatch.

Forward receipt for serialized products requires the exact serials from the approved forward dispatch manifest. This provides a control for identifying potential loss or substitution during transit.

### 4.9 Business and PKP route policy

Mandatory return is evaluated only when origin and destination belong to different businesses.

Agreed policy:

| Origin and destination | Mandatory return |
|---|---:|
| Locations in the same business | No |
| Different non-PKP businesses | No |
| PKP business → non-PKP business | Yes, full quantity |
| Non-PKP business → PKP business | Yes, full quantity |
| PKP business → PKP business | Yes, full quantity |

In short: a different-business transfer involving any PKP business requires the full received quantity to return. A different-business non-PKP-to-non-PKP transfer is one-way but remains fully tracked.

The route policy must be snapshotted at transfer approval so later changes to `settings.is_pkp` do not reinterpret an in-progress or historical transfer.

The snapshot should include at least:

- Origin business and location.
- Destination business and location.
- Origin PKP status.
- Destination PKP status.
- Whether the route is same-business.
- Whether full return is mandatory.
- Transfer stock condition.
- Destination tax-classification rule.
- Approved transfer revision.

### 4.10 Tax classification at receiving business

Confirmed requirement:

- When PKP stock is received by a non-PKP business, every received unit becomes non-tax stock, including serialized products.
- The mandatory return obligation remains the full product quantity, not merely its formerly taxed part.

Expected symmetrical classification, still requiring explicit confirmation before proposal:

- Non-PKP → PKP receipt converts received stock to tax stock and assigns appropriate serial tax provenance.
- PKP → PKP receipt records stock as tax stock.
- Non-PKP → non-PKP receipt records stock as non-tax stock.
- Same-business movement preserves its existing tax provenance.

Good/broken condition never changes during this tax reclassification:

```text
good tax/non-tax       → receiving business's good tax class
broken tax/non-tax     → receiving business's broken tax class
```

Serial tax provenance must be updated consistently with the receiving business. The design must identify which tax record is assigned when a serial becomes taxable and how its prior tax identity is preserved in immutable history.

### 4.11 Mandatory full return

When the snapshotted route requires return:

- The transfer does not become complete after destination receipt.
- A return obligation is created for the full approved-and-received quantity of every product.
- This includes originally taxed and non-tax quantities.
- This includes good or broken stock according to the transfer condition.
- The return cannot be partially accepted in the smallest version.
- Completion occurs only after approved return dispatch and approved origin receipt reconcile the full obligation.

When no return is mandatory:

- Same-business transfers complete after approved destination receipt.
- Cross-business non-PKP-to-non-PKP transfers complete after approved destination receipt.
- The movement and receiving business's retained quantities remain reportable and auditable.

### 4.12 Serial rules

Forward leg:

- Dispatch selects exact serials.
- Destination blind recount must reproduce those exact serials.
- Any mismatch blocks receipt approval.

Return leg:

- A different serial may satisfy the return obligation.
- It must belong to the same product.
- It must be currently available at the return-dispatch location.
- It must match the transfer's good/broken condition.
- It must not be duplicated, reserved, sold, missing, dispatched elsewhere, or already in another return process.
- Return receipt must reproduce the exact serials approved for return dispatch, even though those may differ from the forward serials.

An unresolved decision remains whether other product attributes besides product identity and stock condition must match when substituting a serial.

### 4.13 Audit and reporting

The system must be able to answer, by product and serial where applicable:

- Quantity requested.
- Quantity approved for transfer.
- Quantity prepared for dispatch.
- Quantity dispatch-approved and in transit.
- Quantity recounted at destination.
- Quantity receipt-approved.
- Quantity temporarily held by another business.
- Quantity that must return.
- Quantity prepared/approved for return dispatch.
- Quantity in return transit.
- Quantity return-received.
- Outstanding quantity.
- Discrepancies and rejection reasons.
- Origin, destination, business, PKP snapshot, condition, actors, timestamps, and inventory transaction references.

Users without stock visibility must receive a scrubbed projection of these records. Auditability does not authorize disclosure of protected stock expectations.

## 5. Recommended Target Lifecycle

```text
TRANSFER REQUEST
DRAFT
  → PENDING
  → APPROVED

FORWARD DISPATCH
APPROVED
  → DISPATCH_DRAFT
  → DISPATCH_PENDING_APPROVAL
  → DISPATCHED

FORWARD RECEIPT
DISPATCHED
  → RECEIVE_DRAFT                 blind recount
  → RECEIVE_PENDING_APPROVAL      privileged comparison
  → RECEIVED

NO MANDATORY RETURN
RECEIVED → COMPLETED

MANDATORY RETURN
RECEIVED → AWAITING_RETURN
  → RETURN_DISPATCH_DRAFT
  → RETURN_DISPATCH_PENDING_APPROVAL
  → RETURN_DISPATCHED
  → RETURN_RECEIVE_DRAFT          blind recount
  → RETURN_RECEIVE_PENDING_APPROVAL
  → COMPLETED
```

Implementations may keep the transfer header status coarser and derive detailed progress from movement records. Avoid multiplying header statuses if movement-document state can express the same facts more safely.

## 6. Recommended Data Direction

The current transfer header and JSON serial snapshots are insufficient for independent movement approvals and robust comparisons. Prefer additive normalized records:

```text
transfers
  ├── transfer_products
  ├── transfer_route_policy_snapshot
  ├── transfer_movements
  │     type: FORWARD_DISPATCH | FORWARD_RECEIPT
  │           RETURN_DISPATCH  | RETURN_RECEIPT
  │     status: DRAFT | PENDING | APPROVED | REJECTED
  │     prepared/submitted/approved/rejected actors and times
  ├── transfer_movement_lines
  │     product, condition, entered base quantity, applied tax class
  ├── transfer_movement_serials
  │     serial identity, product, condition, tax snapshots, validation state
  ├── transfer_return_obligations
  │     required and fulfilled base quantity per product/condition
  └── transfer_action_histories
```

Design principles:

- Persist base-unit quantities.
- Preserve unit-conversion scan context only for explanation/audit.
- Use normalized movement serial rows for uniqueness and comparison.
- Snapshot, rather than dynamically infer, approved route policy.
- Build separate blind and privileged projections.
- Lock transfer, movement, stock, and serial rows at authoritative mutation boundaries.
- Make submission/approval/rejection and inventory effects atomic and idempotent.
- Preserve historical transfers without destructive rewriting.

## 7. Smallest Delivery Order

Each numbered item should be an independent OpenSpec change unless proposal work finds a hard schema dependency requiring two adjacent items to be combined.

[~]
### Delivery 1 — Entry-form polish and editable-state correction

- Style the **Kondisi Stok** toggle.
- Make the saved `DRAFT` edit action discoverable.
- Ensure a mutation to `PENDING` returns the transfer to `DRAFT` and requires resubmission.
- Preserve origin ownership and immutable-state guards.
- Add focused UI, Livewire, controller, and lifecycle tests.

This delivery has no inventory movement changes.

### Delivery 2 — Transfer stock-visibility boundary

- Add `stockTransfers.view-system-stock`.
- Define role seeding/migration behavior.
- Remove stock snapshots from unauthorized Livewire public state.
- Hide exact origin stock, buckets, allocations, expected serials, movement expectations, and revealing errors.
- Preserve personally entered quantities and serials.
- Add blind versus privileged projections for create, edit, show, and existing lifecycle pages.
- Test rendered HTML and Livewire payloads for leakage.

This is a security boundary and should land before new recount screens.

### Delivery 3 — Entry scanning stabilization

- Characterize existing barcode, conversion barcode, serial scan, and tokenized search behavior.
- Verify rapid submissions are processed deterministically.
- Verify base-unit normalization.
- Verify duplicate serial handling.
- Verify origin and condition gating.
- Verify good transfers consume only good stock.
- Verify broken transfers consume only broken stock.
- Ensure blind validation messages do not reveal stock.

Prefer tests and narrow corrections; avoid replacing the existing resolver without evidence.

### Delivery 4 — Movement-document and permission foundation

- Add movement, movement-line, and movement-serial records.
- Add draft/pending/approved/rejected movement states.
- Add the four dispatch/receipt preparation and approval permissions.
- Establish actor, timestamp, revision, rejection-reason, idempotency, and history fields.
- Define forward/return movement types.
- Preserve current production behavior until a subsequent delivery routes an action through the new records.

This delivery should be additive and migration-safe.

### Delivery 5 — Approved forward dispatch

- Create/edit a forward dispatch draft against an approved transfer.
- Recount/scan quantities and exact serials at origin.
- Submit dispatch for approval.
- Privileged approver compares it with the approved transfer request.
- Reject discrepancies for correction.
- Deduct origin inventory only upon dispatch approval.
- Persist the immutable approved forward manifest and inventory references.

### Delivery 6 — Blind forward receipt

- Start receipt entry from zero.
- Support product, conversion-barcode, serial scanning, and tokenized search.
- Hide the forward manifest from users without visibility permission.
- Submit recount for approval without adding inventory.
- Compare product quantities, condition, and exact serials during privileged approval.
- Reject/correct any mismatch.
- Add destination inventory only after receipt approval.
- Mark same-business and eligible non-PKP cross-business transfers complete when no return is required.

### Delivery 7 — PKP route-policy snapshot and stock reclassification

- Snapshot same-business/cross-business and origin/destination PKP status at transfer approval.
- Apply the mandatory-return matrix.
- Convert received stock to the destination business's tax classification.
- Convert serial tax provenance consistently and preserve before/after history.
- Create full-quantity obligations for every mandatory-return product.
- Apply the same behavior to good and broken transfers using their respective buckets.
- Ensure historical in-progress transfers receive deterministic compatibility behavior rather than being silently reinterpreted.

### Delivery 8 — Approved return dispatch

- Create a return-dispatch draft for a full outstanding obligation.
- Support scanner-first product and serial entry.
- Require same product, full quantity, and same stock condition.
- Permit substitute serials on the return leg.
- Submit and approve independently.
- Reject/correct discrepancies.
- Deduct destination inventory only after approval.
- Persist the exact approved return manifest.

### Delivery 9 — Blind return receipt and completion

- Start origin return-receipt recount from zero.
- Hide expected return quantities and serials from blind recipients.
- Validate against the approved return-dispatch manifest during approval.
- Require exact returned serials at this boundary.
- Add origin inventory only after approval and classify it for the origin business.
- Complete only when every product obligation is fulfilled.
- Make duplicate approvals/receipts idempotent.

### Delivery 10 — Tracking, audit, and reporting

- Report forward and return in-transit quantities.
- Report stock temporarily held by another business.
- Report retained non-PKP-to-non-PKP quantities.
- Report required, returned, and outstanding quantities.
- Report discrepancies, rejections, actors, and timestamps.
- Support product and serial drill-down where authorized.
- Apply tenant scope and stock-visibility projections to screens and exports.

## 8. Verification Expectations for Every Delivery

- Use focused PHPUnit/Laravel feature and Livewire tests.
- Prefer `php artisan test` with focused filters during iteration.
- Use `composer test:fresh-sqlite` for higher-confidence integration verification where appropriate.
- Test permissions at both presentation and server mutation boundaries.
- Test active-setting ownership for origin and destination actions.
- Test crafted Livewire requests, not only hidden buttons.
- Test transaction rollback on stock, serial, lifecycle, or history failure.
- Test idempotent repeated submission/approval requests.
- Test unauthorized response payloads for data leakage.
- Test good and broken variants.
- Test serialized and non-serialized variants.
- Test same-business, non-PKP/non-PKP, PKP/non-PKP, non-PKP/PKP, and PKP/PKP routes.
- Test legacy records and migrations without destructive backfill.

## 9. Open Questions for Future Exploration

These decisions were not finalized in the captured discussion:

1. Confirm that non-PKP → PKP receipt converts all received quantities to tax stock and determine which tax ID is assigned to serialized stock.
2. Confirm that PKP → PKP receipt always normalizes all received quantities to tax stock rather than preserving unusual legacy non-tax provenance.
3. Decide whether the blind receipt operator sees expected product names without quantities, or no expected lines at all. Strongest blind-recount recommendation: hide all expected lines.
4. Define whether substitute return serials need to match attributes beyond product and good/broken condition.
5. Define how a business's applicable tax ID is resolved and snapshotted for later return reclassification.
6. Define compatibility for transfers already `APPROVED`, `DISPATCHED`, `RECEIVED`, or `AWAITING_RETURN` when the new route policy is deployed.
7. Decide whether movement approval permissions should be added to existing roles automatically or require manual role review.
8. Decide whether dispatch preparation itself is blind to approved requested quantities, or whether blindness is required only for receipt recounts.
9. Define operational cancellation/archive rules for pending or rejected movement documents.
10. Define report retention, export permissions, and whether completed transfer details remain stock-scrubbed indefinitely for users without visibility permission.

## 10. Existing Code and Specifications to Revisit

Primary implementation areas:

- `app/Livewire/Transfer/TransferStockForm.php`
- `app/Livewire/Transfer/SearchProduct.php`
- `app/Livewire/Transfer/TransferProductTable.php`
- `resources/views/livewire/transfer/transfer-stock-form.blade.php`
- `resources/views/livewire/transfer/search-product.blade.php`
- `resources/views/livewire/transfer/transfer-product-table.blade.php`
- `Modules/Adjustment/Http/Controllers/TransferStockController.php`
- `Modules/Adjustment/Entities/Transfer.php`
- `Modules/Adjustment/Entities/TransferProduct.php`
- `Modules/Adjustment/Entities/TransferReturnObligation.php`
- `Modules/Adjustment/Services/TransferDraftService.php`
- `Modules/Adjustment/Services/TransferLifecycleService.php`
- `Modules/Adjustment/Services/TransferMovementService.php`
- `Modules/Adjustment/Services/TransferScanResolverService.php`
- `Modules/Adjustment/Resources/views/transfers/show.blade.php`
- `Modules/Adjustment/Resources/views/transfers/partials/actions.blade.php`
- `Modules/Adjustment/DataTables/StockTransfersDataTable.php`
- `app/Config/Permissions.php`

Relevant established OpenSpec references:

- `openspec/specs/stock-transfer-entry-scanning/spec.md`
- `openspec/specs/stock-transfer-approval-lifecycle/spec.md`
- `openspec/specs/stock-transfer-cross-tenant-tax-return/spec.md`
- `openspec/specs/stock-opname-count-drafts/spec.md`
- `openspec/specs/stock-opname-review-approval/spec.md`
- `openspec/specs/breakage-adjustment-workflow/spec.md`
- `openspec/specs/inventory-remaining-stock-visibility/spec.md`

The future proposal must explicitly modify or supersede conflicting current stock-transfer requirements, especially the existing taxed-only return obligation and exact-original-serial return rule.

## 11. Suggested Resume Prompt

Use the following prompt for a future exploration session:

> Read `docs/stock-transfer-workflow-exploration-reference.md`, inspect the current stock-transfer code and listed OpenSpec specifications, then continue resolving the open questions. Do not propose or implement until the selected delivery slice has precise acceptance scenarios. Treat each item in the smallest delivery order as a separate candidate change.
