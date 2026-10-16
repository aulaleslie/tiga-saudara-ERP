# Design

## Context

See proposal.md for motivation. Checkout Print uses PosReceiptService::getReceiptData; completed Reprint uses PosReceiptReprintProjectionService::getCompletedReprintReceiptData. Both render pos::receipt. The latter updates Sale-derived amounts but retains base quantities and serials and checkout paid_total/change_total. Transaction Print and global-payment Reprint also need consistent routing.

PosReturnLine holds POS-line provenance, owner/sale/detail links, resolution, bundle grouping, and returned/replacement serial IDs. Final approval through PosReturnController calls executeApprovalFromPreview, which atomically receives, executes effects, and marks returns completed. Legacy approval-only states also exist. Cash correction already reduces Sale quantities and caps active payments, while refund evidence is created separately. Therefore return value cannot automatically be interpreted as cash actually refunded.

## Goals / Non-Goals

**Goals:** One read-only current receipt projection, provable line/serial lineage, consistent financial presentation, and explicit return history using existing Laravel module patterns.

**Non-Goals:** Rewrite checkout snapshots, change draft/loaded previews, migrate historical transactions, redesign return approval, or require the full test suite. No schema change is expected.

## Decisions

### Shared completed-receipt builder

Extract or extend the existing reprint projection into a common current-state builder callable by all ordinary completed receipt actions. Retain the existing receipt service as the original snapshot source and explicit historical path; avoid recursive calls between the two. Controllers retain permission/setting checks, successful PRINT/REPRINT logging, and the shared Blade template. Log only after a successful projection. Existing ambiguity fallback remains available for Print as well as Reprint. Separate builders per controller were rejected because they allow divergent receipts.

### Completed return effects and provenance

Load the entire checkout transaction group and related return headers/lines/execution records in batches, scoped to the authorized checkout/business. Include only completed, non-reversed, non-deleted returns. Apply deterministic completion-time order with header/detail IDs as tie-breaks. Do not reuse consumesReturnQuantity directly: it includes states which reserve eligibility without proving completed execution.

Map customer rows by pos_transaction_line_id and persisted Sale provenance. Use linked execution quantities and immutable snapshots to validate remaining quantities and source units. Do not subtract returns again from already-corrected Sale quantities. Cash returns reduce customer quantity once; grouped owner/component rows are not separate purchased units. Preserve authoritative current commercial charges and existing minor-unit header allocation. Remove zero-remaining customer rows before rendering, allowing all rows to disappear without treating a legitimate full return as a mapping failure.

For cross-owner replacements, preserve the customer-facing row and value while following persisted replacement Sale/dispatch linkage; source-sale reduction alone must not look like a cash return or lose replacement value. Extend the sale resolver only as needed to include proven execution targets without duplication. Recalculate packing groups from remaining base quantities and original unit factors. Scale bundle composition once, preserving original composition identities; component movements remain informational rather than billable rows.

Alternative rejected: always subtract return quantities from current Sale quantities, which would double-reduce already corrected records. Guessing repeated-product mapping is also rejected; retain proven historical mapping rules and otherwise offer historical output.

### Effective serials and return history

Build effective serial sets from original posted assignments plus completed execution lineage. Apply replacement A-to-B chains in execution order and remove effective serials on cash return. Preserve unaffected serials and parent/component distinctions; parent-only replacement does not replace informational component identities. Resolve labels from persisted execution/snapshot evidence, not current stock status or live bundle definitions. Refuse ambiguous lineage rather than printing a guessed warranty identity.

Emit a separate return_history payload grouped by customer return reference, including completion date/status, customer item and quantity, resolution, purchase-value credit, debt offset, actual cash refund, and serial pair/replacement information. The Blade view renders it below main purchase items/totals using Indonesian labels and existing 72 mm typography. Fully returned items remain accessible here.

### Current financial presentation

Reuse authoritative current totals and live debt, then derive retained paid amount as current total minus live debt after verifying payment evidence. Use integer minor units internally and existing Rupiah formatting at the boundary. Later global payments remain included. Reconcile payment-method rows using persisted active/reconciled allocations; never arbitrarily assign a refund to a tender method. Original tender and checkout change remain historical facts and must not appear as current retained payments/change following returns. Actual refunds appear only in the return section, separately from debt offsets.

Acceptance examples: original total 300,000 / paid 200,000 / debt 100,000 yields 250,000 / 200,000 / 50,000 after a 50,000 return, with zero cash refund; a 150,000 return yields 150,000 / 150,000 / zero with a 50,000 cash refund. Fully paid 300,000 less a 100,000 return yields total and paid 200,000.

Implementation must verify these examples against final approval and refund evidence, not just render calculated numbers. If current execution records the full return credit as cash despite unpaid debt, make the smallest debt-first refund settlement correction in the existing transactional return lifecycle, with focused regression coverage and no historical rewrite. Never compensate by mislabeling persisted cash evidence. Contradictory historical data results in the existing actionable refusal/historical fallback.

### Focused verification

Extend PosReprintCurrentMoneyTest, receipt generation/reprint tests, and a focused return-execution test only where needed. Cover partial/full returns, all-items-returned, debt-first examples, later payments/multiple payment methods, serial replacement chains and later refund, non-serial/cross-owner replacements, bundles/packing, excluded lifecycle states, endpoint parity, historical output, and ambiguity/audit/access guards. Use compact parameterized cases and existing fixtures. Run named tests or focused php artisan test filters only; no full-suite requirement.

## Risks / Trade-offs

- [Already corrected Sale records] → Validate effects using provenance and avoid double subtraction of quantities, refunds, or bundle charges.
- [Refund evidence differs from debt-first arithmetic] → Verify execution early; narrowly correct new settlement behavior if necessary and refuse inconsistent historical projections.
- [Cross-owner replacement shifts value to another Sale] → Follow execution links while keeping one customer-facing row and include value exactly once.
- [Legacy or repeated-product mapping is ambiguous] → Preserve conservative mapping checks and historical receipt access.
- [Concurrent return/payment execution] → Read a coherent projection using existing transaction conventions; never mix pre-return lines with post-return totals.
- [Thermal return history grows] → Allow vertical expansion, wrap descriptions/serials, and retain intact monetary columns.

## Migration Plan

No backfill or schema migration is planned. Implement service and controller wiring, view changes, and narrowly necessary settlement correction together. Run focused tests, then inspect representative 72 mm receipts. Rollback restores prior receipt routing/rendering; any lifecycle correction must preserve existing persisted audit/payment evidence. Printing never changes stored transactions.
