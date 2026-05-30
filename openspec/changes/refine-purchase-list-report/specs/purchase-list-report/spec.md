## ADDED Requirements

### Requirement: Purchase detail result rows
The system SHALL render one result row per purchase detail/product line for `Faktur Pembelian`, repeating the related purchase header fields on each line.

#### Scenario: Purchase with multiple details appears as multiple report rows
- **WHEN** a purchase has three purchase detail rows and the report filters include that purchase
- **THEN** the report displays three result rows for that purchase
- **AND** each row shows the matching product/detail data for one purchase detail

#### Scenario: Purchase header fields repeat on detail rows
- **WHEN** a purchase detail row is displayed
- **THEN** the row includes the purchase header date, transaction number, supplier, document status, payment status, totals, due date, supplier purchase number, and tags

### Requirement: Purchase detail report columns
The system SHALL display purchase detail report columns using Bahasa Indonesia labels matching the imported sample, with `Nomor Pembelian Supplier` included as a separate ERP-specific transaction reference column.

#### Scenario: Report displays required columns
- **WHEN** a user runs the `Daftar Pembelian` report
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
- **AND** the table includes `Email`
- **AND** the table includes `Alamat Penagihan`
- **AND** the table includes `Alamat Pengiriman`
- **AND** the table includes `No Ref`
- **AND** the table includes `Tag`
- **AND** the table includes `Gudang`
- **AND** the table includes `Nama Produk`
- **AND** the table includes `Kode Produk`
- **AND** the table includes `Deskripsi`
- **AND** the table includes `Kuantitas`
- **AND** the table includes `Satuan`
- **AND** the table includes `Harga per Unit`
- **AND** the table includes `Diskon Per Baris %`
- **AND** the table includes `Tarif Pajak`
- **AND** the table includes `Jumlah Pajak`
- **AND** the table includes `Jumlah Kena Pajak per Baris`
- **AND** the table includes `Jumlah Per Baris`
- **AND** the table includes `Diskon`
- **AND** the table includes `Pesan`
- **AND** the table includes `Biaya Pengiriman`
- **AND** the table includes `Jumlah Pemotongan`
- **AND** the table includes `Nama Perusahaan`
- **AND** the table includes `Nomor Pajak`
- **AND** the table includes `Nomor Ponsel`
- **AND** the table includes `Nomor Telepon`
- **AND** the table includes `Sisa Tagihan Hari Ini`
- **AND** the table includes `Diskon %`

#### Scenario: Missing optional source data displays safely
- **WHEN** a report column has no available source value for a row
- **THEN** the system displays an empty value or `-`
- **AND** the system MUST NOT fabricate source data

### Requirement: Bahasa Indonesia report wording
The system SHALL use Bahasa Indonesia for all user-facing report labels, filter names, buttons, status labels, placeholders, empty states, and validation messages on `Daftar Pembelian`.

#### Scenario: Report controls use Bahasa Indonesia
- **WHEN** a user views `Daftar Pembelian`
- **THEN** visible controls use Bahasa Indonesia wording
- **AND** English labels such as `Purchase Invoice`, `Delivery Status`, and `Payment Status` are not shown as primary report labels

### Requirement: Searchable tag multi-select filter
The system SHALL provide a searchable multi-select `Grup dengan tag` filter that does not preload the full tag dataset and matches purchases that have any selected tag.

#### Scenario: Tag lookup waits for sufficient input
- **WHEN** a user types fewer than two characters in the `Grup dengan tag` search field
- **THEN** the system does not query and display tag suggestions

#### Scenario: User selects multiple tags
- **WHEN** a user searches and selects more than one tag
- **THEN** each selected tag is retained as a removable selected filter value

#### Scenario: Tag filter applies OR matching
- **WHEN** a user selects multiple tags and clicks `Filter`
- **THEN** the report includes purchase detail rows whose purchase has at least one selected tag
- **AND** the report excludes purchase detail rows whose purchase has none of the selected tags

### Requirement: Document status multi-select filter
The system SHALL provide a multi-select `Status Dokumen` filter using canonical purchase lifecycle statuses with Bahasa Indonesia labels.

#### Scenario: Document status options are canonical purchase statuses
- **WHEN** a user opens the `Status Dokumen` filter
- **THEN** the available options include `Draf`
- **AND** the available options include `Menunggu Persetujuan`
- **AND** the available options include `Ditolak`
- **AND** the available options include `Disetujui`
- **AND** the available options include `Diterima Sebagian`
- **AND** the available options include `Diterima`
- **AND** the available options include `Diretur Sebagian`
- **AND** the available options include `Diretur`

#### Scenario: Document status filter applies OR matching
- **WHEN** a user selects multiple document statuses and clicks `Filter`
- **THEN** the report includes purchase detail rows whose purchase document status matches any selected document status
- **AND** the report excludes purchase detail rows whose purchase document status matches none of the selected document statuses

### Requirement: Receiving location display column
The system SHALL populate the `Gudang` column from approved receiving-note locations related to the purchase detail when available, without providing a `Gudang` filter.

#### Scenario: Detail row has one approved receiving location
- **WHEN** a purchase detail has approved receiving activity in one location
- **THEN** the `Gudang` column displays that location name

