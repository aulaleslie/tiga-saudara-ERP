## 1. Export Data Resolution

- [x] 1.1 Refactor the price export service to resolve both required company settings uniquely before export work begins.
- [x] 1.2 Parameterize the product-price query by setting and select the company-scoped selling, tier, last purchase, and average purchase values together with the product purchase-price fallback.
- [x] 1.3 Add reusable purchase-cost resolution that treats null and zero as unavailable, resolves last purchase price from product purchase price, and resolves average purchase price from the effective last purchase price.

## 2. Workbook Generation

- [x] 2.1 Update the command to build CV TIGA NUSA COMPUTER as the first worksheet and CV TOP IT INTERNUSA as the second worksheet while retaining the existing command options and overwrite behavior.
- [x] 2.2 Apply the shared title, timestamp, six-column header, numeric formatting, widths, frozen header row, and autofilter layout to both worksheets.
- [x] 2.3 Report the successful export destination and row count for each company, and ensure a missing or ambiguous required setting leaves an existing destination untouched.

## 3. Verification

- [x] 3.1 Extend the focused command tests to assert workbook sheet order, company titles, six headers, and company-isolated selling and tier prices.
- [x] 3.2 Add focused tests for average-to-last and last-to-product-purchase fallback behavior, including null/zero values and blank output when no positive fallback exists.
- [x] 3.3 Add failure tests for an unresolved CV TOP IT INTERNUSA setting and run `php artisan test Modules/Product/Tests/Feature/TigaNusaPriceExportCommandTest.php`.
