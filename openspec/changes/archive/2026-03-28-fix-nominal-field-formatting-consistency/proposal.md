## Why

Product create and edit pages have inconsistent nominal field behavior: create page shows raw numbers on focus (good UX), but edit page shows formatted currency on focus (blocks editing). This creates a confusing experience and masks a systemic issue where jQuery maskMoney state conflicts with Livewire DOM re-renders. Additionally, the conversion table price field has three competing systems fighting for control (jQuery + Livewire wire:model + wire:focus/blur handlers), making behavior unreliable across focus/blur cycles.

## What Changes

- **Edit page**: Remove `maskNow()` early initialization that breaks focus/blur lifecycle; implement proper display initialization that respects maskMoney state transitions
- **Conversion table**: Eliminate Livewire wire:focus/blur handlers that conflict with jQuery maskMoney; let jQuery handle formatting exclusively, Livewire manages only the hidden data storage
- **All 5 nominal fields**: Ensure identical behavior on both create and edit pages:
  - Page load: formatted display (Rp 1.000.000,00)
  - On focus: raw number display (1000000)
  - On blur: formatted display (Rp 1.000.000,00)
- **Consistency audit**: Review and standardize all payment forms (9 files) for null-safety and precision config consistency

## Capabilities

### New Capabilities
- `nominal-field-formatter`: Reusable component for consistent currency/numeric field formatting across all forms. Creates single source of truth for focus/blur/submit lifecycle, currency settings integration, and null-safe defaults.

### Modified Capabilities
- `product-price-management`: Existing product pricing system updated to use consistent formatting behavior on create and edit operations; edit page no longer has degraded UX on focus.

## Impact

**Affected Files:**
- `/Modules/Product/Resources/views/products/edit.blade.php` - Remove maskNow(), fix initialization
- `/resources/views/livewire/product/unit-configuration.blade.php` - Remove wire:focus/blur handlers
- `/app/Livewire/Product/UnitConfiguration.php` - Remove showRawPrice/syncPrice methods no longer needed
- `/resources/views/components/nominal-field.blade.php` - NEW reusable component
- 9 payment form files - audit and standardize (Phase 2)

**API/Behavior:**
- No breaking changes; behavior becomes MORE consistent (edit now matches create)
- User can see raw numbers on focus in all contexts (improvement)
- Conversion table pricing now reliable across focus/blur cycles

**Dependencies:**
- jQuery maskMoney v3.1.1 (already in use, no version change)
- Livewire 3.x (no version change, only removes conflicting handlers)
