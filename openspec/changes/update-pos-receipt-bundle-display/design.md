## Context

POS transaction `1` demonstrates the current mismatch. The customer purchased a bundled parent product, but completed checkout split posting created two owner-specific Sales documents: the primary sale carries the parent residual and another sale carries the bundle component allocation with a non-billable `sale_bundle_items` row. The current receipt data builder prefers `PosTransactionLine` rows for completed checkouts and, when falling back to Sales data, only looks at the checkout's primary `sale_id`. As a result, bundle component composition can be persisted but invisible on receipt and transaction detail screens.

The receipt template also currently places `Harga sudah termasuk PPN` above the tail dash line and renders print history as `Terakhir dicetak oleh {user} pada {print time}` below the dash. Print/reprint logging is still required for audit, but the customer-facing receipt should show the POS transaction time instead of the latest reprint actor/time.

## Goals / Non-Goals

**Goals:**
- Render bundle component names and customer quantities under bundled parent rows on POS receipts and transaction detail pages.
- Support completed split checkouts where bundle component context lives in `checkoutSales.sale.saleDetails.bundleItems` rather than the checkout's primary sale.
- Keep component price, subtotal, allocation amount, source owner, and split Sales details hidden from customer-facing receipt and transaction detail composition rows.
- Move the combined PPN/company footer text below the dashed tail line in a very small font.
- Continue writing print/reprint logs unchanged while changing only what the receipt displays.
- Use the POS transaction/checkout date-time for footer date display, not the latest print/reprint timestamp.

**Non-Goals:**
- Do not change POS checkout posting, split allocation, stock movement, tax extraction, or `sale_bundle_items` persistence semantics.
- Do not backfill older POS transaction lines with missing `line_meta.bundle_items`.
- Do not expose bundle component allocation prices on receipts or transaction detail pages.
- Do not remove print/reprint audit tables or logging behavior.
- Do not change standard Sales print templates.

## Decisions

### 1. Build a receipt bundle composition map from all checkout Sales, then fall back to transaction metadata

For completed checkouts, receipt data should eager-load `checkoutSales.sale.saleDetails.bundleItems` and derive bundle components from every generated sale associated with the checkout. Components should be associated back to the displayed parent line by stable product/line context where possible:

```text
completed PosTransaction
  └─ completedCheckout
      ├─ transactions.lines                 -> displayed parent rows and customer totals
      └─ checkoutSales.sale.saleDetails
          └─ bundleItems                    -> composition rows, including split component sale
```

If a draft or loaded transaction has bundle data in `line_meta.bundle_items`, use that directly. If metadata is absent but the line only has `price_source = BUNDLE`, do not invent component rows from current bundle configuration for completed historical receipts unless no persisted checkout/sale composition exists. Persisted transaction/sale context should win over current product bundle configuration because bundle definitions can change after sale time.

Alternative considered: Reconstruct bundle components from `product_bundles` by parent product on every receipt. Rejected as the primary strategy because historical receipts should reflect the sold bundle, not today's configuration.

### 2. Keep composition rows nested and non-monetary

Receipt lines should remain parent rows for totals. Bundle components should render as subordinate rows or nested text under the parent product name with quantity only. The right-hand total column should remain blank for component rows.

Example:

```text
2  ACER ASPIRE LITE...              14.000.000
   - MOUSE USB VOTRE KM-309 x2
```

Alternative considered: Add component rows as normal receipt item rows with zero totals. Rejected because that makes components look like separately scanned sale lines and can confuse totals.

### 3. Use transaction/checkout time as customer-facing footer time

For completed transactions, use `completedCheckout.finalized_at` when available; otherwise use `PosTransaction.created_at`. For draft/loaded transactions, use `PosTransaction.created_at`. Print log timestamps remain stored and retrievable for audit, but the receipt footer should not display latest printer identity or latest print timestamp.

Alternative considered: Keep showing the latest print timestamp but remove the user name. Rejected because the user specifically wants the POS transaction date, not reprint date.

### 4. Separate audit data from receipt presentation

`PosReceiptService::logTransactionPrint()` should continue to create PRINT and REPRINT rows. Receipt data may still include print history for internal use if existing tests or screens depend on it, but the thermal receipt template should not render `Terakhir dicetak oleh ...`.

Alternative considered: Stop loading print history for receipt rendering. Rejected because it increases regression risk and is unnecessary; the presentation layer can simply avoid rendering audit wording.

### 5. Enrich transaction detail using the same composition source

The POS transaction detail page should not duplicate complex lookup logic in Blade. The controller or a small service method should provide bundle composition per transaction line, so the view only renders nested component names and quantities.

Alternative considered: Query `sale_bundle_items` directly from the Blade view. Rejected because view-level database access would be harder to test and would duplicate receipt behavior.

## Risks / Trade-offs

- [Ambiguous mapping from split sale detail back to transaction line] -> Prefer existing transaction line metadata and sale detail parent product/quantity matching; when ambiguity remains, group components under the matching parent product rather than dropping persisted composition.
- [Historical completed transactions before bundle persistence may still lack components] -> Do not fabricate data unless persisted context is unavailable and current bundle configuration is an explicit fallback for draft/loaded transactions.
- [Receipt width overflow on long component names] -> Use nested text that wraps within the existing thermal width and omit price columns for component rows.
- [Existing tests expect print history wording] -> Update tests to assert logging persists while receipt content changes to transaction date/time.
- [Split checkout multiple sales can duplicate component rows] -> Aggregate bundle component quantities by parent line, bundle item/product, and display name before rendering.

## Migration Plan

This is a code-only presentation and data assembly change. No database migration is required.

Deploy by updating receipt data assembly, receipt Blade, transaction detail data assembly/view, and regression tests. Rollback is a code rollback; existing checkout, sale, sale bundle item, and print log records remain compatible.

## Open Questions

- Should receipt component labels include a prefix such as `Bonus:` or only the component name and quantity? The safest implementation is neutral composition text with no price wording.
- Should the footer date line be displayed for first prints as well as reprints? The requirement reads as receipt information, so it should be consistent across print and reprint.
