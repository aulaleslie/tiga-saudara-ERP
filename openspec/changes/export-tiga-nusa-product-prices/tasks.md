## 1. Export Foundation

- [x] 1.1 Add a Product-module Excel export class that exposes the product-name and three price columns, workbook title metadata, header styling, numeric price formatting, frozen headers, and auto-filter.
- [x] 1.2 Build the query-backed export data source from all products left-joined to only the resolved CV TIGA NUSA COMPUTER `product_prices` rows, ordered by product name, preserving blank prices for missing rows.
- [x] 1.3 Add the `product:export-tiga-nusa-prices` console command with the default `.xlsx` path, runtime exact-setting resolution, clear missing/ambiguous-setting errors, and completion count.
- [x] 1.4 Implement barcode-export-compatible `--path`, `--force`, and overwrite-confirmation behavior while writing a native Excel workbook.
- [x] 1.5 Register the command in the Product service provider.

## 2. Verification

- [x] 2.1 Add focused command tests for the default path, a custom path, the generated Xlsx format, expected headers, product-name ordering, and reported row count.
- [x] 2.2 Add tests proving only CV TIGA NUSA COMPUTER price values appear when another setting has different values, and that products without a Tiga Nusa price row are included with blank price cells.
- [x] 2.3 Add tests for missing/duplicate target-setting failure and overwrite cancellation/`--force` behavior.
- [x] 2.4 Run the focused Product-module test suite and the relevant project test command; record any environment limitations.
