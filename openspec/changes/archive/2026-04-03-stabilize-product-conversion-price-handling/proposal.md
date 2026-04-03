## Why

Product conversion prices on the product create and edit flows are currently fragile because a visible masked input, a hidden submitted input, and duplicated JavaScript binders must remain synchronized across Livewire rerenders. That contract has already failed in production-facing behavior, where users can see a filled conversion price but the backend still receives `null` and rejects the submission.

This needs to be fixed now because conversion prices are part of core product setup, and the current implementation makes the form unreliable precisely in the stock-managed flow where users are defining the sellable unit structure of a product.

## What Changes

- Stabilize product conversion price entry so a filled conversion price in the UI is preserved through focus, blur, dynamic row changes, form submission, and validation on both create and edit flows.
- Make the unit-conversion component the single owner of conversion price masking and synchronization behavior instead of splitting responsibility across both the Livewire partial and the page template.
- Replace selector-fragile hidden-input lookup with a DOM-relative contract so dynamic conversion rows do not depend on generated CSS-selector-safe IDs.
- Normalize submitted conversion prices server-side before validation so formatted currency input is converted into canonical numeric values consistently.
- Preserve existing validation intent: conversion price remains required when a conversion unit is chosen, and rejected values still produce the same user-facing validation errors when actually missing or invalid.

## Capabilities

### New Capabilities
- `product-conversion-price-handling`: defines the required behavior for entering, preserving, submitting, and validating product conversion prices across dynamic conversion rows in product create and edit flows.

### Modified Capabilities
- `nominal-field-deterministic-rp-format`: clarify that the canonical raw-value contract for product nominal fields also applies to conversion price rows rendered through the stock-managed unit configuration flow, including dynamic row additions and form resubmission after validation errors.

## Impact

- Affected code: `app/Livewire/Product/UnitConfiguration.php`, `resources/views/livewire/product/unit-configuration.blade.php`, `Modules/Product/Resources/views/products/create.blade.php`, `Modules/Product/Resources/views/products/edit.blade.php`, and `Modules/Product/Http/Requests/StoreProductInfoRequest.php` plus corresponding update request normalization paths.
- Affected behavior: product create/edit flows for stock-managed items with conversion rows, especially masked conversion price input, hidden/raw value synchronization, and server-side validation.
- Affected tests: product request normalization coverage, product create/edit regression tests for conversion price submission, and frontend/integration coverage for dynamic conversion rows after Livewire rerenders.
