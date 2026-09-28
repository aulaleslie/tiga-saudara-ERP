# Proposal

## Why

Live purchase approval treats average purchase price as one global product value by synchronizing it to every business, but the cross-business price page presents that value repeatedly and leaves it entirely unavailable for deliberate correction. Authorized users need one explicit, guarded action that communicates the global HPP impact and updates every business consistently without mixing the operation into the page's broader price editor.

## What Changes

- Add an `Ubah HPP` action beside the existing `Ubah` action on the cross-business product price page.
- Open a Bahasa Indonesia dialog showing the current average purchase price, a single Rupiah input, and warnings that the new value applies to every business, affects future sale HPP resolution, may later be recalculated by approved purchase receiving, and does not rewrite existing sale HPP snapshots.
- Keep average purchase price read-only in the per-business table and keep the existing general `Ubah` workflow from editing it.
- Submit the dialog through a dedicated, permission-protected update operation that validates a positive value for stock-managed products, rejects stale state and duplicate submission, synchronizes every business atomically, and redirects to the same page with refreshed values and feedback.
- Warn when the loaded per-business average values differ; a successful save deliberately normalizes them to the submitted global value.
- Record changed per-business average values under one auditable operation group without producing events for unchanged values.
- Use focused feature and rendering verification for this workflow; do not require a full application test-suite run.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-product-price-management`: Add the dedicated global HPP dialog, interaction isolation, validation, synchronization, concurrency, redirect, and feedback behavior while retaining the read-only table column.
- `manual-product-purchase-price-handling`: Permit an explicit global average-purchase-price correction only through the dedicated guarded workflow while leaving ordinary product price maintenance unable to modify it.
- `product-price-update-feed`: Treat a changed average purchase price from the dedicated global HPP workflow as an auditable grouped product-price update.

## Impact

- Affected product routes, cross-business price controller/request/service boundaries, and the cross-business pricing Blade page and JavaScript.
- Reuses the existing `products.manage_cross_business_prices` authorization and global average-price synchronization behavior.
- Updates existing `product_prices.average_purchase_price` rows for the selected product; no schema migration or historical sale-cost rewrite is required.
- Extends product price feed recording and visibility to the explicitly corrected average purchase price.
- Adds focused tests for authorization, modal rendering and warnings, validation, global synchronization, divergent-value normalization, stale-state rejection, transaction rollback, duplicate submission protection, redirect/feedback, and audit recording.
