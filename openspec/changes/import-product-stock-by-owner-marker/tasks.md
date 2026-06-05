## 1. Import Entry Point

- [x] 1.1 Decide whether the stock snapshot import is a distinct product upload mode or a dedicated product stock upload route, preserving existing product import behavior.
- [x] 1.2 Add upload/header validation for `Product Code`, `Product Name`, `Unassigned`, `Total Quantity`, and `Product Unit`.
- [x] 1.3 Store the uploaded stock snapshot file in the product import storage area and create a product import batch for processing.

## 2. Row Staging and Normalization

- [x] 2.1 Add row mapping for stock snapshot CSV columns into normalized row payload keys.
- [x] 2.2 Implement product-name marker parsing for leading `*`, trailing `TP`, and default owner marker cases.
- [x] 2.3 Normalize clean product names consistently before matching or creating products.
- [x] 2.4 Preserve raw marker, clean product name, `Unassigned`, `Total Quantity`, product unit, and product code in row payload for audit.

## 3. Owner and Location Resolution

- [x] 3.1 Resolve `*` rows to CV TIGA NUSA COMPUTER, `TP` rows to CV TOP IT INTERNUSA, and default rows to PERDANA.
- [x] 3.2 Resolve the first location for each owner setting and cache setting/location lookups during batch processing.
- [x] 3.3 Mark rows as errors when the required owner setting or first owner location cannot be found.

## 4. Product Matching and Creation

- [x] 4.1 Match existing products by normalized clean product name and product code without creating owner-specific duplicate products.
- [x] 4.2 Create missing stock-managed products with clean product name, optional product code, and resolved product unit.
- [x] 4.3 Create or reuse product unit records using existing module conventions.
- [x] 4.4 Seed required product price rows with conservative defaults for all settings when creating a missing product.

## 5. Stock Overwrite and Audit

- [x] 5.1 Parse `Total Quantity` as the authoritative stock quantity, including zero and negative values.
- [x] 5.2 Create or update the target `product_stocks` row for the resolved product and first owner location.
- [x] 5.3 Overwrite stock buckets and product quantity projections consistently with existing stock conventions.
- [x] 5.4 Record a stock transaction or equivalent audit entry with previous quantity, after quantity, owner setting, location, user, and import reason.
- [x] 5.5 Ensure each row is processed atomically so row failure does not partially update product or stock state.

## 6. Monitoring and Result Visibility

- [x] 6.1 Show row status, error message, raw payload, product reference, and stock effect details on the product import batch detail page.
- [x] 6.2 Ensure batch counters reflect imported, skipped, and error rows for the stock snapshot import.
- [x] 6.3 Keep the existing product import list usable for both current product imports and stock snapshot imports.

## 7. Verification

- [x] 7.1 Add focused tests for header validation and stock snapshot row staging.
- [x] 7.2 Add tests for marker routing and clean product name normalization.
- [x] 7.3 Add tests for creating missing products and reusing one global product across multiple owner markers.
- [x] 7.4 Add tests for overwriting positive, zero, and negative stock quantities at the first owner location.
- [x] 7.5 Add tests for row-level errors when owner setting or location is missing.
- [x] 7.6 Run focused Product import tests and a relevant broader Laravel test command if feasible.
