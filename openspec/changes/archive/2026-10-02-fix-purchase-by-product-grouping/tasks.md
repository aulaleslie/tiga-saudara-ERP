# Tasks

## 1. Fix GROUP BY and SELECT in Query Service

- [x] 1.1 In `PurchaseByProductReportQueryService::build()`, change the GROUP BY clause from `groupBy('purchase_details.product_id', 'purchase_details.product_code', 'purchase_details.product_name', 'unit_name')` to `groupBy('purchase_details.product_id')`. Wrap the three display SELECT expressions (`product_code`, `product_name`, `unit_name`) in `MAX()` for MySQL `ONLY_FULL_GROUP_BY` compliance. Verify: run the raw SQL against the local DB for product_id 1526 on setting_id 1, year 2026 — must return exactly 1 row with qty 71 and correct totals.

## 2. Focused Verification

- [x] 2.1 Run existing `PurchaseByProductReportTest` suite to confirm no regressions: `php artisan test --filter=PurchaseByProductReportTest`. All existing tests must pass.
- [x] 2.2 Run existing `PurchaseByProductReportQueryServiceEligibilityTest` suite to confirm eligibility logic unchanged: `php artisan test --filter=PurchaseByProductReportQueryServiceEligibilityTest`. All existing tests must pass.
