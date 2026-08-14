## 1. Shared Preview Resolution

- [x] 1.1 Add focused failing coverage for resolving one label-preview result per unique selected product, including valid output and product-specific barcode/price errors.
- [x] 1.2 Refactor or extend `BarcodeBatchService` to produce a bounded product-ID-keyed preview map using the existing label payload, symbology, SVG, selected-business price, and deterministic SKU rules without quantity expansion.
- [x] 1.3 Preserve the existing `expand()` and final print endpoint contracts while reusing the shared preview resolution path.

## 2. Business-Aware Product Suggestions

- [x] 2.1 Add focused Livewire coverage that search suggestions show primary barcode and formatted `sale_price` for the authorized selected business, including unavailable-price and unauthorized-business cases.
- [x] 2.2 Pass the workspace's selected business to `BarcodeProductSearch` as reactive context and resolve authorization before querying its constrained product price rows.
- [x] 2.3 Extend suggestion presentation with primary barcode, selected-business price or explicit unavailable state, and input guidance for name, SKU, and barcode search.
- [x] 2.4 Preserve partial primary-barcode suggestions, exact active primary-barcode Enter selection, existing result selection, and conversion-barcode exclusion.

## 3. Immediate Selected-Row Label Preview

- [x] 3.1 Add focused Livewire coverage that selecting a valid product renders one compact preview containing name, displayed SKU, barcode SVG/value, and formatted selected-business price without clicking the expanded-preview action.
- [x] 3.2 Add focused coverage that quantity changes keep one preview per unique product and preserve row merging, totals, expanded batch order, and requested copy counts.
- [x] 3.3 Add focused coverage that authorized business changes refresh every selected-row preview price and do not leave prior-business values visible.
- [x] 3.4 Add focused coverage for actionable inline states when barcode data is blank, explicitly invalid EAN-13, or missing a selected-business sale price, while existing preview/print gates still reject the batch.
- [x] 3.5 Build and refresh the workspace's bounded product-ID-keyed preview map at product selection/removal and business-context boundaries without treating preview data as print submission input.
- [x] 3.6 Add the responsive rightmost compact label-preview column, rendering exactly one preview or inline error per selected product while retaining quantity, removal, total, expanded-preview, and print controls.

## 4. Focused Verification

- [x] 4.1 Run `php artisan test Modules/Product/Tests/Feature/BrowserBatchBarcodePrintingTest.php` and resolve failures related to this change.
- [x] 4.2 If implementation touches another directly related Product-module test file, run only that additional file and document the focused result; do not run the full application test suite.
- [x] 4.3 Review the final diff for business-price authorization, bounded eager loading/no per-row queries, escaped product text, trusted server-generated SVG handling, and unchanged print request authority.
