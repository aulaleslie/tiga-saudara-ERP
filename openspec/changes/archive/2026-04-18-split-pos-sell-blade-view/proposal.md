## Why

`Modules/Pos/Resources/views/sell.blade.php` has grown into a 5,000+ line monolithic Blade view with inline CSS, POS shell markup, modal markup, and inline JavaScript. Previous attempts to split this view broke the POS screen, so this change is needed to reduce file size through a deliberately low-risk decomposition with explicit preservation checks.

## What Changes

- Split static portions of the POS sell Blade view into Blade partials under `Modules/Pos/Resources/views/sell/`.
- Start with the lowest-risk extraction: move the existing inline page CSS into a Blade partial rendered from the same `@push('page_css')` location.
- Continue only with static Blade partials for modal and shell markup after the CSS extraction is verified.
- Preserve the browser-facing contract: DOM IDs, classes, route output, script order, style order, permission checks, modal attributes, and inline JavaScript behavior must remain unchanged.
- Add or use verification that compares the rendered POS sell page before and after each extraction slice.
- Do not extract the large inline JavaScript into public assets as part of this change.

## Capabilities

### New Capabilities
- `pos-sell-view-decomposition`: Defines how the POS sell screen may be decomposed into Blade partials while preserving rendered behavior and regression safety.

### Modified Capabilities
- None.

## Impact

- Affected view: `Modules/Pos/Resources/views/sell.blade.php`.
- New view partials under `Modules/Pos/Resources/views/sell/css/`, `sell/modals/`, and possibly `sell/components/`.
- Existing POS sell route, controllers, APIs, permissions, public JavaScript assets, and checkout/cart/customer/scanner behavior must remain unchanged.
- Existing POS feature tests and a targeted render-equivalence check will be used to guard against view breakage.
