## 1. Shared Search Query Support

- [x] 1.1 Add reusable global-payment search query support that trims input, splits non-empty whitespace tokens, applies AND-per-token/OR-per-text-field grouping, and parameterizes all values.
- [x] 1.2 Add a reusable whole-input exact case-insensitive identity comparison that behaves consistently on MySQL/MariaDB and SQLite without introducing partial barcode or serial matching.
- [x] 1.3 Add current-product lookup paths for product name, product code, primary barcode, and unit-conversion barcode without applying active-product filtering or snapshot-field fallback.

## 2. Purchase Global-Payment Search

- [x] 2.1 Integrate the shared search support into `PurchaseTable` global mode while preserving the existing ordinary purchase-list search behavior.
- [x] 2.2 Add purchase serial lookup through current receiving-detail serial associations and supported legacy receiving provenance, tied to the matching purchase.
- [x] 2.3 Confirm tokenized and exact-identity predicates continue to compose with global eligibility, supplier embedding, business/date/card filters, sorting, and pagination.

## 3. Sales Global-Payment Search

- [x] 3.1 Integrate the shared search support into `SaleTable` global mode while preserving the existing ordinary sales-list search behavior.
- [x] 3.2 Add exact sales serial lookup through persisted sale/dispatch provenance so only the sale carrying the matched serial is returned.
- [x] 3.3 Confirm tokenized and exact-identity predicates continue to compose with global eligibility, customer embedding, business/date/card filters, POS and bundle text fields, sorting, and pagination.

## 4. Focused Verification

- [x] 4.1 Add focused global purchase-payment tests for cross-field AND-token matching, current product name/code authority, snapshot-only exclusion, inactive linked products, exact mixed-case primary/conversion barcode matches, exact serial lineage, and partial identity rejection.
- [x] 4.2 Add focused global sales-payment tests for cross-field AND-token matching, current product name/code authority, snapshot-only exclusion, inactive linked products, exact mixed-case primary/conversion barcode matches, exact serial lineage, and partial identity rejection.
- [x] 4.3 Add focused regression assertions that ordinary non-global purchase and sales list search remains unchanged and that party/business or eligibility constraints cannot be bypassed by search.
- [x] 4.4 Run only the directly affected focused Laravel test files or filters and record their passing results; do not run the full suite for this change.
