## 1. Cart Pricing Consistency

- [x] 1.1 Update sales cart price resolution so create, repricing, and edit hydration all use active-setting `product_prices` metadata.
- [x] 1.2 Replace sales edit cart hydration's legacy product sale/tier fallbacks with the same setting-scoped pricing metadata used for newly added lines.
- [x] 1.3 Verify customer tier repricing updates existing cart lines, subtotals, and stored tier metadata consistently on sales create and edit pages.

## 2. Customer Selection Event Flow

- [x] 2.1 Standardize the sales customer quick-add flow so creating a customer results in the same effective customer-selection event used by existing-customer dropdown selection.
- [x] 2.2 Ensure the sales cart repricing path runs exactly once when a newly created customer becomes the selected customer.
- [x] 2.3 Add regression coverage for existing-customer selection and quick-add-customer selection with tiered repricing expectations.

## 3. Sales Product Quick-Add Behavior

- [x] 3.1 Add a sales-scoped quick-add mode that prevents silent purchase-only product creation when the modal is opened from sales create or edit.
- [x] 3.2 Require or default the sellable pricing inputs needed for inserting a quick-added product into the sales cart.
- [x] 3.3 Ensure the cart inserts quick-added products using the active setting's resolved sales price metadata and reprices them correctly after customer selection.

## 4. UI Feedback And Verification

- [x] 4.1 Update sales quick-add and cart UI feedback so validation failures do not present a misleading “added to cart” success state.
- [x] 4.2 Add feature or Livewire tests covering fresh sales create, sales edit hydration, quick-add product insertion, and customer-created repricing flows.
- [x] 4.3 Manually verify `/sales/create` and `/sales/{id}/edit` with the repro checklist paths for existing customer, quick-add customer, search product, and quick-add product.
