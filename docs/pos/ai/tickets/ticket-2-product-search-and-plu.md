---
id: ticket-2
title: Product Search and PLU
status: queued
depends_on:
  - ticket-1
source_docs:
  - docs/pos/current-pos-supported-brainstorm.md
  - docs/pos/expected-ui-component-checklist.md
allowed_paths:
  - Modules/Pos/Resources/views/sell.blade.php
  - Modules/Pos/Http/Controllers/PosSellController.php
  - Modules/Pos/Services/PosProductSearchService.php
  - docs/pos/ai/ticket-status.md
test_commands:
  - php artisan test --filter POSProductSearchScanTest
done_when:
  - Search input supports barcode/SKU/name flow in cashier UI.
  - Exact barcode can auto-add behavior remains working.
  - PLU list presentation is available from current search API results.
---

## Objective

Implement the product lookup and PLU interaction layer on top of the new shell.

## Scope

1. Ensure search input and result list are prominently usable.
2. Keep exact barcode auto-select/add behavior.
3. Provide `List PLU` interaction using existing search endpoint data.

## Out of Scope

1. Cart line editing behavior.
2. Payment flow changes.
3. Serial assignment UI.

## Implementation Steps

1. Wire search field and PLU action to existing endpoints.
2. Keep request debounce and current JSON contract.
3. Confirm no regression on exact-match scan behavior.

## Validation Notes

1. Search should fail gracefully and show clear status text.
2. Search must remain scoped to active setting/sales locations.
