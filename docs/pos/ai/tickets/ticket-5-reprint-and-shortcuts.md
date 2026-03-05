---
id: ticket-5
title: Reprint and Cashier Shortcuts
status: queued
depends_on:
  - ticket-4
source_docs:
  - docs/pos/current-pos-supported-brainstorm.md
  - docs/pos/expected-ui-component-checklist.md
allowed_paths:
  - Modules/Pos/Resources/views/sell.blade.php
  - Modules/Pos/Resources/views/receipt.blade.php
  - docs/pos/ai/ticket-status.md
test_commands:
  - php artisan test --filter POSReceiptGenerationTest
done_when:
  - Reprint shortcut is available and wired to existing receipt/reprint route.
  - Shortcut bar exposes only supported actions.
  - Unsupported actions are hidden or marked clearly as not available.
---

## Objective

Implement cashier shortcuts that map to existing backend capabilities, especially reprint.

## Scope

1. Add reprint action in bottom shortcuts.
2. Keep receipt view/reprint flow clear and reachable.
3. Add supported shortcut entries (e.g., Lap. Sales, Lainnya container entry).

## Out of Scope

1. Promo/sponsor shortcut behavior.
2. Pending cart restore.
3. Ecommerce action.

## Implementation Steps

1. Wire reprint shortcut to latest checkout receipt reprint path.
2. Keep success modal print action intact.
3. Ensure shortcuts do not advertise unsupported business flow as active.

## Validation Notes

1. Receipt print log and reprint log behavior must remain intact.
