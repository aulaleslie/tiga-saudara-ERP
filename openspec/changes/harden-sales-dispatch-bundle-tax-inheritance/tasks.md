## 1. Dispatch Tax Context Resolution

- [x] 1.1 Add a shared resolution path in sales dispatch flow to derive bundle component tax context from `sale_bundle_items.sale_detail_id -> sale_details.tax_id`.
- [x] 1.2 Update dispatch-page aggregation to build bundle composite keys with inherited parent tax context rather than nullable bundle-item tax context.
- [x] 1.3 Update `storeDispatch` validation aggregation to use the same inherited parent tax context for bundle composite keys.

## 2. Stock Bucket Consistency in UI and Validation

- [x] 2.1 Ensure non-serial bundle row stock display uses the corrected composite key tax context so location stock reflects the intended bucket.
- [x] 2.2 Ensure server-side non-serial stock validation evaluates the same corrected tax bucket as the dispatch-page display path.
- [x] 2.3 Add defensive handling for invalid/orphaned bundle-to-parent references so failures are explicit and safe.

## 3. Regression Coverage

- [x] 3.1 Add/extend feature tests for taxed parent bundle lines to verify dispatch uses `quantity_tax` for non-serial bundle components.
- [x] 3.2 Add/extend feature tests for non-tax parent bundle lines to verify dispatch uses `quantity_non_tax` for non-serial bundle components.
- [x] 3.3 Add/extend a consistency test ensuring dispatch-page stock indication and submit-time validation remain aligned for bundle components.
