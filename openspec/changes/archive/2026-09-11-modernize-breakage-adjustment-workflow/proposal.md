## Why

The breakage adjustment flow uses an older location, product, quantity, and serial-entry experience and does not give approvers a clear, durable explanation of the inventory effect they are authorizing. Breakage should provide the same efficient searchable/scannable entry capabilities as Stock Adjustment while enforcing its narrower rule: move available stock from good to broken at one location without changing tax classification or total physical quantity.

## What Changes

- Modernize breakage create and edit with the searchable standard-location dropdown, unified barcode/serial scan input, conversion-barcode support, product search dialog, ambiguity feedback, and scanner-focus behavior established by Stock Adjustment.
- Replace separate editable tax and non-tax breakage quantities with one base-unit breakage quantity; allocate it exclusively to the selected location setting's PKP-governed bucket.
- Restrict serialized breakage to existing sellable/good serials for the selected product and selected location; reject unavailable, dispatched, returning, already-broken, wrong-product, wrong-location, unknown, or duplicate serials.
- Preserve tax classification and location during breakage: approval changes only good stock to broken stock and marks selected serials broken.
- Validate availability during entry and submission, then authoritatively revalidate and lock all affected stock and serial rows during atomic approval. Any invalid line blocks the entire approval without partial mutation.
- Add a Bahasa Indonesia review experience that explains current good/broken stock, requested movement, projected post-approval stock, serial condition transitions, drift, and blocking conflicts.
- Persist an immutable result of successful approval so later views show what was actually applied rather than reconstructing history from current stock.
- Keep total physical product quantity unchanged; only good/sellable and broken condition quantities change.

## Capabilities

### New Capabilities
- `breakage-adjustment-workflow`: Defines breakage entry, PKP-aligned condition-only movement, serial eligibility, approval review, atomic posting, and immutable audit behavior.

### Modified Capabilities
- `adjustment-product-selection`: Extends breakage create and edit from the legacy embedded selector to the searchable/scannable product-entry capability while retaining location and duplicate-row guards.

## Impact

- Affects breakage routes and lifecycle behavior in `Modules/Adjustment`, including create, edit, store, update, show, approve, and reject paths.
- Reworks the breakage Livewire table and Blade views and reuses or extracts established Stock Adjustment location-search, product-search, barcode resolution, ambiguity, and focus patterns.
- Uses the existing `Setting::is_pkp`, location-scoped `ProductStock` good/broken buckets, `ProductSerialNumber` availability/condition model, inventory transactions, notifications, permissions, and idempotency protections.
- Requires additive persistence for immutable breakage approval results if the existing adjustment schema cannot hold the required audit snapshot.
- Verification is limited to focused automated tests for affected breakage behavior; end-to-end browser verification will be performed manually.
