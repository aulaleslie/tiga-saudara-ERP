## ADDED Requirements

### Requirement: Purchase by supplier export requires prior filter application
The system SHALL block export if no filter has been applied in the current session.

#### Scenario: Export blocked before filtering
- **WHEN** a user opens `Pembelian Per Supplier` and clicks Excel or CSV export without first applying filters
- **THEN** the system displays an error alert indicating that filters must be applied first
- **AND** no file download is initiated

### Requirement: Purchase by supplier export snapshot guard
The system SHALL block export if the applied filters have changed since the last filter application.

#### Scenario: Export blocked after filter change without re-applying
- **WHEN** a user applies filters, changes a filter value, and then clicks export without re-applying filters
- **THEN** the system displays an error alert indicating that filters must be re-applied
- **AND** no file download is initiated

#### Scenario: Export succeeds when filters match last application
- **WHEN** a user applies filters and immediately clicks export
- **THEN** the system initiates a file download

### Requirement: Purchase by supplier XLSX export format
The system SHALL produce an XLSX file with a formatted header block and data rows matching the applied filters and sort.

#### Scenario: XLSX file has title and period rows
- **WHEN** a user exports the `Pembelian Per Supplier` report as XLSX
- **THEN** the first row contains the report title merged across all columns
- **AND** the second row contains the period label `Periode: dd/mm/yyyy s/d dd/mm/yyyy` merged across all columns
- **AND** the third row contains the column headers in bold

#### Scenario: XLSX columns match the sample layout
- **WHEN** a user exports the `Pembelian Per Supplier` report as XLSX
- **THEN** the exported columns are `Supplier`, `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Keterangan`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, `Total nominal tagihan` in that order

#### Scenario: XLSX filename follows naming convention
- **WHEN** a user exports the report for period 01/05/2026 to 31/05/2026 as XLSX
- **THEN** the downloaded filename is `purchases_by_vendor_01-05-2026_31-05-2026.xlsx`

### Requirement: Purchase by supplier CSV export format
The system SHALL produce a CSV file with column headers and data rows matching the applied filters and sort, without additional formatting rows.

#### Scenario: CSV file has only headers and data rows
- **WHEN** a user exports the `Pembelian Per Supplier` report as CSV
- **THEN** the first row contains the column headers
- **AND** subsequent rows contain data without merged title or period rows

#### Scenario: CSV columns match the sample layout
- **WHEN** a user exports the `Pembelian Per Supplier` report as CSV
- **THEN** the exported columns are `Supplier`, `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Keterangan`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, `Total nominal tagihan` in that order

#### Scenario: CSV filename follows naming convention
- **WHEN** a user exports the report for period 01/05/2026 to 31/05/2026 as CSV
- **THEN** the downloaded filename is `purchases_by_vendor_01-05-2026_31-05-2026.csv`

### Requirement: Purchase by supplier export running totals
The system SHALL compute `Total nominal tagihan` per supplier in the exported file using the same running total logic as the on-screen report.

#### Scenario: Running total resets for each supplier group
- **WHEN** the export contains rows for Supplier A (sub_total 500) followed by rows for Supplier B (sub_total 300)
- **THEN** Supplier A's `Total nominal tagihan` starts from 0 and accumulates within the A group
- **AND** Supplier B's `Total nominal tagihan` starts from 0 and accumulates within the B group independently

#### Scenario: Running total accumulates within a supplier group
- **WHEN** a supplier group has three rows with `Nominal tagihan` values 1000, 2000, 3000 in display order
- **THEN** the first row's `Total nominal tagihan` is 1000
- **AND** the second row's `Total nominal tagihan` is 3000
- **AND** the third row's `Total nominal tagihan` is 6000

### Requirement: Purchase by supplier export row parity
The system SHALL export the same rows that match the applied filters — one row per purchase detail.

#### Scenario: Export row count matches filtered result count
- **WHEN** the filtered report contains N purchase detail rows
- **THEN** the exported file contains exactly N data rows (excluding header and title rows)

#### Scenario: Export respects applied supplier filter
- **WHEN** a user applies a supplier filter and exports
- **THEN** the exported file contains only rows for the selected suppliers
## Requirements
### Requirement: Purchase by supplier export tax row expansion
The system SHALL export a separate `Pajak` row immediately after a purchase detail's product row when the persisted purchase detail has `product_tax_amount > 0`.

