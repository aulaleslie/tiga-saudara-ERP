## ADDED Requirements

### Requirement: Purchase list report navigation
The system SHALL expose the purchase list report from the sidebar as `Laporan -> Pembelian -> Daftar Pembelian`, using the existing `/reports/purchase-report` route and the existing `purchaseReports.access` permission.

#### Scenario: Authorized user sees nested purchase list report menu
- **WHEN** a user with `purchaseReports.access` opens the ERP sidebar
- **THEN** the sidebar shows `Laporan -> Pembelian -> Daftar Pembelian`
- **AND** the menu item links to `/reports/purchase-report`

#### Scenario: Unauthorized user cannot see purchase list report menu
- **WHEN** a user without `purchaseReports.access` opens the ERP sidebar
- **THEN** the sidebar does not show `Daftar Pembelian`

#### Scenario: Flat purchase report menu is replaced
- **WHEN** a user with `purchaseReports.access` opens the ERP sidebar
- **THEN** the report navigation does not show a separate flat `Laporan Pembelian` entry outside the nested `Pembelian` report group

### Requirement: Purchase list report page identity
The system SHALL present the existing purchase report route as `Daftar Pembelian` in the page title and breadcrumb.

#### Scenario: Purchase report page uses purchase list naming
- **WHEN** a user opens `/reports/purchase-report`
- **THEN** the page title is `Daftar Pembelian`
- **AND** the breadcrumb identifies the active page as `Daftar Pembelian`

### Requirement: Purchase list default date range
The system SHALL initialize the purchase list report date filters to today only.

#### Scenario: Default report date range is today
- **WHEN** a user opens `/reports/purchase-report` on a given calendar date
- **THEN** `Tanggal awal` is set to that date
- **AND** `Tanggal akhir` is set to that date

### Requirement: Purchase list top filter bar
The system SHALL provide a top filter bar containing `Tanggal awal`, `Tanggal akhir`, a period preset control, `Filter`, `Filter lainnya`, and an `Ekspor` dropdown shell.

#### Scenario: Top filter bar controls are visible
- **WHEN** a user opens `/reports/purchase-report`
- **THEN** the top filter bar shows date range controls
- **AND** the top filter bar shows a period preset control
- **AND** the top filter bar shows `Filter`
- **AND** the top filter bar shows `Filter lainnya`
- **AND** the top filter bar shows an `Ekspor` dropdown shell

### Requirement: Period presets require explicit filtering
The system SHALL update pending start and end dates when a period preset is selected, but SHALL NOT rerun the report query until the user clicks `Filter`.

#### Scenario: Period preset updates dates without querying
- **WHEN** a user selects a period preset
- **THEN** the pending `Tanggal awal` and `Tanggal akhir` values update for that preset
- **AND** the displayed report results are not refreshed yet

#### Scenario: Filter applies selected period
- **WHEN** a user selects a period preset and then clicks `Filter`
- **THEN** the report results are refreshed using the preset's start and end dates

### Requirement: Advanced filters drawer
The system SHALL open a right-side `Filter lainnya` drawer containing transaction type, date basis, supplier, delivery status, and payment status filters.

#### Scenario: User opens advanced filter drawer
- **WHEN** a user clicks `Filter lainnya`
- **THEN** a right-side drawer opens
- **AND** the drawer title is `Filter laporan`

#### Scenario: Drawer contains advanced filters
- **WHEN** the `Filter lainnya` drawer is open
- **THEN** the drawer shows `Tipe transaksi`
- **AND** the drawer shows `Tanggal berdasarkan`
- **AND** the drawer shows `Supplier`
- **AND** the drawer shows `Status Pengiriman`
- **AND** the drawer shows `Status Pembayaran`

#### Scenario: User can close advanced filter drawer
- **WHEN** the `Filter lainnya` drawer is open and the user clicks `Batalkan`
- **THEN** the drawer closes without applying unsubmitted filter changes to the displayed results

### Requirement: Transaction type filter
The system SHALL include a transaction type filter in the advanced drawer and SHALL constrain v1 purchase list reporting to purchase invoices.

#### Scenario: Transaction type defaults to purchase invoice
- **WHEN** a user opens the `Filter lainnya` drawer
- **THEN** `Tipe transaksi` defaults to `Faktur Pembelian`

#### Scenario: Purchase list report returns purchase invoice rows
- **WHEN** a user applies the report filters
- **THEN** the result rows represent purchase invoice/header records

### Requirement: Date basis filter
The system SHALL let users filter the date range by transaction date or due date.

#### Scenario: User filters by transaction date
- **WHEN** `Tanggal berdasarkan` is `Tgl. transaksi` and the user clicks `Filter`
- **THEN** the report applies the date range to purchase transaction dates

#### Scenario: User filters by due date
- **WHEN** `Tanggal berdasarkan` is `Tgl. jatuh tempo` and the user clicks `Filter`
- **THEN** the report applies the date range to purchase due dates

### Requirement: Supplier multi-select filter
The system SHALL let users select multiple suppliers in the advanced drawer and SHALL include purchases whose supplier is any selected supplier.

#### Scenario: User selects multiple suppliers
- **WHEN** a user selects more than one supplier in the `Supplier` filter
- **THEN** each selected supplier is retained as part of the pending filter state

#### Scenario: Supplier filter applies any selected supplier
- **WHEN** a user selects multiple suppliers and clicks `Filter`
- **THEN** the report results include purchases whose `supplier_id` matches any selected supplier
- **AND** the report results exclude purchases from suppliers not selected

### Requirement: Delivery status filter
The system SHALL provide a `Status Pengiriman` filter using canonical purchase lifecycle statuses.

#### Scenario: Delivery status options are canonical purchase statuses
- **WHEN** a user opens the `Status Pengiriman` filter
- **THEN** the available options include `Draft`
- **AND** the available options include `Menunggu Persetujuan`
- **AND** the available options include `Disetujui`
- **AND** the available options include `Ditolak`
- **AND** the available options include `Diterima Sebagian`
- **AND** the available options include `Diterima`
- **AND** the available options include `Diretur Sebagian`
- **AND** the available options include `Diretur`

#### Scenario: Delivery status filter applies purchase status
- **WHEN** a user selects a delivery status and clicks `Filter`
- **THEN** the report results include only purchases whose `status` matches the selected canonical purchase status

### Requirement: Payment status filter
The system SHALL provide a `Status Pembayaran` filter using existing purchase payment status semantics.

#### Scenario: User filters by payment status
- **WHEN** a user selects a payment status and clicks `Filter`
- **THEN** the report results include only purchases matching that payment status according to the existing purchase report payment-status logic

### Requirement: Purchase header result rows
The system SHALL render one result row per purchase header, not one row per purchase detail/product line.

#### Scenario: Purchase with multiple details appears once
- **WHEN** a purchase has multiple purchase detail rows and the report filters include that purchase
- **THEN** the report displays that purchase as one result row

### Requirement: Export dropdown shell
The system SHALL show a sample-like `Ekspor` dropdown shell in v1 while keeping export options disabled and non-functional.

#### Scenario: Export dropdown options are disabled
- **WHEN** a user opens the `Ekspor` dropdown
- **THEN** export options are visible as disabled actions
- **AND** selecting an export option does not generate a file

### Requirement: CoreUI aligned report styling
The system SHALL use existing CoreUI/Bootstrap ERP styling for the purchase list report controls, drawer, table, and empty states.

#### Scenario: Report uses existing ERP visual conventions
- **WHEN** a user views the purchase list report
- **THEN** controls and layout use existing ERP form, button, card, drawer/offcanvas, badge, and table conventions
- **AND** the page does not depend on external sample-specific CSS classes