#### Scenario: Detail row has multiple approved receiving locations
- **WHEN** a purchase detail has approved receiving activity in multiple locations
- **THEN** the `Gudang` column displays the distinct location names together
- **AND** the system does not duplicate the purchase detail row solely because of multiple receiving locations

#### Scenario: Location filter is not shown
- **WHEN** a user opens the report filters
- **THEN** the system does not show a `Gudang` or receiving location filter

### Requirement: Payment status source of truth
The system SHALL derive report payment status from active purchase payment transactions and purchase total amount.

#### Scenario: No active payments means unpaid
- **WHEN** a purchase has no active payment amount
- **THEN** its report payment status is `Belum Dibayar`

#### Scenario: Partial active payments mean partially paid
- **WHEN** a purchase has active payment amount greater than zero and less than the purchase total
- **THEN** its report payment status is `Terbayar Sebagian`

#### Scenario: Active payments cover total means paid
- **WHEN** a purchase has active payment amount greater than or equal to the purchase total
- **THEN** its report payment status is `Lunas`

## MODIFIED Requirements

### Requirement: Purchase list default date range
The system SHALL initialize the purchase list report date filters to the current calendar month.

#### Scenario: Default report date range is current month
- **WHEN** a user opens `/reports/purchase-report` on a given calendar date
- **THEN** `Tanggal awal` is set to the first day of that calendar month
- **AND** `Tanggal akhir` is set to the last day of that calendar month

### Requirement: Advanced filters drawer
The system SHALL open a right-side `Filter lainnya` drawer containing date basis, searchable supplier multi-select, document status multi-select, payment status multi-select, and searchable tag multi-select filters.

#### Scenario: User opens advanced filter drawer
- **WHEN** a user clicks `Filter lainnya`
- **THEN** a right-side drawer opens
- **AND** the drawer title is `Filter laporan`

#### Scenario: Drawer contains advanced filters
- **WHEN** the `Filter lainnya` drawer is open
- **THEN** the drawer shows `Tanggal berdasarkan`
- **AND** the drawer shows `Supplier`
- **AND** the drawer shows `Status Dokumen`
- **AND** the drawer shows `Status Pembayaran`
- **AND** the drawer shows `Grup dengan tag`
- **AND** the drawer does not show `Tipe transaksi`
- **AND** the drawer does not show a product filter
- **AND** the drawer does not show a `Gudang` filter

#### Scenario: User can close advanced filter drawer
- **WHEN** the `Filter lainnya` drawer is open and the user clicks `Batalkan`
- **THEN** the drawer closes without applying unsubmitted filter changes to the displayed results

### Requirement: Supplier multi-select filter
The system SHALL let users search and select multiple suppliers in the advanced drawer without preloading the full supplier dataset, and SHALL include purchase detail rows whose purchase supplier is any selected supplier.

#### Scenario: Supplier lookup waits for sufficient input
- **WHEN** a user types fewer than two characters in the `Supplier` search field
- **THEN** the system does not query and display supplier suggestions

#### Scenario: User selects multiple suppliers
- **WHEN** a user searches and selects more than one supplier in the `Supplier` filter
- **THEN** each selected supplier is retained as a removable selected filter value

#### Scenario: Supplier filter applies any selected supplier
- **WHEN** a user selects multiple suppliers and clicks `Filter`
- **THEN** the report results include purchase detail rows whose purchase `supplier_id` matches any selected supplier
- **AND** the report results exclude purchase detail rows from suppliers not selected

### Requirement: Payment status filter
The system SHALL provide a multi-select `Status Pembayaran` filter using derived active-payment status semantics.

#### Scenario: Payment status options are canonical report statuses
- **WHEN** a user opens the `Status Pembayaran` filter
- **THEN** the available options include `Belum Dibayar`
- **AND** the available options include `Terbayar Sebagian`
- **AND** the available options include `Lunas`

#### Scenario: User filters by multiple payment statuses
- **WHEN** a user selects multiple payment statuses and clicks `Filter`
- **THEN** the report results include purchase detail rows whose derived payment status matches any selected payment status
- **AND** the report results exclude purchase detail rows whose derived payment status matches none of the selected payment statuses

## REMOVED Requirements

### Requirement: Transaction type filter
**Reason**: The refined report is fixed to `Faktur Pembelian`, and a one-option transaction type selector adds noise while implying unsupported report modes.

**Migration**: Treat transaction type as an internal fixed report contract value if needed; do not render a user-facing `Tipe transaksi` filter.

### Requirement: Delivery status filter
**Reason**: The report must separate document lifecycle status from payment status using clearer Bahasa Indonesia terminology. `Status Pengiriman` is replaced by `Status Dokumen`.

**Migration**: Use the new `Status Dokumen` multi-select filter with canonical purchase lifecycle statuses.

### Requirement: Purchase header result rows
**Reason**: The imported purchase report sample is detail-line oriented and requires product-level columns that cannot be represented correctly by one header row per purchase.

**Migration**: Use the new purchase detail row requirement. Existing header-level filter rules should be adapted to filter the parent purchase while returning matching purchase detail rows.
