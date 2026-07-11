## Why

Initializing or correcting product barcodes currently requires users to open and submit the full product form, while the existing barcode screen only previews and prints barcodes that have already been saved. Users need a focused, scanner-friendly workflow so they can assign barcodes across the catalog quickly without risking unrelated product, pricing, stock, or unit data.

## What Changes

- Add a dedicated product barcode initialization workspace that searches products by name or product code and prioritizes products without a base-unit barcode.
- Automatically focus and visually highlight the barcode input after product selection so a hardware scanner can be used without extra clicks.
- Capture the scanned value without saving, immediately show the candidate value and a rendered barcode preview, and require an explicit confirmation before persistence.
- Return focus to product search after a successful save so users can repeat the workflow efficiently across many products.
- Support both first-time assignment and replacement of an existing base-unit barcode, with stronger old-versus-new confirmation for replacements.
- Preserve scanned barcode values as strings, including leading zeroes, and validate them against both product and unit-conversion barcode assignments before saving.
- Add barcode-specific authorization and auditable assignment history so barcode operators do not require access to unrelated product editing capabilities.
- Provide clear duplicate, invalid, stale-selection, and concurrent-update feedback while retaining the selected product for correction and rescan.

## Capabilities

### New Capabilities

- `product-barcode-initialization`: Scanner-first search, preview, confirmation, validated assignment/replacement, authorization, audit history, and repeat-workflow behavior for product base-unit barcodes.

### Modified Capabilities

None.

## Impact

- Product module routes, navigation, Livewire components, Blade views, validation/services, permissions, and focused tests.
- Product barcode persistence and product unit-conversion barcode lookup rules.
- A new audit/history persistence mechanism for barcode assignments and replacements.
- Existing POS barcode resolution and barcode printing remain consumers of the stored product barcode; their current cart and scanner behavior is not otherwise changed.
- No historical product, POS transaction, sale, stock, price, or unit-conversion records are rewritten.
