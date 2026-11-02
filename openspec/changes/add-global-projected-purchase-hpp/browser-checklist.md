# Human browser validation checklist

**Status:** Pending human review. No browser automation was run for this change.

Use an authorized test account and a product with approved outstanding purchases across at least two businesses.

- [ ] On the multi-business price page, confirm `Proyeksi HPP` is beside `Harga Beli Rata-rata`, shows whole-Rupiah formatting, and repeats the same value for each business row.
- [ ] Hover the projection heading and confirm the tooltip is exactly: `Estimasi HPP jika seluruh sisa pembelian yang disetujui diterima sekarang.`
- [ ] Enter price edit mode, cancel, and save ordinary price changes; confirm the projection remains read-only and stored prices/HPP/stock are unchanged.
- [ ] Activate `Lihat pembelian terkait ↗`; confirm it opens a new tab and does not change the active business session.
- [ ] Confirm the source list includes contributing purchases from multiple businesses, shows its product/scope label, and excludes approved purchases that no longer have an outstanding line for the selected product.
- [ ] Open an approved source purchase from the list; confirm its detail is readable and the return link keeps the product projection scope.
- [ ] Use a product with no remaining source purchases; confirm the empty state still displays the selected product and source-scope label.
- [ ] With price-management access but without `purchasePayments.global.access`, confirm the projection remains visible, the source link is hidden, and direct access to the filtered global workspace is denied.
- [ ] Confirm standalone and supplier-embedded global payment workspaces retain their normal payment summaries, eligibility, and actions.

## Human review record

- Reviewer:
- Date:
- Result:
- Notes:
