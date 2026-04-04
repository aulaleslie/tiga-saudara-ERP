## 1. CreateForm Component Fix

- [x] 1.1 Update CreateForm.mount() to initialize is_tax_included = true when isPkp is enabled
- [x] 1.2 Ensure initialization only applies to new purchases (check duplicateId is null)
- [x] 1.3 Verify initialization works for both PKP and non-PKP entities

## 2. ProductCart Tax State Fix

- [x] 2.1 Move dispatch('taxIncludedUpdated') outside the if($data) block in ProductCart.mount()
- [x] 2.2 Ensure event fires for both new purchases ($data = null) and duplicate purchases ($data != null)
- [x] 2.3 Verify dispatch passes correct is_tax_included value

## 3. Purchase Tax Policy Alignment

- [x] 3.1 Ensure PKP purchase lines automatically use the product purchase tax when configured for the active setting
- [x] 3.2 Ensure PKP purchase lines fall back to the default tax when the product has no configured purchase tax
- [x] 3.3 Remove any latest-tax fallback behavior from PKP purchase tax assignment
- [x] 3.4 Ensure PKP purchase submission still fails clearly if no product tax and no default tax can be resolved
- [x] 3.5 Ensure non-PKP purchase flows hide purchase tax selectors and tax-included UI consistently
- [x] 3.6 Ensure non-PKP purchase create/update paths persist `tax_id = null`, `product_tax_amount = 0`, `tax_amount = 0`, and `is_tax_included = false`

## 4. Testing

- [x] 4.1 Create test for new PKP purchase defaulting to is_tax_included = true
- [x] 4.2 Create test for new non-PKP purchase defaulting to is_tax_included = false
- [x] 4.3 Create test for event dispatch on ProductCart mount with null data
- [x] 4.4 Create test for CreateForm receiving and storing the event value
- [x] 4.5 Create test verifying submitted purchase has correct is_tax_included in database
- [x] 4.6 Run existing purchase tests to ensure no regressions
- [x] 4.7 Add or update tests for PKP purchase tax resolution order: product tax first, default tax second
- [x] 4.8 Add or update tests proving PKP purchase rows do not use latest-tax fallback
- [x] 4.9 Add or update tests proving non-PKP purchase flows do not render or persist any tax state
- [x] 4.10 Add or update tests proving quick-add product modal persists an existing manually selected purchase tax into the created product payload
- [x] 4.11 Add or update tests proving newly created purchase tax in the quick-add modal auto-selects and persists into the created product payload
- [x] 4.12 Add or update tests proving newly created sale tax in the quick-add modal auto-selects and persists into the created product payload
- [x] 4.13 Add or update tests proving `taxCreated` auto-selection is scoped to the requesting dropdown and does not overwrite the other tax field

## 5. Manual Testing & Verification

- [x] 5.1 Test new PKP purchase creation flow (checkbox visible, checked by default, stored as 1)
- [x] 5.2 Test PKP purchase with product-specific purchase tax and verify that exact tax is selected and calculated
- [x] 5.3 Test PKP purchase where product has no purchase tax and verify default tax is selected and calculated
- [x] 5.4 Test PKP purchase with no product tax and no default tax and verify the flow blocks clearly
- [x] 5.5 Test new non-PKP purchase creation flow (tax UI hidden, stored with no tax state)
- [x] 5.6 Test toggling checkbox on PKP form and verifying database value
- [x] 5.7 Test duplicate purchase flow (values come from existing purchase, not defaults)
- [x] 5.8 Verify no console errors or Livewire warnings
- [x] 5.9 Test add-product modal in purchase create flow: select an existing purchase tax and verify the saved product and resulting purchase line retain that tax
- [x] 5.10 Test add-product modal in purchase create flow: create a new purchase tax, verify it auto-selects, saves on the product, and appears on the resulting purchase line
- [x] 5.11 Test add-product modal with sale pricing enabled: create a new sale tax and verify only the sale tax field auto-selects it
- [x] 5.12 Verify the non-requesting tax dropdown does not visually change when a new tax is created from the other field

## 6. Documentation & Cleanup

- [x] 6.1 Add inline comments explaining the PKP initialization logic
- [x] 6.2 Update any related code comments or documentation
- [x] 6.3 Verify no unused code or temporary changes remain
