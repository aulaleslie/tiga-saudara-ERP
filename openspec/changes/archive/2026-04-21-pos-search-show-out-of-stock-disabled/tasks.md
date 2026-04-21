## 1. Backend Search Semantics

- [x] 1.1 Update POS keyword search query to include matched products with `available_qty = 0` while keeping allowed sales-location scope and stock-managed filter intact.
- [x] 1.2 Update auto-select candidate logic so exact barcode/conversion matches only set `meta.auto_select_product_id` when `available_qty > 0`.
- [x] 1.3 Add/adjust feature tests for mixed in-stock/out-of-stock keyword matches and for exact-match zero-stock auto-select suppression.

## 2. Search Modal Interaction and Presentation

- [x] 2.1 Update search-result card rendering to mark out-of-stock products as disabled/non-selectable and prevent add-to-cart invocation for those cards.
- [x] 2.2 Add `Stok Kosong` visual status treatment (watermark/label + muted style) for out-of-stock cards in POS search modal CSS.
- [x] 2.3 Update keyboard navigation logic so focus/Enter interactions target selectable cards only and skip disabled out-of-stock cards.

## 3. Validation and Regression Safety

- [x] 3.1 Verify existing cart-service stock guard behavior remains unchanged and continues rejecting direct add attempts for unavailable stock.
- [x] 3.2 Run POS search/cart related tests and perform manual smoke checks for keyword search, exact barcode search, and modal add behavior.
- [x] 3.3 Confirm no regression to scan-resolve flow semantics (scope remains search modal behavior only).
