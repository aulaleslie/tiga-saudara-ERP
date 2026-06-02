## Context

Purchase and sales imports are line-oriented, while the source CSV repeats invoice-level fields such as `Total`, `Pembayaran`, `Sisa Tagihan`, `Diskon`, and `Biaya Pengiriman` on every product row. Recent payment ledger work made those fields authoritative and added strict reconciliation before creating purchase or sale payment rows.

Current purchase and sales import services group rows by invoice plus product-name marker owner. That grouping was introduced to avoid tag-driven owner drift, but it conflicts with two current requirements:

- Non-Daizu source tags are intended to be the primary owner signal when they map to a known setting.
- Payment fields remain source-invoice-level, so split-owner groups cannot each reconcile against the full source `Total`.

Examples from `upload-data/` show both cases. Purchase invoice `JL.2008.05975` has `Tag = CV TIGA NUSA` on all rows, including unmarked zero-price bundle rows; marker-only grouping splits those zero rows away from the priced rows and rejects them. Sales invoice `JL1071` has blank tags and legitimately splits by marker, so payment must be allocated across the generated owner documents.

## Goals / Non-Goals

**Goals:**

- Apply one effective owner resolver in purchase and sales imports: Daizu product rule, mapped tag, then product marker fallback.
- Use the same effective owner for grouping, document owner, stock/dispatch/receipt location owner, ProductPrice owner, inventory Transaction owner, and duplicate checks.
- Reconcile source invoice payment fields at source invoice scope before owner document creation.
- Allocate paid and outstanding amounts pro-rata by owner document total when a source invoice splits into multiple owner documents.
- Allow zero-total owner groups and prevent payment rows from being created for those groups.
- Preserve existing document discount and shipping reconciliation behavior.

**Non-Goals:**

- Do not mutate historical imported purchases, sales, payments, stock, dispatches, or prices.
- Do not add a new database column or migration.
- Do not infer multiple payment methods from CSV tags, notes, memo fields, or bank references.
- Do not change manual purchase or sale creation/edit/payment flows.
- Do not change Daizu product detection beyond preserving the existing whole-word product-name rule.

## Decisions

### Decision 1: Resolve owner as Daizu, then mapped tag, then marker fallback

For every import row:

```text
if product name contains whole-word KEDELE, KEDELAI, or RAGI:
    owner = Daizu Kedelai
else if Tag maps to a known owner:
    owner = mapped Tag owner
else:
    owner = product marker owner
```

Rationale: Daizu is product-domain-specific and must remain absolute. For non-Daizu imports, the source `Tag` is the intended company signal. Product markers remain useful only when the tag is blank or unmapped.

Alternative considered: keep marker-only ownership. This was rejected because it rejects valid tagged invoice rows and places tagged unmarked products under the wrong company.

### Decision 2: Keep unmapped tags as metadata and fall back to marker

Unmapped non-empty tags will not invalidate a row solely because the tag is unknown. The importer will keep syncing the raw tag as metadata and use the marker fallback for owner resolution.

Rationale: historical source files may contain labels that are useful for audit but not reliable as owner mappings. Falling back preserves import continuity without discarding the tag.

Alternative considered: fail unmapped tags. This is stricter but likely blocks historical imports unnecessarily.

### Decision 3: Validate invoice-level payment fields at source invoice scope

Before creating owner documents, the importer should group pending rows by source invoice number and validate repeated document-level fields at that source invoice scope. The source invoice adjusted total is the sum of all owner-group adjusted totals after document discount and shipping handling.

Rationale: `Total`, `Pembayaran`, and outstanding fields represent the original invoice, not each generated owner document.

Alternative considered: validate each owner group against the repeated source fields. This is the current failure mode for legitimate split-owner imports.

### Decision 4: Allocate payment pro-rata by owner document total

When a source invoice splits into multiple positive-total owner documents, allocate paid and outstanding amounts by each owner group's adjusted total divided by the full source invoice adjusted total. Round to cents and assign any final rounding remainder to the largest positive-total group.

Rationale: pro-rata allocation preserves the source paid/due ratio and avoids arbitrary payment ordering.

