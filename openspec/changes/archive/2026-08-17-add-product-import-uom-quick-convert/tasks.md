## 1. Audit schema

- [x] 1.1 Create migration for the correction audit table (product, old/new unit_id, factor, before/after global and per-location quantities, before/after cost-basis fields, reason, actor user id, timestamp, rounding notes)
- [x] 1.2 Create migration for the removed-documents audit table/JSON column (correction id, document type [POS/SALE], reference, status, payment amount, owner/customer, created_at)
- [x] 1.3 Add Eloquent model(s) for the new audit table(s)

## 2. Eligibility service

- [x] 2.1 Implement transaction-ledger self-consistency check (global and per-location `after_quantity`/`after_quantity_at_location` vs live `product_quantity`/`product_stocks.quantity`)
- [x] 2.2 Implement fulfillment-history check: refuse if any `DISPATCH`-type transaction exists, or any `BUY`-type transaction exists, for the product
- [x] 2.3 Implement Sale-fulfillment check: refuse if any `sales` row referencing the product has `status IN ('DISPATCHED','RETURNED','RETURNED PARTIALLY')` or `paid_amount > 0`
- [x] 2.4 Implement broken-stock check: refuse if any `product_stocks` row has non-zero `broken_quantity`/`broken_quantity_tax`/`broken_quantity_non_tax`
- [x] 2.5 Implement unhandled-complexity checks: refuse if `product_unit_conversions` rows exist, if `products.barcode` is non-null, or if stock/price footprint spans more than one setting
- [x] 2.6 Implement discovery of removable documents: POS transactions (status `DRAFT`/`LOADED`) with a line referencing the product; Sales (status not dispatched/returned, `paid_amount = 0`) referencing the product via `sale_details`
- [x] 2.7 Compose all checks into a single eligibility result object (eligible boolean, list of blocking reasons, list of documents to remove, before-state snapshot for preview)
- [x] 2.8 Unit tests for each eligibility check in isolation, using factories to construct each blocking and passing scenario

## 3. Mutation service

- [x] 3.1 Implement quantity rebase: multiply `products.product_quantity`, each `product_stocks` quantity bucket, and the originating adjustment transaction's own quantity fields by `factor`
- [x] 3.2 Implement cost-basis rebase: divide `average_purchase_price`/`last_purchase_price` by `factor` with higher internal precision, recording any display-rounding effect
- [x] 3.3 Implement unit flip: update `products.unit_id` and `products.base_unit_id` to the target unit
- [x] 3.4 Implement document removal: delete qualifying POS transactions (and their lines) and Sales (and their details) identified during eligibility, without touching `product_stocks`/`product_quantity` as a result
- [x] 3.5 Wrap all of 3.1–3.4 plus audit-row creation in a single database transaction with row locking on the product; re-validate eligibility under lock before committing
- [x] 3.6 Unit/feature tests: full successful execution, each blocking condition prevents mutation, removed-documents report matches actual deletions, precision/rounding is recorded correctly

## 4. Artisan command

- [x] 4.1 Define command signature: `product:convert-uom {product_id} {target_unit} {factor} {--reason=} {--dry-run}`
- [x] 4.2 Validate arguments (product exists, target unit exists and differs from current base unit, factor is a positive number, reason non-empty when not dry-run)
- [x] 4.3 Wire dry-run mode: run eligibility only, print blocking reasons or projected before/after impact and documents-to-be-removed, perform no mutation
- [x] 4.4 Wire execute mode: run eligibility, call mutation service, print the audit summary and removed-documents report
- [x] 4.5 Feature tests covering command output for: ineligible product (each blocking reason), dry-run on eligible product, successful execution on eligible product

## 5. Documentation and rollout

- [x] 5.1 Document command usage and eligibility rules in module README or relevant docs location
- [x] 5.2 Manually verify against the triggering case (product 4669, target unit PCS, factor 82) in a non-production environment restored from the production snapshot
- [x] 5.3 Confirm audit records and removed-documents report are reviewable by a non-technical operator before running against production
