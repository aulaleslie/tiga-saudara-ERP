# reports-landing-navigation Specification

## Purpose

Replace the nested "Laporan" sidebar dropdown with a single permission-gated reports landing page that organizes report cards into Mekari-taxonomy tabs, showing only the cards and tabs each user is permitted to access and linking to existing report routes without altering their behavior.

## Requirements

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

The reports landing page SHALL organize reports into tabs using the Mekari taxonomy, declared in the fixed order: Sekilas bisnis, Penjualan, Pembelian, Produk, Aset, Bank, Pajak, Produksi, followed by a trailing Lainnya tab for custom tools. Tabs SHALL be rendered in this declared order. Each tab SHALL display only the report cards the current user is permitted to access, and any tab with no permitted cards SHALL be hidden (including tabs that currently have no reports mapped, such as Aset, Bank, and Produksi). The Pajak tab SHALL be shown when the current user is permitted to access at least one Pajak report card, including the actionable Pajak penjualan report card. The tab navigation SHALL be presented as underline-style tabs, with the active tab marked by a bottom border rather than a filled pill.

#### Scenario: All-permission user sees all populated tabs in Mekari order

- **WHEN** a user with all report permissions opens the landing page
- **THEN** the Sekilas bisnis, Penjualan, Pembelian, Produk, Pajak, and Lainnya tabs are shown in that order
- **AND** the Aset, Bank, and Produksi tabs are not shown because they have no mapped reports
- **AND** each visible tab contains the report cards mapped to its category

#### Scenario: Restricted user sees only permitted tabs

- **WHEN** a user holds only `saleReports.access`
- **THEN** the Penjualan tab is shown with the Daftar Penjualan and Penjualan Per Customer cards
- **AND** the Pembelian, Produk, Sekilas bisnis, Pajak, and Lainnya tabs are not shown
- **AND** the Penjualan Global card is not shown (it requires `saleReports.global.access`)

#### Scenario: Empty tab is hidden

- **WHEN** a category would contain zero cards for the current user's permissions
- **THEN** that tab is not rendered, regardless of its position in the declared Mekari order

#### Scenario: Future report lights up its declared tab

- **WHEN** a report card is later mapped to a currently-empty Mekari tab (e.g. Aset) and the user is permitted to see it
- **THEN** that tab appears in its declared Mekari position without reordering the other tabs

### Requirement: Permission-aware report cards

Each report card SHALL be gated by the same permission as its corresponding sidebar entry was previously, and SHALL link to the existing report route without altering that report's behavior. Each card SHALL display a leading icon, a title, a descriptive sentence explaining what the report shows, and a "Lihat laporan" call-to-action. The entire card SHALL remain a navigable link to its report route; the call-to-action button is a visual affordance and SHALL navigate to the same route. The "Penjualan per produk" sales by product report card SHALL be included in the Penjualan tab, gated by `saleReports.access`, and remain actionable without placeholder treatment. The "Usia utang" aged payables report card SHALL be included in the Pembelian tab, gated by `purchaseReports.access`, and remain actionable without placeholder treatment. The "Pengiriman pembelian" purchase delivery report card SHALL be included in the Pembelian tab, gated by `purchaseReports.access`, and remain actionable without placeholder treatment. The "Pembelian per produk" purchase by product report card SHALL be included in the Pembelian tab, gated by `purchaseReports.access`, and remain actionable without placeholder treatment. The "Ringkasan persediaan barang" inventory summary report card SHALL be included in the Produk tab, gated by `inventoryValuationReports.access`, and remain actionable without placeholder treatment. The "Kuantitas stok gudang" warehouse stock quantity report card SHALL be included in the Produk tab, gated by `stockMutationReports.access`, and remain actionable without placeholder treatment. The "Nilai persediaan barang" inventory valuation report card SHALL be included in the Produk tab, gated by `inventoryValuationReports.access`, linking to `reports.inventory-valuation-report.index`, and remain actionable without placeholder treatment. The "Detail persediaan barang" inventory detail report card SHALL be included in the Produk tab, gated by `stockMutationReports.access`, linking to `reports.inventory-detail-report.index`, and remain actionable without placeholder treatment. The "Pajak penjualan" sales tax report card SHALL be included in the Pajak tab, gated by `reports.access`, linking to the sales tax report route, and remain actionable without placeholder treatment.

#### Scenario: Card permission and tab mapping is preserved

