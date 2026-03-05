---
id: ticket-4
title: Payment Slide (No Numpad)
status: queued
depends_on:
  - ticket-3
source_docs:
  - docs/pos/current-pos-supported-brainstorm.md
  - docs/pos/expected-ui-component-checklist.md
allowed_paths:
  - Modules/Pos/Resources/views/sell.blade.php
  - Modules/Pos/Http/Requests/StorePosCheckoutFinalizeRequest.php
  - docs/pos/ai/ticket-status.md
test_commands:
  - php artisan test --filter POSPaymentValidationRulesTest
  - php artisan test --filter POSReceiptGenerationTest
done_when:
  - Payment slide supports cash/transfer/qris flow using current finalize endpoint.
  - Receipt preview area is present in payment UI composition.
  - On-screen numeric keypad is not implemented.
  - Quick amount presets are optional and can fill amount paid.
---

## Objective

Build payment-stage UI aligned to the target slide while keeping current backend payment rules.

## Scope

1. Payment method selector for current supported methods.
2. Amount paid input and change display for cash.
3. Required payment reference for non-cash.
4. Receipt preview block in payment stage.

## Out of Scope

1. On-screen numpad implementation.
2. Split payment support.
3. New payment methods outside current model.

## Implementation Steps

1. Keep modal/slide submit path to `pos.sell.checkout.finalize`.
2. Add/keep amount presets as fast-fill controls.
3. Ensure non-cash reference is enforced in UI and backend validation still passes.

## Validation Notes

1. Cash overpay should compute change.
2. Transfer/QRIS without reference must be rejected.
