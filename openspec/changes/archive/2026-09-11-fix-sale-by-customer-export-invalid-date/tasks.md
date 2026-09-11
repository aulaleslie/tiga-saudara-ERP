## 1. Query and Date Resolution

- [x] 1.1 Add an explicit qualified archive constraint to the Penjualan Per Customer query so archived sales cannot affect displayed rows, pagination, or totals.
- [x] 1.2 Update export row mapping to prefer the query-selected effective `sale_date` while retaining a safe fallback for direct mapper callers.

## 2. Export Safety

- [x] 2.1 Add defensive date formatting to the Penjualan Per Customer exporter so valid effective dates use `d/m/Y` and missing or invalid values produce a neutral placeholder without throwing for XLSX or CSV.

## 3. Focused Verification

- [x] 3.1 Add focused regression coverage proving archived matching sales are excluded and active original/override dates remain correctly mapped for export.
- [x] 3.2 Add focused coverage proving an absent or invalid mapped date does not raise a Carbon parsing exception, then run only the relevant report tests.
- [x] 3.3 Record the human browser check for successful XLSX and CSV downloads as the remaining manual acceptance step.
