## 1. Frontend Routing

- [x] 1.1 Audit POS sell add/scan entry points for product-only cart-line targeting before bundle selection.
- [x] 1.2 Update serial scan handling so bundle-parent products always route through `addProductToCart` and bundle intent capture before appending serials.
- [x] 1.3 Add a bundle-aware cart-line matcher that normalizes product id and bundle id, including explicit no-bundle state.
- [x] 1.4 Use the bundle-aware matcher after selected-bundle add to append preserved serials to the correct row.
- [x] 1.5 Use the bundle-aware matcher after continue-without-bundle add so normal rows do not target selected-bundle rows.
- [x] 1.6 Ensure non-serial bundle-parent barcode/manual/camera adds always show bundle selection before quantity increment.

## 2. Backend Verification

- [x] 2.1 Verify `PosCartService` merge keys keep selected bundle ids and no-bundle rows distinct.
- [x] 2.2 Verify append-serial behavior operates on the requested line id and does not re-target by product id when multiple bundle-aware rows exist.
- [x] 2.3 Add or adjust backend tests only if verification exposes a backend gap.

## 3. Regression Coverage

- [x] 3.1 Add test coverage for serial bundle-parent scan where choosing the same bundle appends to the matching bundle row.
- [x] 3.2 Add test coverage for serial bundle-parent scan where choosing a different bundle creates or targets a different bundle row.
- [x] 3.3 Add test coverage for serial bundle-parent scan where continue-without-bundle creates or targets the no-bundle row.
- [x] 3.4 Add test coverage for non-serial bundle-parent add where choosing the same bundle increments only the matching bundle row.
- [x] 3.5 Add test coverage for non-serial bundle-parent add where choosing a different bundle keeps a separate row.
- [x] 3.6 Add test coverage for non-serial bundle-parent add where continue-without-bundle stays separate from selected-bundle rows.

## 4. Validation

- [x] 4.1 Run focused POS cart, bundle, and scan feature tests.
- [x] 4.2 Run any affected frontend/static checks available for the POS sell shell.
- [x] 4.3 Manually verify the cashier sequence: Product A + Bundle A, Product A + Bundle B, and Product A with no bundle can coexist and receive the correct serials or quantities.
