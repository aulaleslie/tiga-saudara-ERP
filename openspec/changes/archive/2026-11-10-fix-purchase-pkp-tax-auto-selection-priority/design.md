# Design

## Context

In `app/Livewire/Purchase/ProductCart.php`, PKP purchase cart line taxes are resolved in `resolvePreferredPkpAutoTaxId()` and `resolveDefaultTaxId()`.
Currently:
1. `resolvePreferredPkpAutoTaxId()` looks for product tax first, then falls back to `resolveDefaultTaxId()`.
2. `resolveDefaultTaxId()` returns `$defaultTax->id`, but if none is marked default and the business is PKP, falls back to `$this->taxes->first()->id`.
This violates the confirmed business priority.

## Goals / Non-Goals

**Goals:**
- Enforce tax priority: 1) default tax (`is_default = true`), 2) product purchase tax (`ProductPrice.purchase_tax_id`), 3) `null`.
- Remove first-tax fallback in `resolveDefaultTaxId()`.
- Keep existing PKP purchase validation in `PurchaseController` and `CreateForm` that blocks submission if any row has null tax.

**Non-Goals:**
- Do not modify sales cart tax behavior (`app/Livewire/Sale/ProductCart.php`).
- Do not change non-PKP cart behavior (tax always null).

## Decisions

### Decision 1: Priority order in `resolvePreferredPkpAutoTaxId`
- Check `resolveDefaultTaxId()` first.
- If null and `$productId` provided, check `resolveProductPurchaseTaxIdForProduct($productId, $productPayload)`.
- If neither exists, return `null`.

### Decision 2: Pure default lookup in `resolveDefaultTaxId`
- Remove `$this->taxes->first()->id` fallback.
- If `$this->taxes->firstWhere('is_default', true)` is found, return its ID; otherwise return `null`.

## Risks / Trade-offs

- [Risk] Existing tests expecting first-tax fallback when no default exists will fail.
  → Mitigation: `PurchaseProductCartNoDefaultTaxTest` was already failing because it expected `null`; with this change it will now pass. Any other test asserting the legacy fallback will be aligned with the confirmed priority.
