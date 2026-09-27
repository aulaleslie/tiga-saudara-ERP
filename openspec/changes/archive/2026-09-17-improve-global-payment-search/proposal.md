## Why

Global purchase- and sales-payment searches currently treat the full search text as one partial value and resolve product identity from transaction-detail snapshots. This makes multi-word searches brittle, allows renamed products to become undiscoverable by their current catalog name, and provides no reliable transaction lookup by exact barcode or serial number.

## What Changes

- Make non-identity fields in standalone and party-embedded global-payment workspaces use product-list-style tokenized search: every whitespace-delimited token must match at least one supported partial-search field.
- Resolve searchable product names and product codes from the current linked `products` row rather than persisted purchase or sale detail snapshots.
- Add whole-input, trimmed, case-insensitive exact matching for product barcodes and serial numbers; barcode and serial values do not participate in partial token matching.
- Support both primary product barcodes and product-unit-conversion barcodes as exact product identities.
- Preserve existing global-payment eligibility, business/date/card/party filters, sorting, pagination, authorization, and payment behavior.
- Add focused purchase and sales Livewire/query tests; no full-suite verification is required for this change.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-purchase-multi-payment`: Define tokenized text search, current-product catalog lookup, and exact barcode/serial lookup for standalone and supplier-embedded global purchase-payment results.
- `global-sales-multi-payment`: Define tokenized text search, current-product catalog lookup, and exact barcode/serial lookup for standalone and customer-embedded global sales-payment results.

## Impact

- Affects the global-mode search branches of `App\Livewire\Purchase\PurchaseTable` and `App\Livewire\Sale\SaleTable`, plus reusable query support introduced for consistent semantics.
- Reads current catalog data from `products` and `product_unit_conversions`, purchase serial lineage through receiving-detail serial associations, and sales serial lineage through dispatched transaction evidence.
- Does not change transaction snapshots, payment allocation services, routes, permissions, or external APIs.
- Existing snapshot-based list-search tests will need focused adjustment or separation from the new global-mode behavior.
