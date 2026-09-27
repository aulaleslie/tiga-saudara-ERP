## Why

When creating a new PKP purchase, the `is_tax_included` field is being stored as `0` (false) in the database, regardless of the checkbox state in the form. This occurs because the `CreateForm` component initializes `is_tax_included = false` by default, while the `ProductCart` child component initializes it to `true`. The two components fail to synchronize their state on initial mount, causing the parent's false value to be persisted.

Additionally, the current implementation requires clarification on intended behavior: the `is_tax_included` checkbox should only be visible and relevant for PKP entities, hidden completely for non-PKP entities.

During investigation, the purchase tax behavior itself was also found to be under-specified. The current implementation and tests contain conflicting expectations about what should happen when a PKP purchase product has no configured purchase tax. This has led to UI states that can imply a tax is selected while calculations still resolve to zero.

The purchase create flow also contains a related quick-add tax state bug. Inside the add-product modal, selecting an existing purchase or sale tax from the tax dropdown persists correctly. However, when the user creates a new tax from the nested quick-add tax modal and the dropdown auto-selects it, that selected tax is only reflected in the dropdown UI and is not propagated back to the parent product modal state. As a result, the created product is saved without the newly selected `purchase_tax_id` or `sale_tax_id`, and the purchase flow receives a product payload with missing tax configuration even though the UI suggested a tax had been chosen.

The business rule now needs to be captured explicitly:
- For PKP settings, product tax is required on purchase lines
- If the product has a configured purchase tax, use that tax automatically
- If the product has no configured purchase tax, use the default tax automatically
- If no product tax and no default tax exist, the line remains invalid until corrected explicitly
- For non-PKP settings, tax information must not be visible and must not be persisted

## What Changes

- **CreateForm component**: Initialize `is_tax_included = true` for PKP users on mount when creating a new purchase (not duplicating)
- **ProductCart component**: Dispatch `taxIncludedUpdated` event on mount even when `$data` is null, ensuring initial state syncs to parent component
- **Visibility**: Confirm and document that the checkbox is correctly hidden for non-PKP entities (currently working via `@if($isPkp)` in view)
- **PKP purchase tax assignment**: Define deterministic purchase-line tax assignment for PKP purchases using product purchase tax first, then default tax fallback
- **Non-PKP purchase normalization**: Ensure purchase tax UI, calculations, and persistence are fully suppressed when `is_pkp = false`
- **Quick-add product tax synchronization**: Ensure that when a user creates a new tax from the add-product modal, the auto-selected tax is synchronized into the parent product modal state and persists into the saved product and emitted purchase payload

## Capabilities

### New Capabilities

- `purchase-tax-included-initialization`: Proper initialization and synchronization of `is_tax_included` field between CreateForm and ProductCart components on initial mount, ensuring PKP purchases default to tax-included and maintain state consistency

### Modified Capabilities

- `purchase-creation`: Modification to how `is_tax_included` state is initialized and synchronized during purchase creation to ensure PKP purchases correctly store tax inclusion preference
- `purchase-creation`: Modification to purchase-line tax assignment and persistence rules so PKP and non-PKP settings behave consistently with the business rules

## Impact

**Affected Code:**
- `app/Livewire/Purchase/CreateForm.php` - mount() method
- `app/Livewire/Purchase/ProductCart.php` - mount() method
- `resources/views/livewire/purchase/product-cart.blade.php` - purchase tax visibility and selector behavior
- `Modules/Product/Livewire/TaxSearchDropdown.php` - tax dropdown auto-selection behavior after nested tax creation
- `app/Livewire/Modules/Product/Modals/ProductQuickAddModal.php` - parent modal state used for product tax persistence
- `Modules/Setting/Livewire/Modals/TaxQuickAddModal.php` - nested tax quick-add event contract
- Purchase normalization and persistence paths for create/edit flows
- Database: `purchases.is_tax_included` column values (existing records unaffected, new/edited purchases fixed)

**User Impact:**
- PKP users will now see `is_tax_included = 1` when they create a new purchase (current default behavior in UI, now persisted correctly)
- PKP users will get deterministic purchase tax behavior: product tax first, otherwise default tax
- Non-PKP users will not see or store tax information in purchase flows
- Users creating a new tax inside the add-product modal will no longer see a misleading selected tax that is dropped on save
- Data integrity: Fixes discrepancy between form state and database state