#### Scenario: Export includes tax row for taxed purchase detail
- **WHEN** a matching purchase detail has `sub_total` 11162162.16 and `product_tax_amount` 1227837.84
- **THEN** the exported file contains a product row with `Nominal tagihan` 11162162.16
- **AND** the exported file contains a following `Pajak` row with `Nominal tagihan` 1227837.84

#### Scenario: Export omits tax row for untaxed purchase detail
- **WHEN** a matching purchase detail has `product_tax_amount` 0
- **THEN** the exported file contains the purchase detail product row
- **AND** the exported file does not contain a `Pajak` row for that detail

#### Scenario: Export tax row uses persisted amount regardless of current PKP setting
- **WHEN** a matching purchase detail has `product_tax_amount` greater than 0
- **AND** the current setting has any `is_pkp` value
- **THEN** the exported file contains the `Pajak` row
- **AND** the exported file does not recompute tax from current tax settings

### Requirement: Purchase by supplier XLSX export format
The system SHALL produce an XLSX file with a formatted header block and data rows matching the applied filters and sort.

#### Scenario: XLSX file has title and period rows
- **WHEN** a user exports the `Pembelian Per Supplier` report as XLSX
- **THEN** the first row contains the report title merged across all columns
- **AND** the second row contains the period label `Periode: dd/mm/yyyy s/d dd/mm/yyyy` merged across all columns
- **AND** the third row contains the column headers in bold

#### Scenario: XLSX columns omit Keterangan
- **WHEN** a user exports the `Pembelian Per Supplier` report as XLSX
- **THEN** the exported columns are `Supplier`, `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, `Total nominal tagihan` in that order
- **AND** the exported columns do not include `Keterangan`

#### Scenario: XLSX filename follows naming convention
- **WHEN** a user exports the report for period 01/05/2026 to 31/05/2026 as XLSX
- **THEN** the downloaded filename is `purchases_by_vendor_01-05-2026_31-05-2026.xlsx`

### Requirement: Purchase by supplier CSV export format
The system SHALL produce a CSV file with column headers and data rows matching the applied filters and sort, without additional formatting rows.

#### Scenario: CSV file has only headers and data rows
- **WHEN** a user exports the `Pembelian Per Supplier` report as CSV
- **THEN** the first row contains the column headers
- **AND** subsequent rows contain data without merged title or period rows

#### Scenario: CSV columns omit Keterangan
- **WHEN** a user exports the `Pembelian Per Supplier` report as CSV
- **THEN** the exported columns are `Supplier`, `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, `Total nominal tagihan` in that order
- **AND** the exported columns do not include `Keterangan`

#### Scenario: CSV filename follows naming convention
- **WHEN** a user exports the report for period 01/05/2026 to 31/05/2026 as CSV
- **THEN** the downloaded filename is `purchases_by_vendor_01-05-2026_31-05-2026.csv`

### Requirement: Purchase by supplier export running totals
The system SHALL compute `Total nominal tagihan` per supplier in the exported file using the same expanded-row running total logic as the on-screen report.

#### Scenario: Running total resets for each supplier group
- **WHEN** the export contains rows for Supplier A followed by rows for Supplier B
- **THEN** Supplier A's `Total nominal tagihan` starts from 0 and accumulates within the A group
- **AND** Supplier B's `Total nominal tagihan` starts from 0 and accumulates within the B group independently

#### Scenario: Running total accumulates product and tax rows within a supplier group
- **WHEN** a supplier group has a product row with `Nominal tagihan` 1000 followed by a `Pajak` row with `Nominal tagihan` 110
- **THEN** the product row's `Total nominal tagihan` is 1000
- **AND** the `Pajak` row's `Total nominal tagihan` is 1110

### Requirement: Purchase by supplier export row parity
The system SHALL export the expanded rows that match the applied filters, with one product row per matching purchase detail and one additional tax row per matching purchase detail whose persisted `product_tax_amount` is greater than 0.

#### Scenario: Export row count includes tax expansion
- **WHEN** the filtered report contains two matching purchase details
- **AND** one matching purchase detail has `product_tax_amount` greater than 0
- **THEN** the exported file contains three data rows excluding header and title rows

#### Scenario: Export respects applied supplier filter
- **WHEN** a user applies a supplier filter and exports
- **THEN** the exported file contains only rows for the selected suppliers