- **WHEN** the landing page renders cards
- **THEN** Sekilas bisnis shows Laporan Laba Rugi (gated by `reports.access`, linking to `profit-loss-report.index`)
- **AND** Sekilas bisnis shows Neraca (gated by `reports.access`, linking to `operational-balance-sheet-report.index`)
- **AND** Sekilas bisnis shows Buku Besar (gated by `reports.access`, linking to the Buku Besar report route)
- **AND** Sekilas bisnis shows Arus kas (gated by `reports.access`, linking to the Arus Kas report route)
- **AND** Penjualan shows Daftar Penjualan, Penjualan Per Customer, Piutang pelanggan, Usia piutang, Pengiriman penjualan, and Penjualan per produk (gated by `saleReports.access`) and Penjualan Global (gated by `saleReports.global.access`)
- **AND** Pembelian shows Daftar Pembelian, Pembelian Per Supplier, Utang supplier, Daftar pengeluaran, Usia utang, Pengiriman pembelian, and Pembelian per produk (gated by `purchaseReports.access`) and Pembelian Global (gated by `purchaseReports.global.access`)
- **AND** Produk shows Mutasi Stok (gated by `stockMutationReports.access`), Mutasi Stok Global (gated by `stockMutationReports.global.access`), Kuantitas stok gudang (gated by `stockMutationReports.access`), Ringkasan persediaan barang (gated by `inventoryValuationReports.access`), Nilai persediaan barang (gated by `inventoryValuationReports.access`), and Detail persediaan barang (gated by `stockMutationReports.access`)
- **AND** Pajak shows Pajak penjualan (gated by `reports.access`)
- **AND** Lainnya shows Mekari Converter and Mekari Invoice Generator (gated by `reports.access`)

#### Scenario: Card shows description and call-to-action

- **WHEN** the landing page renders a permitted report card
- **THEN** the card displays the report title, a descriptive sentence, and a "Lihat laporan" button
- **AND** both the card body and the button navigate to the report's route

#### Scenario: Card navigates to existing report

- **WHEN** a user clicks a permitted report card
- **THEN** the system navigates to the existing report route for that card
- **AND** the report page behaves exactly as before

#### Scenario: Arus kas card is actionable

- **WHEN** a user with `reports.access` views the Sekilas bisnis tab
- **THEN** the Arus kas card is rendered as an actionable report link
- **AND** the Arus kas card does not show placeholder or unavailable-state treatment

#### Scenario: Pengiriman penjualan card is actionable

- **WHEN** a user with `saleReports.access` views the Penjualan tab
- **THEN** the Pengiriman penjualan card is rendered as an actionable report link
- **AND** the Pengiriman penjualan card does not show placeholder or unavailable-state treatment

#### Scenario: Penjualan per produk card is actionable

- **WHEN** a user with `saleReports.access` views the Penjualan tab
- **THEN** the Penjualan per produk card is rendered as an actionable report link
- **AND** the Penjualan per produk card does not show placeholder or unavailable-state treatment

#### Scenario: Usia utang card is actionable

- **WHEN** a user with `purchaseReports.access` views the Pembelian tab
- **THEN** the Usia utang card is rendered as an actionable report link
- **AND** the Usia utang card does not show placeholder or unavailable-state treatment

#### Scenario: Pembelian per produk card is actionable

- **WHEN** a user with `purchaseReports.access` views the Pembelian tab
- **THEN** the Pembelian per produk card is rendered as an actionable report link
- **AND** the Pembelian per produk card does not show placeholder or unavailable-state treatment

#### Scenario: Ringkasan persediaan barang card is actionable

- **WHEN** a user with `inventoryValuationReports.access` views the Produk tab
- **THEN** the Ringkasan persediaan barang card is rendered as an actionable report link
- **AND** the Ringkasan persediaan barang card does not show placeholder or unavailable-state treatment

#### Scenario: Kuantitas stok gudang card is actionable

- **WHEN** a user with `stockMutationReports.access` views the Produk tab
- **THEN** the Kuantitas stok gudang card is rendered as an actionable report link
- **AND** the Kuantitas stok gudang card does not show placeholder or unavailable-state treatment

#### Scenario: Nilai persediaan barang card is actionable

- **WHEN** a user with `inventoryValuationReports.access` views the Produk tab
- **THEN** the Nilai persediaan barang card is rendered as an actionable report link to `reports.inventory-valuation-report.index`
- **AND** the Nilai persediaan barang card does not show placeholder or unavailable-state treatment

#### Scenario: Detail persediaan barang card is actionable

- **WHEN** a user with `stockMutationReports.access` views the Produk tab
- **THEN** the Detail persediaan barang card is rendered as an actionable report link to `reports.inventory-detail-report.index`
- **AND** the Detail persediaan barang card does not show placeholder or unavailable-state treatment

#### Scenario: Pajak penjualan card is actionable

- **WHEN** a user with `reports.access` views the Pajak tab
- **THEN** the Pajak penjualan card is rendered as an actionable report link to the sales tax report route
- **AND** the Pajak penjualan card does not show placeholder or unavailable-state treatment

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
