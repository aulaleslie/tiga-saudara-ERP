## Context

The product catalog stores product-specific direct conversions to `base_unit_id`, but purchase details and receiving details persist quantities without a UOM/conversion snapshot. Receiving approval increments stock and creates one `BUY` transaction per approved receiving detail. The current transaction schema records product, location, type, quantities, and a human-readable purchase reference in `reason`, but has no durable foreign key back to the receipt that produced it.

When a supplier delivery was entered in a package UOM before its conversion existed, the system records the package count as the base quantity. The supplier invoice amount is still correct, but inventory and average HPP are understated. This change is deliberately limited to products with no stock-affecting outbound history, allowing historical quantities and their original `BUY` rows to be corrected in place rather than using a compensating inventory movement.

## Goals / Non-Goals

**Goals:**

- Normalize a user-selected set of fully received erroneous lines for one product and one direct conversion into the existing base UOM.
- Preserve each supplier document's monetary values and existing row identity while correcting its quantity and per-base-unit cost basis.
- Update the original receipt-created `BUY` transaction rows and all running quantity/bucket snapshots consistently.
- Recalculate current product cost indicators from normalized receipt history, without replaying sales HPP.
- Make the operation traceable, permissioned, atomic, idempotent, and consistent with existing Purchase Blade/CoreUI UI patterns.

**Non-Goals:**

- No hold/reservation or proactive cross-module blocking while an operator waits for incomplete receipts.
- No serial-tracked products, changes to a product's base unit, chained conversion paths, conversion-factor inference, or financial/payment correction.
- No normalization after dispatched Sales, completed POS checkouts, purchase returns, transfer/adjustment/breakage, or other later stock movement for the product.
- No automatic discovery of every historically incorrect purchase; operators explicitly select the related lines.
- No sale-HPP snapshot replay, new compensating inventory transaction, or destructive rewrite of unrelated transactions.

## Decisions

### Product-level, explicitly selected batch

Create a normalization header and selected-line records for one product, one source UOM, the product's current base UOM, and one direct positive factor. A preview may exist before all selected lines are fully received; execution is disabled until they are all complete.

This represents one business mistake spanning several purchases while avoiding accidental inclusion of correct historical purchases. A line/receiving detail can belong to only one completed normalization and cannot be selected by concurrent active normalizations.

Alternative: normalize one purchase at a time. Rejected because several erroneous receipts would leave product stock and HPP in an intermediate incorrect state.

### In-place correction with immutable audit

At execution, retain `purchase_details` and `received_note_details` IDs and update their selected quantities from source quantity to base quantity. Do not change header/document monetary values, active payment rows, supplier identity, tax identity, or receipt locations. Store detailed source and final snapshots, conversion IDs/names/factor, transaction IDs, actor, reason, and cost outcome in new immutable normalization records.

Alternative: create a compensating `ADJ` transaction. Rejected per business preference because the original receipt should present as the correct inventory fact and no later stock movement is allowed.

### Durable transaction provenance plus conservative legacy matching

Add nullable, unique `transactions.received_note_detail_id` pointing to `received_note_details`. Receiving approval writes that link when it creates its `BUY` transaction. For legacy rows without the link, resolve candidates using the receipt's product, setting, location, `BUY` type, purchase reference in reason, quantity, and approval chronology. Bind and update only a unique candidate. Zero or multiple candidates blocks execution and exposes the mismatch in preview.

Alternative: match by the text reason alone. Rejected because duplicate product/location deliveries and repeated purchase references can make it ambiguous.

### Correct original transaction snapshots in chronological order

For each matched original `BUY` row, update quantity, tax/non-tax quantity, current quantity, and before/after global and location quantity snapshots. Rebuild those snapshots for the selected product in chronological receipt/transaction order so one corrected receipt becomes the next corrected row's opening balance. Update aggregate `products` and `product_stocks` quantities/buckets to the reconstructed final values.

Execution blocks if any relevant later transaction exists, which limits the reconstruction to purchase-receipt history and prevents rewriting a ledger after consumption or relocation.

Alternative: modify only `transactions.quantity`. Rejected because stock mutation reports rely on the stored running snapshots and would become internally inconsistent.

### Strict execution-time eligibility, no hold

The form can display projected availability while related receipts are incomplete, but it does not reserve product stock or prevent drafts. Submission locks the product, selected purchases/details/receipts, linked transaction candidates, product stock rows, and relevant history, then repeats all checks within one DB transaction.

The product must be stock-managed and non-serial; all selected receipts must be approved and collectively complete their purchase line; no selected row has prior normalization; and there must be no stock-affecting dispatched standard Sale, completed POS checkout, return, transfer, adjustment, breakage, replacement dispatch, import/initialization movement, or other transaction after the earliest affected receipt. Standard sale drafts/approvals and POS draft/loaded/cancelled transactions are not blockers.

Alternative: create a hold that blocks outgoing operations while incomplete receipts await normalization. Deferred because it broadens scope across Sales, POS, Transfer, Returns, and Adjustment workflows.

### Current HPP recalculation only

Convert each selected line's existing tax-exclusive cost basis to its normalized base quantity and replay eligible purchase/return receipt history needed to compute current per-setting `ProductPrice` average and last purchase price, plus the synchronized product average. Do not alter any sale-cost snapshot because an executed sale makes the batch ineligible.

Rounding uses the application's monetary precision for totals and a higher internal per-unit precision; invoice total remains authoritative and unchanged.

### Purchase-native UI

Add an authorized action near existing Purchase “Koreksi Penerimaan” actions, a dedicated Bootstrap/CoreUI card-and-table normalization screen, preview/confirmation feedback, and read-only audit cards on affected purchase details. Disabled/unavailable execution explicitly names the blocking receipt, history row, transaction mismatch, or eligibility reason.

## Risks / Trade-offs

- [Legacy transaction cannot be matched uniquely] → Block execution; display candidates; require data repair/linking before retry.
- [Concurrent receipt approval or POS completion during confirmation] → Lock affected rows and revalidate history in the execution transaction.
- [Multiple historical purchases at distinct costs] → Preserve each line's total and calculate unit costs per line before chronological HPP replay.
- [Factor precision causes non-representable quantity] → Require direct factor and normalized result compatible with the three-decimal quantity contract before execution.
- [Existing reports expect supplier UOM] → Keep supplier financial amounts unchanged and surface base-UOM normalization/audit context on purchase views.
- [Incomplete batch is forgotten] → No system hold is introduced; preview clearly labels incomplete lines and execution remains disabled.

## Migration Plan

1. Add additive normalization header/line/audit schema and nullable transaction receipt-detail provenance key/index; do not rewrite existing data during migration.
2. Start writing the provenance key for new receiving approvals.
3. Release the privileged UI/service with preview-only legacy matching.
4. Execute normalization only when every selected legacy link resolves uniquely; record newly bound provenance atomically with the correction.
5. Rollback disables the new routes/actions while retaining correction/audit data and the nullable linkage; no destructive rollback of completed normalization facts.

## Open Questions

- Whether Super Admin alone is sufficient or a new `purchases.received.uom-normalize` permission must be explicitly granted to non-admin users. The design assumes the new dedicated permission.
- Whether the existing `transactions.type` vocabulary needs a neutral normalization marker or the audit linkage/reason is sufficient. The design assumes existing `BUY` is retained because the transaction remains a corrected receipt.
