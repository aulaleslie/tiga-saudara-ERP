## ADDED Requirements

### Requirement: Purchase report mode selection
The system SHALL provide a `Mode Laporan` control on `Daftar Pembelian` with `Detail` and `Header` options.

#### Scenario: Detail mode is default
- **WHEN** a user opens `Daftar Pembelian` without a previously selected valid report mode
- **THEN** `Mode Laporan` is set to `Detail`
- **AND** the report uses the existing purchase detail/product-line result behavior after filtering

#### Scenario: User selects header mode
- **WHEN** a user changes `Mode Laporan` to `Header` and applies filters
- **THEN** the report displays header-mode results
- **AND** the selected mode is included in the applied report state

#### Scenario: Invalid mode falls back safely
- **WHEN** the report is loaded with an unsupported mode value
- **THEN** the system uses `Detail` mode
- **AND** the report remains usable

### Requirement: Purchase header report rows
The system SHALL support a `Header` mode on `Daftar Pembelian` that renders one result row per purchase invoice matching the applied filters.

#### Scenario: Purchase with multiple details appears once in header mode
- **WHEN** a purchase has three purchase detail rows and the header-mode report filters include that purchase
- **THEN** the report displays one row for that purchase
- **AND** the row shows purchase invoice header information rather than product-line information

#### Scenario: Header mode keeps purchase filtering semantics
- **WHEN** a user applies date range, date basis, supplier, tag, document status, or payment status filters in header mode
- **THEN** the report includes only purchases matching those filters
- **AND** the report applies the same access scope rules as detail mode

#### Scenario: Header mode payment status uses active payments
- **WHEN** a purchase appears in header mode
- **THEN** `Status Pembayaran`, `Pembayaran`, and `Sisa Tagihan` are derived from active purchase payments using the same source-of-truth semantics as detail mode

### Requirement: Purchase header report columns
The system SHALL display concise invoice-level columns in `Header` mode.

#### Scenario: Header mode displays concise columns
- **WHEN** a user runs `Daftar Pembelian` in `Header` mode
- **THEN** the table includes `Tanggal`
- **AND** the table includes `Nomor Transaksi`
- **AND** the table includes `Nomor Pembelian Supplier`
- **AND** the table includes `Nama Panggilan`
- **AND** the table includes `Status Dokumen`
- **AND** the table includes `Status Pembayaran`
- **AND** the table includes `Memo`
- **AND** the table includes `Total`
- **AND** the table includes `Sisa Tagihan`
- **AND** the table includes `Tanggal Jatuh Tempo`
- **AND** the table includes `Jumlah Kena Pajak`
- **AND** the table includes `Total Pajak`
- **AND** the table includes `Pembayaran`
- **AND** the table includes `No Ref`
- **AND** the table includes `Tag`

#### Scenario: Header mode omits product columns
- **WHEN** a user runs `Daftar Pembelian` in `Header` mode
- **THEN** the table does not show product-line columns such as `Nama Produk`, `Kode Produk`, `Kuantitas`, `Satuan`, `Harga per Unit`, or `Jumlah Per Baris`

### Requirement: Purchase report mode persistence
The system SHALL remember the selected `Mode Laporan` through query string and/or session state.

#### Scenario: Mode persists through pagination and sorting
- **WHEN** a user applies filters in `Header` mode
- **AND** the user paginates or sorts the report
- **THEN** the report remains in `Header` mode

#### Scenario: Mode persists after page reload
- **WHEN** a user has selected a valid report mode and reloads the report page
- **THEN** the system restores that selected mode from query string or session state

## MODIFIED Requirements

### Requirement: Purchase list Excel and CSV export
The system SHALL allow authorized users to export the `Daftar Pembelian` report to Excel and CSV from the existing report export control, using the currently applied report mode.

#### Scenario: User exports Excel after filtering
- **WHEN** a user applies valid `Daftar Pembelian` filters and selects the Excel export action
- **THEN** the system downloads an `.xlsx` file
- **AND** the file contains rows matching the last successfully applied filters and report mode
- **AND** the file is not limited to the current paginated page

#### Scenario: User exports CSV after filtering
- **WHEN** a user applies valid `Daftar Pembelian` filters and selects the CSV export action
- **THEN** the system downloads a `.csv` file
- **AND** the file contains rows matching the last successfully applied filters and report mode
- **AND** the file is not limited to the current paginated page

#### Scenario: Export is blocked before a report is applied
- **WHEN** a user opens `Daftar Pembelian` and selects an export action before successfully applying filters
- **THEN** the system does not generate a file
- **AND** the user is notified that the report must be filtered before export

#### Scenario: Pending filter or mode changes are not exported
- **WHEN** a user has applied filters and then changes filter inputs or report mode without clicking `Filter`
- **THEN** an export uses the last successfully applied filters and report mode
- **AND** the export does not include rows based on unapplied filter or mode inputs

### Requirement: Purchase list export column contract
The system SHALL export the same column labels and order as the current `Daftar Pembelian` table for the selected report mode.

#### Scenario: Detail mode export contains detail table columns
- **WHEN** a user exports the report in `Detail` mode
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

#### Scenario: Header mode export contains header table columns
- **WHEN** a user exports the report in `Header` mode
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
- **AND** the exported file includes `No Ref`
- **AND** the exported file includes `Tag`
- **AND** the exported file does not include product-line columns such as `Nama Produk`, `Kode Produk`, `Kuantitas`, `Satuan`, `Harga per Unit`, or `Jumlah Per Baris`

#### Scenario: Export values are spreadsheet friendly
- **WHEN** a report row has amount, quantity, or percentage values
- **THEN** those values are exported as raw numeric values
- **AND** percentage values do not include a percent sign suffix

#### Scenario: Missing optional values export consistently
- **WHEN** an exported column has no available source value for a row
- **THEN** the exported cell contains `-`
- **AND** the system MUST NOT fabricate source data
