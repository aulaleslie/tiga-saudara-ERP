## Purpose
Enable Excel and CSV export for the Daftar Pembelian (Purchase List) report, allowing authorized users to export filtered and sorted results matching the current table UI.

## ADDED Requirements

### Requirement: Purchase list Excel and CSV export
The system SHALL allow authorized users to export the `Daftar Pembelian` report to Excel and CSV from the existing report export control.

#### Scenario: User exports Excel after filtering
- **WHEN** a user applies valid `Daftar Pembelian` filters and selects the Excel export action
- **THEN** the system downloads an `.xlsx` file
- **AND** the file contains the purchase detail rows matching the last successfully applied filters
- **AND** the file is not limited to the current paginated page

#### Scenario: User exports CSV after filtering
- **WHEN** a user applies valid `Daftar Pembelian` filters and selects the CSV export action
- **THEN** the system downloads a `.csv` file
- **AND** the file contains the purchase detail rows matching the last successfully applied filters
- **AND** the file is not limited to the current paginated page

#### Scenario: Export is blocked before a report is applied
- **WHEN** a user opens `Daftar Pembelian` and selects an export action before successfully applying filters
- **THEN** the system does not generate a file
- **AND** the user is notified that the report must be filtered before export

#### Scenario: Pending filter changes are not exported
- **WHEN** a user has applied filters and then changes filter inputs without clicking `Filter`
- **THEN** an export uses the last successfully applied filters
- **AND** the export does not include rows based on the unapplied filter inputs

### Requirement: Purchase list export column contract
The system SHALL export the same column labels and order as the current `Daftar Pembelian` table.

#### Scenario: Export contains current table columns
- **WHEN** a user exports the report
- **THEN** the exported file includes `Tanggal`
- **AND** the exported file includes `Nomor Transaksi`
- **AND** the exported file includes `Nomor Pembelian Supplier`
- **AND** the exported file includes `Nama Panggilan`
- **AND** the exported file includes `Status Dokumen`
- **AND** the exported file includes `Status Pembayaran`
- **AND** the exported file includes `Memo`
- **AND** the exported file includes `Total`
- **AND** the exported file includes `Sisa Tagihan`
- **AND** the exported file includes `Tanggal Jatuh Tempo`
- **AND** the exported file includes `Jumlah Kena Pajak`
- **AND** the exported file includes `Total Pajak`
- **AND** the exported file includes `Pembayaran`
- **AND** the exported file includes `Email`
- **AND** the exported file includes `Alamat Penagihan`
- **AND** the exported file includes `Alamat Pengiriman`
- **AND** the exported file includes `No Ref`
- **AND** the exported file includes `Tag`
- **AND** the exported file includes `Gudang`
- **AND** the exported file includes `Nama Produk`
- **AND** the exported file includes `Kode Produk`
- **AND** the exported file includes `Deskripsi`
- **AND** the exported file includes `Kuantitas`
- **AND** the exported file includes `Satuan`
- **AND** the exported file includes `Harga per Unit`
- **AND** the exported file includes `Diskon Per Baris %`
- **AND** the exported file includes `Tarif Pajak`
- **AND** the exported file includes `Jumlah Pajak`
- **AND** the exported file includes `Jumlah Kena Pajak per Baris`
- **AND** the exported file includes `Jumlah Per Baris`
- **AND** the exported file includes `Diskon`
- **AND** the exported file includes `Pesan`
- **AND** the exported file includes `Biaya Pengiriman`
- **AND** the exported file includes `Jumlah Pemotongan`
- **AND** the exported file includes `Nama Perusahaan`
- **AND** the exported file includes `Nomor Pajak`
- **AND** the exported file includes `Nomor Ponsel`
- **AND** the exported file includes `Nomor Telepon`
- **AND** the exported file includes `Sisa Tagihan Hari Ini`
- **AND** the exported file includes `Diskon %`

#### Scenario: Export values are spreadsheet friendly
- **WHEN** a report row has amount, quantity, or percentage values
- **THEN** those values are exported as raw numeric values
- **AND** percentage values do not include a percent sign suffix

#### Scenario: Missing optional values export consistently
- **WHEN** an exported column has no available source value for a row
- **THEN** the exported cell contains `-`
- **AND** the system MUST NOT fabricate source data

### Requirement: Purchase list export sorting
The system SHALL export rows using the current `Daftar Pembelian` table sort field and direction.

#### Scenario: Export follows selected sort
- **WHEN** a user sorts the report table by a supported sortable column and exports the report
- **THEN** the exported rows use the selected sort field and direction
- **AND** stable tie-breaker ordering is applied consistently for rows with equal sort values

### Requirement: Purchase list export file format
The system SHALL generate purchase list export files using the agreed report-specific file structure and filenames.

#### Scenario: CSV starts with column headers
- **WHEN** a user exports CSV
- **THEN** the first row of the CSV file contains the report column headers
- **AND** the CSV does not include report metadata rows before the headers

#### Scenario: Excel includes metadata rows
- **WHEN** a user exports Excel
- **THEN** the workbook includes report metadata rows above the table headers
- **AND** the table headers and data rows appear below those metadata rows

#### Scenario: Export filename uses selected date range
- **WHEN** a user exports a report for `01/05/2026` through `31/05/2026`
- **THEN** the downloaded filename is `purchases_list_01-05-2026_31-05-2026.xlsx` for Excel
- **AND** the downloaded filename is `purchases_list_01-05-2026_31-05-2026.csv` for CSV

### Requirement: Purchase list PDF export remains unavailable
The system SHALL keep PDF export unavailable for `Daftar Pembelian`.

#### Scenario: User cannot generate PDF export
- **WHEN** a user opens the `Ekspor` control
- **THEN** PDF is either hidden or disabled
- **AND** selecting available export actions cannot generate a PDF file
