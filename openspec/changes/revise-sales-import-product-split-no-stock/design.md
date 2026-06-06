## Context

Sales import currently resolves non-Daizu ownership from an effective owner rule: Daizu product, then mapped CSV `Tag`, then product-name marker. That differs from the desired historical-sales import model where product names are the owner signal and tags are audit metadata only.

The sales importer also creates a dispatched sale and immediately decrements inventory through `ProductStock`, `Product.product_quantity`, and `Transaction` records. Future imported sales are historical documents that are already dispatched in the source system, so the ERP should keep dispatch paperwork but must not consume stock again.

Purchase import has newer settlement behavior that treats CSV `Total` and `Status Hari Ini` as authoritative. Sales import still has stricter calculated-total reconciliation and less complete status mapping. This change brings sales import settlement behavior in line with purchase import while keeping scope limited to sales import.

## Goals / Non-Goals

**Goals:**

- Make sales import ownership deterministic from product name: Daizu/Kedelai first, then `*`, then ` TP`, then `PERDANA`.
- Preserve CSV `Tag` as metadata without using it for ownership, grouping, duplicate checks, stock-owner resolution, or payment split decisions.
- Allow one source sales invoice to create multiple `DISPATCHED` sales when rows resolve to different owners.
- Use CSV `Total` and `Status Hari Ini` as authoritative sales import settlement inputs and allocate settlement across owner groups.
- Keep dispatch and dispatch-detail records for imported sales while preventing inventory quantity and transaction-log mutation.
- Apply sales import tax only when the resolved owner setting is PKP.

**Non-Goals:**

- Do not change manual sales creation, manual sales dispatch, POS checkout, or POS return behavior.
- Do not rewrite historical imported sales, dispatches, stock records, or inventory transactions.
- Do not remove tag syncing or tag display features.
- Do not add database schema.
- Do not change purchase import behavior except by reusing existing status-mapping semantics as the model for sales import.

## Decisions

### Decision 1: Resolve imported sales ownership from product name only

Sales import should use one owner resolver for grouping, document `setting_id`, duplicate lookup, ProductPrice owner, dispatch location owner, and any remaining stock-owner lookup needed for dispatch-detail location:

```text
if product name contains whole-word KEDELE, KEDELAI, or RAGI:
    owner = Daizu Kedelai
else if product name starts with "*":
    owner = CV TIGA NUSA COMPUTER
else if product name ends with " TP":
    owner = CV TOP IT INTERNUSA
else:
    owner = PERDANA
```

CSV `Tag` remains synced onto generated sales after owner grouping is resolved.

Alternative considered: keep mapped `Tag` before marker fallback. This preserves current behavior, but it contradicts the desired product-name split and can prevent one invoice with `*`, `TP`, and unmarked products from creating the intended owner documents.

### Decision 2: Use source invoice scope before owner document creation

Rows should first be grouped by source invoice number and product-name owner. The importer should calculate each owner group's tax-gated gross total, resolve document-level discount/shipping once at source-invoice scope, reconcile to CSV `Total`, then allocate paid, deduction, and due amounts across owner groups.

Sales import should adopt purchase import's status mapping:

- `Lunas` / `Paid`: paid equals authoritative total, due is zero.
- `Belum Dibayar` / `Belum Lunas`: paid is zero, due equals authoritative total.
- `Terbayar Sebagian`: paid comes from `Pembayaran`, due is the remaining total.
- `Lewat Jatuh Tempo`: partial when `Pembayaran` is positive, otherwise unpaid.

Alternative considered: keep sales import strict calculated-total reconciliation. That is safer for line-total fidelity, but it rejects historical documents where source `Total` is the accounting authority and conflicts with the requested purchase-import parity.

### Decision 3: Keep imported sale status dispatched while decoupling inventory mutation

Imported sales should continue to persist `Sale::STATUS_DISPATCHED` and create `dispatches` plus `dispatch_details`, because the source documents are already dispatched and existing sales UI/reporting expects dispatched sales. The import dispatch path must skip:

- `product_stocks.quantity`, `quantity_tax`, and `quantity_non_tax` decrement
- `products.product_quantity` decrement
- `transactions` creation

Alternative considered: skip dispatch records entirely. That would more strongly represent "no ERP dispatch occurred", but it would make imported sales look less complete in existing sales/dispatch reporting and contradict the chosen "all sales imported already dispatched" status model.

### Decision 4: Gate imported sales tax by resolved owner PKP status

For each sales import row, tax is allowed only if the resolved owner setting has `is_pkp = true`. When the resolved owner is non-PKP, the importer must ignore CSV `pajak` and `tarif_pajak`, set line `tax_id` to null, set line `product_tax_amount` to zero, and compute header tax as zero for that owner document.

With current company setup this means `*` rows under `CV TIGA NUSA COMPUTER` can be taxable, while `TP` and unmarked rows under TOP IT/PERDANA are non-tax.

Alternative considered: preserve CSV tax values regardless of owner. That can leak repeated source tax into non-PKP owner documents and make proportional owner totals differ from the persisted sale details.

## Risks / Trade-offs

- Existing tag-priority sales import tests will fail → Update them to assert product-name routing and tag metadata preservation.
- Existing stock decrement tests for sales import will fail → Replace with assertions that dispatch records exist but stock quantities and inventory transactions do not change.
- CSV `Total` authority can hide line-total drift → Keep explicit tests proving adjustments are allocated and persisted on owner headers, and keep row-level details unchanged apart from PKP tax gating.
- Non-PKP tax suppression changes owner group totals → Calculate owner totals from the same tax-gated values that will be persisted to avoid payment allocation drift.
- Payment status capitalization may differ from older sales records → Use the current import/payment conventions consistently in new sales import tests.

## Migration Plan

- Add focused tests for sales import owner routing, split invoice settlement, status mapping, tax gating, and dispatch-without-stock mutation.
- Update sales import owner resolver and grouping before changing settlement creation.
- Update sales import payment summary usage to the purchase-style authoritative status path or an equivalent sales-specific method.
- Refactor sales import dispatch creation so dispatch details can be created without inventory mutation.
- Run focused sales import tests, then import payment and split-owner tests.

Rollback is code-only: restore tag-priority ownership, strict sales total reconciliation, and stock-mutating dispatch behavior. No schema or historical data migration is involved.

## Open Questions

None. The selected behavior is product-name owner routing, tag metadata preservation, source `Total` authority, `DISPATCHED` imported sales, dispatch records without stock mutation, and owner PKP-gated tax.
