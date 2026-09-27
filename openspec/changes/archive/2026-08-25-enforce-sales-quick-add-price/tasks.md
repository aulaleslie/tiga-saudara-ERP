## 1. Sales Quick-Add Validation

- [x] 1.1 Add Sales-context validation that requires `sale_price` to be numeric and greater than zero before product creation, without changing Purchase-context or normal product-edit rules.
- [x] 1.2 Confirm failed Sales validation leaves product and cart persistence untouched and exposes feedback on the `sale_price` field.

## 2. Focused Cross-Business Regression Coverage

- [x] 2.1 Update the focused Sales product quick-add tests to cover missing, zero, and valid positive sale prices, including the existing tier-price defaults.
- [x] 2.2 Add focused Sales quick-add assertions proving identical initial `ProductPrice` rows are created for every existing business.
- [x] 2.3 Add focused Purchase quick-add assertions proving its submitted initial purchase and optional sale pricing is created identically for every existing business.

## 3. Targeted Verification

- [x] 3.1 Run only the touched Sales and Purchase product quick-add test files and resolve failures caused by this change; do not run the full application test suite.
