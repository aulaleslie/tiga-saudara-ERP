---
id: ticket-3
title: Cart Row Operations
status: queued
depends_on:
  - ticket-2
source_docs:
  - docs/pos/current-pos-supported-brainstorm.md
  - docs/pos/expected-ui-component-checklist.md
allowed_paths:
  - Modules/Pos/Resources/views/sell.blade.php
  - Modules/Pos/Services/PosCartService.php
  - docs/pos/ai/ticket-status.md
test_commands:
  - php artisan test --filter POSCartTotalsDisplayTest
done_when:
  - Item rows show expected columns: PLU/Nama Barang, Harga, QTY, Total.
  - Quantity update works using row controls (`- / +` or equivalent) via existing API.
  - Cart totals section reflects latest snapshot values correctly.
---

## Objective

Implement cashier-friendly row operations in the item table using current cart endpoints.

## Scope

1. Row render for line items.
2. Quantity update action wiring.
3. Row remove and discount save actions.
4. Total block updates from cart snapshot.

## Out of Scope

1. Split cart/pending cart storage.
2. Promo/sponsor calculations.
3. New tax model changes.

## Implementation Steps

1. Map row controls to existing line update/delete endpoints.
2. Keep deterministic totals display from snapshot response.
3. Preserve existing error/success status messaging pattern.

## Validation Notes

1. Cart mutation must not create sales/payment posting before finalize.
2. Empty cart state must render correctly.
