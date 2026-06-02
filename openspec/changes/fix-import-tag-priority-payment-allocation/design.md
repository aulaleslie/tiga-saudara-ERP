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

Fractional quantities: line totals use the CSV quantity, which can be fractional for weight-based units (e.g. `23.7` KG). The importers parse quantity via a shared `parseQuantity()` helper (float, accepting dot or comma decimal separators) at both the source-total reconciliation and document/detail creation sites, rather than casting with `(int)`. Casting to integer truncated `23.7` to `23`, dropping `0.7 × unit price` from the calculated total and falsely rejecting otherwise-valid invoices (e.g. invoice `11023`: truncation gave `2927500` vs. the source `Total` of `2936250`). Using the same parse in both places keeps reconciliation and persisted detail/stock quantities consistent.

The persistence layer must also accept fractions. The quantity columns the importer writes — `purchase_details.quantity`, `sale_details.quantity`, `products.product_quantity`, `product_stocks` quantity/broken-quantity columns, and the `transactions` quantity snapshot columns — were `integer`, which MySQL/MariaDB silently truncate/round (SQLite tolerated the float in tests, masking the issue). A migration converts them to `decimal(15,3)` (three fractional digits for common weight units, ample integer headroom), and the corresponding model casts are `decimal:3` (replacing `Transaction`'s `integer` quantity casts) so reads return the stored fraction. This revises the "do not add a new database column or migration" non-goal: no new column is added, but an alter-column migration is now required for correctness on production databases.

The migration restates each known column's nullability and default, because a bare `->change()` resets attributes that are not restated (on MySQL/MariaDB this would drop an existing default). In particular `products.product_quantity` carries a `default(0)` from an earlier migration, which is preserved. MySQL/MariaDB uses raw `ALTER TABLE ... MODIFY` statements rather than Doctrine introspection because `doctrine/dbal` is a dev dependency and production installs may not have the Doctrine driver classes available during `php artisan migrate`.

### Decision 4: Allocate payment pro-rata by owner document total

When a source invoice splits into multiple positive-total owner documents, allocate paid and outstanding amounts by each owner group's adjusted total divided by the full source invoice adjusted total. Round to cents and assign any final rounding remainder to the largest positive-total group.

Rationale: pro-rata allocation preserves the source paid/due ratio and avoids arbitrary payment ordering.

Alternative considered: pay groups sequentially until the source paid amount is exhausted. This is simple but creates owner-specific balances based on row order rather than source accounting data.

**Per-owner consistency (single settlement allocator):** An owner document's total has three settlement components — cash `Pembayaran`, the non-cash `Jumlah Pemotongan` credit, and the outstanding due. These must satisfy `cash + deduction + due == group_total` per owner with every component non-negative, while the invoice sums equal the source `paid`, `deduction`, and `outstanding`.

Allocating two components independently and deriving the third by subtraction is unsafe: two independently-rounded components can over-settle a tiny group so the derived third goes negative (e.g. group totals `[0.14, 14.14]` with cash `10.71` and deduction `3.57` gave the small group cash `0.11`, deduction `0.04`, due `-0.01`). It also drifts when all three are rounded independently (e.g. a `22.33` group receiving components summing to `22.35`).

`ImportSettlementAllocator` resolves both by deriving the components from a shared pro-rata base:
1. allocate `due` pro-rata by group total (`due_g ≤ group_total` because `outstanding ≤ sum(group_total)`);
2. `settled_g = group_total − due_g` (≥ 0);
3. allocate `cash` pro-rata by `settled_g` (`cash_g ≤ settled_g` because `paid ≤ sum(settled) = paid + deduction`);
4. `deduction_g = settled_g − cash_g` (≥ 0).

Each step's rounding remainder goes to the largest-weight group, which has the most headroom, so no group is pushed negative. This guarantees the per-owner invariant and non-negativity, and the chained sums give `sum(due) == outstanding`, `sum(cash) == paid`, `sum(deduction) == deduction`. It replaced both the per-component `ImportDocumentAdjustmentAllocator` usage for settlement and the earlier `ImportPaymentAllocator`; `ImportDocumentAdjustmentAllocator` remains only for document discount/shipping allocation.

The internal pro-rata helper distinguishes a true zero from a legitimate one-cent value using a sub-cent epsilon (`0.005`), not a one-cent tolerance. A one-cent tolerance wrongly skipped `0.01` weights and amounts: a fully cash-paid `0.01` group with no deduction was settled as `deduction = 0.01` instead of `cash = 0.01`, and groups `[0.01, 1.00]` with cash `1.01` produced a spurious `0.01` deduction (a `POTONGAN` row for an invoice with no source deduction, with active payments exceeding the document total). Because money is two-decimal, a rounded value is positive exactly when it exceeds half a cent.

### Decision 4b: Allocate document-level discount and shipping pro-rata, not per group

`Diskon` and `Biaya Pengiriman` are repeated on every source row but represent a single invoice-level amount. They must be resolved once at source-invoice scope and then allocated across owner groups pro-rata by each group's gross line total (line totals plus tax, before adjustment), with the two-decimal rounding remainder assigned to the largest positive-total group. Each owner group's adjusted total is `grossTotal - allocatedDiscount + allocatedShipping`, and the source invoice adjusted total is the sum of those.

Rationale: subtracting the full document discount/shipping inside each owner group (as the first implementation did) double-counts the adjustment on a split-owner invoice. For two equal groups with line totals 100000 + 100000 and a repeated Diskon of 15000, the per-group approach yielded (100000 - 15000) + (100000 - 15000) = 170000 and falsely rejected a valid source Total of 185000. Pro-rata allocation gives 92500 + 92500 = 185000 and persists each owner header's `discount_amount`/`shipping_amount` as its allocated share, so the summed headers reconcile back to the source values. A single-positive-group invoice receives the whole amount, preserving prior single-owner behavior. This is implemented in `App\Support\ImportDocumentAdjustmentAllocator`, mirroring `ImportPaymentAllocator`.

Alternative considered: apply the full document discount/shipping to each owner group. Rejected — it is the double-counting failure mode this decision fixes.

Zero-gross fallback: when every owner group's gross line total is zero there is no positive weight to allocate by, but a single owner group can still legitimately carry a document-level amount — e.g. invoice `JL00158527` has zero-priced lines and only `Biaya Pengiriman = 4000` (source `Total = 4000`). Allocating zero would leave the calculated total at `0` and falsely reject the invoice. So when the document amount is non-zero, all gross totals are zero, and there is exactly one owner group, the allocator assigns the full amount to that group; with multiple zero-gross groups the split is ambiguous, so it leaves all groups at zero rather than guess. The downstream settlement then weights by the now-positive group total (`gross 0 + shipping 4000 = 4000`), so a fully-paid such invoice imports as paid with zero due.

### Decision 4c: Model Jumlah Pemotongan as a non-cash settlement credit

Some source invoices record a `Jumlah Pemotongan` (settlement reduction/credit) separately from the cash `Pembayaran`. For these the source reconciles as `Pembayaran + Jumlah Pemotongan + outstanding == Total`, not `Pembayaran + outstanding == Total`. The first reconciliation model rejected such valid invoices (e.g. purchase `2009DPS227/T0248`: Total 17,876,755.50, Pembayaran 15,176,755.50, Pemotongan 2,700,000, outstanding 0).

`Jumlah Pemotongan` is mapped through the upload controllers and staging jobs (alias `jumlah pemotongan` → `jumlah_pemotongan`) and modeled in `ImportPaymentSummaryResolver`, which now returns a `deduction_amount` and reconciles against `paid + deduction + outstanding == total`. On the generated document the deduction is treated as a non-cash credit: the header `paid_amount = cash Pembayaran + deduction` so `paid + due = total`. The cash payment row records the `Pembayaran` amount only; the deduction is persisted as a **separate non-cash payment row** (see the import/report bridge below) so it is never recorded as cash received. On split-owner invoices the cash, deduction, and due components are split together by `ImportSettlementAllocator`, so summed owner documents still reconcile (see Decision 4 for the per-owner allocation guarantee).

Rationale: the deduction is a real settlement of the invoice but not cash received, so it must close the reconciliation gap without inflating recorded cash. No new column/migration is added.

Alternative considered: fold the deduction into the cash payment row amount. Rejected — it overstates cash actually received. Alternative considered: subtract the deduction from the document total. Rejected — it loses the original invoice total and conflates a settlement credit with a price discount.

**Import/report bridge:** Purchase reports derive "paid" from active payment rows whenever any exist (`PurchaseReportQueryService::effectivePaidExpression`), ignoring the header `paid_amount`. A deducted invoice with only a cash payment row would therefore report as partially paid with the deduction shown as outstanding. To keep reports consistent without overstating cash, the deduction is persisted as a **second active payment row** using a dedicated non-cash payment method (`POTONGAN`, `is_cash = false`, resolved or created via `ImportPaymentSummaryResolver::resolveDeductionPaymentMethod`, reusing the cash method's chart of account to satisfy the required `coa_id`). Reports then sum cash + credit to the full total (Lunas, zero outstanding) while payment-method breakdowns keep the credit distinguishable from cash. This intentionally revises the earlier "do not infer multiple payment methods" non-goal for the single deduction-credit method only; no schema change is required.

**Current-status paid fallback:** Some historical source rows show `Status Hari Ini = Lunas` and `Sisa Tagihan Hari Ini = 0`, but still repeat the original `Sisa Tagihan = Total` and `Pembayaran = 0`. In that shape, `Status Hari Ini` and today's outstanding balance represent the current state; the old `Sisa Tagihan` value must not force the import to create an unpaid document. The importers map `Status Hari Ini` as `status_hari_ini`, and `ImportPaymentSummaryResolver` treats `Lunas`/`Paid` with zero current outstanding as fully paid by inferring cash paid from the calculated document total minus any deduction credit.

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
