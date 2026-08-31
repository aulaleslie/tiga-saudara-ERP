## 1. Permission and Entry Guards

- [ ] 1.1 Register and seed `products.convert_existing_stock_to_serialized` using the existing product permission conventions, without bundling it into ordinary product-edit access.
- [ ] 1.2 Add permission-protected Product module routes and an eligible-product entry action for the conversion page, scan validation, and final submission.
- [ ] 1.3 Add a server-side generic product-update guard that rejects stocked false-to-true serial tracking changes outside the dedicated conversion flow.

## 2. Eligibility and Pool Resolution

- [ ] 2.1 Implement a shared conversion eligibility service that checks product state, positive whole-number and internally consistent stock buckets, absence of serial records, default-tax availability, and directly related active stock-moving dependencies.
- [ ] 2.2 Implement owner-level aggregation of normal/broken and PPN/Non-PPN pools from all product stock rows and their original owner/location relationships.
- [ ] 2.3 Implement conversion-specific global serial validation that trims input and rejects every existing database serial regardless of product or lifecycle status.

## 3. Scanner Conversion Page

- [ ] 3.1 Build the product conversion page with owner, PPN/Non-PPN, and normal/broken selectors; capped pool progress; overall progress; and Indonesian eligibility/warning text.
- [ ] 3.2 Adapt the purchase-receiving Enter scanner interaction to add removable serial badges, maintain page-wide uniqueness, enforce the active pool cap, clear/refocus after scans, and retain all scan data in the single final form.
- [ ] 3.3 Keep final confirmation disabled until every pool is exact, then show the all-stock Indonesian confirmation and submit all pools once.

## 4. Atomic Conversion Execution

- [ ] 4.1 Implement a conversion request validator for the complete nested pool payload, expected quantities, serial formatting, page-wide uniqueness, and permission.
- [ ] 4.2 Implement the atomic conversion service with product-first and deterministic stock-row locking, authoritative eligibility/pool recomputation, stock-drift rejection, and final global serial uniqueness checks.
- [ ] 4.3 Allocate scanned serials in stable order to each owner's original location bucket capacities, applying the default tax to PPN pools and the correct broken identity to broken pools.
- [ ] 4.4 Create all serial rows and conversion-specific serial history/audit entries before enabling `serial_number_required` last, with full rollback on any error.
- [ ] 4.5 Return clear Indonesian success, incomplete, drift, duplicate, already-converted, and retry-safe responses without creating partial or repeated records.

## 5. Focused Verification

- [ ] 5.1 Add focused authorization and generic-update-guard feature tests for permitted, forbidden, and bypass-attempt cases.
- [ ] 5.2 Add focused pool/scanner endpoint tests for cross-setting aggregation, all four stock buckets, caps, default tax, fractional-stock rejection, and database-wide duplicate rejection.
- [ ] 5.3 Add focused atomic service tests for deterministic multi-location allocation, complete success, stock drift, injected failure rollback, and repeated/concurrent submission safety.
- [ ] 5.4 Run only the directly related Product conversion test files and any existing Product tests touched by the update guard, fixing regressions within this change's scope.
