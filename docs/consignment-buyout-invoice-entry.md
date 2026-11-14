# Consignment buyout: invoice entry (tab 3) and draft editing

## Tab 3: one financial row per product

The buyout wizard keeps its three steps. Step 2 still selects goods per receipt, sold/unsold kind, serial and location.
Step 3 combines every selected occurrence of a product into **one invoice row**:

| Control | Meaning |
| --- | --- |
| Unit price | One price for the product across all receipts. Empty means "use the receipt price", which is only possible when every contributing receipt has the same price. Differing receipt prices require an explicit price or final total; `0` is a deliberate zero. |
| Discount / unit | Fixed amount or percentage, same meaning as consignment billing. |
| Tax (PKP only) | One invoice tax for the product. "Ikuti penerimaan" uses the shared receipt tax; when receipts disagree a tax (or "Tanpa pajak") must be chosen. Non-PKP businesses never carry tax, whatever is submitted. |
| Final line total | Editing it makes it authoritative and back-solves the displayed unit price. Editing price, discount or tax clears it. |

Expanding a row shows its read-only sources: receipt, sold/unsold, quantity, serials, location, owner/destination and original
receipt price. The document discount, invoice number, invoice date (Purchase date), reporting date, payment term / due date
(manual due date survives invoice-date changes), tax reference, notes and attachments (PDF/JPG/PNG/WEBP, 10 MB, max 10) are
captured in the same step. Totals come from the server preview (`POST consignments/buyouts/preview`), which uses the same
calculation as draft save, approval and finalization; responses older than the latest input are discarded.

**Simpan Draft** only saves a draft. Invalid terms or files reject the whole save (no draft, no stored file).

## Data

- `pricing_intent` (version 2): `products[<product id>] => {unit_price, discount_type, discount_value, tax_id, row_total_override}`,
  `global_discount_type`, `global_discount_value`, `is_tax_included`.
- The product row is calculated once with `ConsignmentBillingPricingCalculator`; its total and tax are allocated to the
  source slices (buyout lines) by quantity with deterministic residual-cent assignment, so slice amounts sum exactly to the row.
- Draft files live in the buyout's `invoice_attachments` media collection and are referenced (id + sha256) from
  `invoice_metadata.attachments`, so approval freezes them with the other terms. Finalization copies them to the Purchase.

## Editing drafts

DRAFT and REJECTED buyouts show **Ubah Draft** (`consignments.buyouts.edit`), which reopens the same wizard on step 3 with saved
destinations, selections, product terms and invoice data. Saving uses the existing revision PUT: a REJECTED document returns to
DRAFT with its revision incremented; stale revisions, other businesses, approved documents and users without
`consignments.buyouts.edit` are refused. Saved goods that are no longer offered are listed and must be acknowledged; they are
never silently re-selected or substituted.

## Finalization and generated Purchases

Product-intent documents create **one Purchase detail per product**, with the approved row's price, discount and tax. Every
source slice keeps its own lineage row (original amount, accepted amount, inventory cost) linked to that detail. Only unsold
slices feed the acquisition cost; sold slices move no stock.

Consignment-billing and buyout Purchases can be opened in the standard **Ubah Nilai (Moneter)** editor (normal and global),
under the usual `purchases.update` + `purchases.received.monetary.edit` rules and payment-floor check. Only prices, discounts,
tax and charges change; products, quantities, rows, supplier, source evidence, stock, serials and acquisition cost stay as they
were. Full editing, receiving, returns, deletion and reversal eligibility are unchanged.

## Legacy behaviour

- Buyouts approved before this change keep their legacy per-line intent, approval hash and accepted amounts, and finalize with
  the previous commercial-term grouping (possibly several details per product).
- Historical Purchases are never regrouped or repriced automatically.
- Reopening an older draft whose receipts disagree on price requires one explicit product price or total before saving.
