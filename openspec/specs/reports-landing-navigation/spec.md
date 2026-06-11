## ADDED Requirements

### Requirement: Reports landing page entry point

The system SHALL provide a single "Laporan" sidebar entry that links to a reports landing page at the route `reports.index`. The previous nested "Laporan" dropdown tree (including the Pembelian and Penjualan sub-dropdowns and individual report links) SHALL be removed from the sidebar.

#### Scenario: Sidebar shows a single Laporan link

- **WHEN** an authenticated user with at least one report permission views the sidebar
- **THEN** a single "Laporan" link is shown that navigates to the `reports.index` route
- **AND** no nested report sub-dropdowns are rendered in the sidebar

#### Scenario: Sidebar hides Laporan for users without any report permission

- **WHEN** an authenticated user holds none of the report permissions (`reports.access`, `saleReports.access`, `saleReports.global.access`, `purchaseReports.access`, `purchaseReports.global.access`, `stockMutationReports.access`, `stockMutationReports.global.access`, `inventoryValuationReports.access`)
- **THEN** the "Laporan" sidebar link is not rendered

#### Scenario: Landing route is permission-gated

- **WHEN** a user without any report permission requests the `reports.index` route directly
- **THEN** the system denies access

### Requirement: Categorized report tabs

The reports landing page SHALL organize reports into tabs: Laba/Rugi, Penjualan, Pembelian, Stock, and Lainnya. Each tab SHALL display only the report cards the current user is permitted to access, and any tab with no permitted cards SHALL be hidden.

#### Scenario: All-permission user sees all populated tabs

- **WHEN** a user with all report permissions opens the landing page
- **THEN** the Laba/Rugi, Penjualan, Pembelian, Stock, and Lainnya tabs are all shown
- **AND** each tab contains the report cards mapped to its category

#### Scenario: Restricted user sees only permitted tabs

- **WHEN** a user holds only `saleReports.access`
- **THEN** the Penjualan tab is shown with the Daftar Penjualan and Penjualan Per Customer cards
- **AND** the Pembelian, Stock, Laba/Rugi, and Lainnya tabs are not shown
- **AND** the Penjualan Global card is not shown (it requires `saleReports.global.access`)

#### Scenario: Empty tab is hidden

- **WHEN** a category would contain zero cards for the current user's permissions
- **THEN** that tab is not rendered

### Requirement: Permission-aware report cards

Each report card SHALL be gated by the same permission as its corresponding sidebar entry was previously, and SHALL link to the existing report route without altering that report's behavior.

#### Scenario: Card permission mapping is preserved

- **WHEN** the landing page renders cards
- **THEN** Laba/Rugi shows Laporan Laba Rugi (gated by `reports.access`, linking to `profit-loss-report.index`)
- **AND** Penjualan shows Daftar Penjualan and Penjualan Per Customer (gated by `saleReports.access`) and Penjualan Global (gated by `saleReports.global.access`)
- **AND** Pembelian shows Daftar Pembelian and Pembelian Per Supplier (gated by `purchaseReports.access`) and Pembelian Global (gated by `purchaseReports.global.access`)
- **AND** Stock shows Mutasi Stok (gated by `stockMutationReports.access`), Mutasi Stok Global (gated by `stockMutationReports.global.access`), and Valuasi Stok (gated by `inventoryValuationReports.access`)
- **AND** Lainnya shows Mekari Converter and Mekari Invoice Generator (gated by `reports.access`)

#### Scenario: Card navigates to existing report

- **WHEN** a user clicks a permitted report card
- **THEN** the system navigates to the existing report route for that card
- **AND** the report page behaves exactly as before

### Requirement: Tab selection via query parameter

The active tab SHALL be selected via a `tab` query parameter on the landing route. When the parameter is missing or names a tab the user cannot see, the system SHALL fall back to the first tab the user is permitted to view.

#### Scenario: Explicit tab selection

- **WHEN** a permitted user requests `reports.index?tab=pembelian`
- **THEN** the Pembelian tab is active and its cards are displayed

#### Scenario: Missing tab parameter defaults to first visible tab

- **WHEN** a user opens the landing page without a `tab` parameter
- **THEN** the first tab the user is permitted to view is active

#### Scenario: Invalid or unauthorized tab falls back

- **WHEN** a user requests a `tab` value that does not exist or that they are not permitted to view
- **THEN** the system activates the first tab the user is permitted to view instead