Alternative considered: pay groups sequentially until the source paid amount is exhausted. This is simple but creates owner-specific balances based on row order rather than source accounting data.

### Decision 4b: Allocate document-level discount and shipping pro-rata, not per group

`Diskon` and `Biaya Pengiriman` are repeated on every source row but represent a single invoice-level amount. They must be resolved once at source-invoice scope and then allocated across owner groups pro-rata by each group's gross line total (line totals plus tax, before adjustment), with the two-decimal rounding remainder assigned to the largest positive-total group. Each owner group's adjusted total is `grossTotal - allocatedDiscount + allocatedShipping`, and the source invoice adjusted total is the sum of those.

Rationale: subtracting the full document discount/shipping inside each owner group (as the first implementation did) double-counts the adjustment on a split-owner invoice. For two equal groups with line totals 100000 + 100000 and a repeated Diskon of 15000, the per-group approach yielded (100000 - 15000) + (100000 - 15000) = 170000 and falsely rejected a valid source Total of 185000. Pro-rata allocation gives 92500 + 92500 = 185000 and persists each owner header's `discount_amount`/`shipping_amount` as its allocated share, so the summed headers reconcile back to the source values. A single-positive-group invoice receives the whole amount, preserving prior single-owner behavior. This is implemented in `App\Support\ImportDocumentAdjustmentAllocator`, mirroring `ImportPaymentAllocator`.

Alternative considered: apply the full document discount/shipping to each owner group. Rejected — it is the double-counting failure mode this decision fixes.

### Decision 5: Allow zero-total owner groups with no payment

Owner groups with adjusted total `0.00` are valid when their source rows are otherwise valid. Their document header paid amount and due amount should be `0.00`, and no purchase or sale payment row should be created for them.

Rationale: historical files contain zero-priced bundle/component rows that still need quantity, stock, and audit representation.

Alternative considered: attach zero-total rows to the nearest positive owner group. This would hide the row's effective owner and complicate stock ownership alignment.

### Decision 6: Keep implementation parallel in purchase and sales services

The purchase and sales importers currently duplicate similar ownership and grouping code. This change should keep behavior aligned in both modules, with a shared helper only if it can be introduced without broad refactoring.

Rationale: a focused parallel change reduces blast radius. A larger shared abstraction can be considered later if duplicate logic keeps growing.

Alternative considered: first extract a common import ownership/allocation service. This may be cleaner long-term but increases risk for an accounting import fix.

## Risks / Trade-offs

- Changed owner outcomes for tagged non-Daizu imports -> Add focused tests where mapped tags override `*`, ` TP`, and unmarked products for both purchase and sales.
- Partial-payment allocation can create fractional cents -> Round to two decimals and assign the final remainder deterministically to the largest positive owner group.
- Source invoice discount/shipping across split owners can be ambiguous -> Keep existing repeated adjustment validation and allocate document-level adjustments consistently with the owner-group total calculation used for payment allocation.
- Duplicate checks may change when a mapped tag overrides marker ownership -> Test duplicate matching using the effective owner key, including changed tag re-imports.
- Zero-total documents may affect reports that assume positive totals -> Assert zero-total groups have no payment row and preserve stock/transaction behavior.

## Migration Plan

1. Add focused purchase and sales tests for mapped tag precedence over `*`, ` TP`, and unmarked product names.
2. Add focused purchase and sales tests for unmapped tag fallback to marker while preserving tag metadata.
3. Add fixture-level tests for `JL.2008.05975` and `JL1071`-style split invoices: fully paid, partial paid, and zero-total owner groups.
4. Update owner grouping, tenant resolution, stock owner resolution, price owner selection, and duplicate checks to use the same effective owner resolver.
5. Add or adapt payment allocation logic so source invoice payment fields are validated once and allocated to owner documents.
6. Run focused import tests, then a broader import/payment test pass if practical.

Rollback is code-only. Reverting this change restores marker-only grouping and current payment validation behavior. No database migration or historical backfill is required.

## Open Questions

None. Ownership priority, unmapped tag fallback, pro-rata payment allocation, and zero-total owner group behavior have been decided.
