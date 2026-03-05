---
id: ticket-1
title: POS Shell Layout
status: ready
depends_on: []
source_docs:
  - docs/pos/current-pos-supported-brainstorm.md
  - docs/pos/expected-ui-component-checklist.md
allowed_paths:
  - Modules/Pos/Resources/views/sell.blade.php
  - docs/pos/ai/ticket-status.md
test_commands:
  - php artisan test --filter POSShellSessionGuardTest
  - php artisan test --filter POSNavigationMenuVisibilityTest
done_when:
  - POS sell page has clear shell sections: top strip, cart area, payment area, shortcut bar.
  - Theme-adaptive classes are used (no fixed hardcoded color theme requirement).
  - No ecommerce section is rendered as active functionality.
---

## Objective

Create the base POS page structure that matches the target visual direction and keeps existing POS flow intact.

## Scope

1. Restructure `sell.blade.php` layout into stable sections.
2. Keep existing IDs/endpoints so JS flow remains functional.
3. Ensure structure supports future ticket additions without rewrites.

## Out of Scope

1. Product search logic changes.
2. Cart row behavior changes.
3. Payment method flow changes.

## Implementation Steps

1. Reorganize markup into top strip, main list, right summary, and bottom actions.
2. Keep all existing critical DOM bindings used by current script.
3. Run tests listed above.

## Validation Notes

1. `/pos/sell` loads without console/runtime errors.
2. Active session context still required.
