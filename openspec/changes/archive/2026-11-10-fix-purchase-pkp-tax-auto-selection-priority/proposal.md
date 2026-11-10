# Proposal

## Why

In PKP purchase creation, tax auto-selection currently checks product purchase tax before default tax, and if no default tax is found, it automatically falls back to the first available tax in the system (introduced in commit fc89eaff). This causes unintended tax auto-assignments when businesses have no default tax marked, and breaks the intended business priority where global default tax takes precedence over product-level tax, and unresolved lines remain null until manually selected by the user.

## What Changes

- Update `ProductCart::resolvePreferredPkpAutoTaxId()` to prioritize:
  1. Business default tax (`is_default = true`)
  2. else product's configured purchase tax (`ProductPrice.purchase_tax_id` for active setting, or product payload)
  3. else `null` (no arbitrary fallback to first tax)
- Remove the first-available-tax fallback in `ProductCart::resolveDefaultTaxId()`. If no tax has `is_default = true`, it returns `null`.
- When `is_pkp = false`, tax remains null.
- Ensure store validation remains intact: PKP purchases require tax on all lines before submission.

## Capabilities

### Modified Capabilities
- `purchase-creation`: Update line tax resolution policy for PKP purchases to prioritize default tax first, then product purchase tax, and leave unresolved taxes as null rather than falling back to the first available tax.

## Impact

- `app/Livewire/Purchase/ProductCart.php`: `resolvePreferredPkpAutoTaxId` and `resolveDefaultTaxId`.
- Fixes `tests/Feature/Livewire/PurchaseProductCartNoDefaultTaxTest.php`.
