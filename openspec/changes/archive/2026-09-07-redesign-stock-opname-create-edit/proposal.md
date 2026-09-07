## Why

Stock opname create/edit currently has an unreliable location selector and a product payload mismatch that causes a 500 error. Operators need to count good and bad stock through barcode and serial scanning, review differences against existing stock, and save the proposed counts without changing inventory before approval.

## What Changes

- Reuse purchase receiving's searchable standard-location dropdown and correct edit location persistence and validation restoration.
- Replace the inline adjustment product search with a POS-style main scan field, tokenized search dialog, compact product rows, and per-row serial dialog.
- Initialize search-added products at zero; increment ordinary products by one for product barcodes or by the conversion factor for conversion barcodes, in base units.
- Count serialized products exclusively from serial entries; resolve existing serials from the main field and allow unregistered serial text in product-specific row entry.
- Prevent duplicate product/serial pairs across good and bad counts while allowing identical serial text on different products; present ambiguous scans for explicit selection.
- Add a Good/Bad entry toggle, per-condition counts, and existing-versus-proposed comparisons. Derive tax buckets from the selected location's setting PKP status.
- Persist versioned pending count proposals, including unknown serials and condition assignments, without inventory mutations. Preserve existing records and block new-format documents from incompatible legacy approval until the separate approval change is implemented.
- Limit verification to focused automated checks and human browser verification; no full-suite or automated browser testing.

## Capabilities

### New Capabilities
- `stock-opname-count-drafts`: Create/edit location-based physical counts, scanning, condition allocation, serial entry, comparisons, persistence, and legacy approval isolation.

### Modified Capabilities
- `adjustment-product-selection`: Adjustment search becomes dialog-based with zero initial counts and existing-row focus, while breakage and purchase selection behavior remains compatible.

## Impact

- Adjustment create/edit Blade forms, Livewire adjustment components, controller validation/persistence, additive module migrations, and focused tests.
- Reuse location dropdown, Product globalSearch, and POS interaction patterns; introduce adjustment-specific scan resolution rather than inheriting sale eligibility checks.
- Existing adjustment detail storage only models tax/non-tax counts and existing serial IDs; a versioned draft payload must represent both conditions and raw serial text.
- Approval reconciliation, serial creation/movement, shortages, and approval-time stock drift handling are deferred. A minimal server-side compatibility guard is included to prevent premature legacy approval of new-format documents.
- No changes to POS, purchase, or separate breakage business workflows and no live inventory writes from create/edit.
