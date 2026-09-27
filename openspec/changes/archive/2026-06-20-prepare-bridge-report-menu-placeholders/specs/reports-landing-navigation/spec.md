## MODIFIED Requirements

### Requirement: Categorized report tabs

The reports landing page SHALL organize reports into tabs using the Mekari taxonomy, declared in the fixed order: Sekilas bisnis, Penjualan, Pembelian, Produk, Aset, Bank, Pajak, Produksi, followed by a trailing Lainnya tab for custom tools. Tabs SHALL be rendered in this declared order. Each tab SHALL display only the report cards or placeholder cards the current user is permitted to access, and any tab with no permitted cards or placeholder cards SHALL be hidden. Bank and Aset SHALL remain hidden for this change because they are intentionally out of scope. Produksi SHALL remain hidden because no `report-sample/bridge/produksi.txt` source exists. The tab navigation SHALL be presented as underline-style tabs, with the active tab marked by a bottom border rather than a filled pill.

#### Scenario: All-permission user sees populated tabs in scoped bridge order

- **WHEN** a user with all report permissions opens the landing page
- **THEN** the Sekilas bisnis, Penjualan, Pembelian, Produk, Pajak, and Lainnya tabs are shown in that order
- **AND** the Aset, Bank, and Produksi tabs are not shown
- **AND** each visible tab contains the report cards and placeholder cards mapped to its category

#### Scenario: Restricted user sees only permitted tabs

- **WHEN** a user holds only `saleReports.access`
- **THEN** the Penjualan tab is shown with the permitted Penjualan cards and placeholder cards
- **AND** the Pembelian, Produk, Sekilas bisnis, Pajak, and Lainnya tabs are not shown
- **AND** the Penjualan Global card is not shown because it requires `saleReports.global.access`

#### Scenario: Empty tab is hidden

- **WHEN** a category would contain zero cards and zero placeholder cards for the current user's permissions
- **THEN** that tab is not rendered, regardless of its position in the declared Mekari order

#### Scenario: Future report lights up its declared tab

- **WHEN** a report card is later mapped to a currently-empty Mekari tab and the user is permitted to see it
- **THEN** that tab appears in its declared Mekari position without reordering the other tabs

### Requirement: Permission-aware report cards

Each implemented report card SHALL remain gated by its existing permission and SHALL link to the existing report route without altering that report's behavior. Each implemented card SHALL display a leading icon, a title, a descriptive sentence explaining what the report shows, and a "Lihat laporan" call-to-action. The entire implemented card SHALL remain a navigable link to its report route; the call-to-action button is a visual affordance and SHALL navigate to the same route. Bridge-derived reports without an implementation SHALL be rendered as permission-aware placeholder cards instead of route links.

#### Scenario: Existing card permission and tab mapping is preserved

- **WHEN** the landing page renders implemented cards
- **THEN** Sekilas bisnis shows Laporan Laba Rugi (gated by `reports.access`, linking to `profit-loss-report.index`)
- **AND** Penjualan shows Daftar Penjualan and Penjualan Per Customer (gated by `saleReports.access`) and Penjualan Global (gated by `saleReports.global.access`)
- **AND** Pembelian shows Daftar Pembelian and Pembelian Per Supplier (gated by `purchaseReports.access`) and Pembelian Global (gated by `purchaseReports.global.access`)
- **AND** Produk shows Mutasi Stok (gated by `stockMutationReports.access`), Mutasi Stok Global (gated by `stockMutationReports.global.access`), and Valuasi Stok (gated by `inventoryValuationReports.access`)
- **AND** Lainnya shows Mekari Converter and Mekari Invoice Generator (gated by `reports.access`)

#### Scenario: Implemented card shows description and call-to-action

- **WHEN** the landing page renders a permitted implemented report card
- **THEN** the card displays the report title, a descriptive sentence, and a "Lihat laporan" button
- **AND** both the card body and the button navigate to the report's route

#### Scenario: Implemented card navigates to existing report

- **WHEN** a user clicks a permitted implemented report card
- **THEN** the system navigates to the existing report route for that card
- **AND** the report page behaves exactly as before

#### Scenario: Bridge placeholders are appended without replacing existing cards

- **WHEN** a user with all report permissions opens the landing page
- **THEN** existing implemented report cards remain present with their current labels and links
- **AND** bridge-derived placeholder cards are appended to their matching tabs
- **AND** no existing implemented report card is renamed, removed, or converted into a placeholder

## ADDED Requirements

### Requirement: Bridge-derived placeholder report cards

The reports landing page SHALL display unimplemented bridge-derived report menu entries as disabled placeholder cards. Placeholder cards SHALL be visible only when the current user has the mapped permission, SHALL NOT require a Laravel named route to exist, SHALL NOT render as links, and SHALL show a `Belum tersedia` status. The system SHALL NOT add report implementation logic for these placeholders.

#### Scenario: Sekilas bisnis placeholders follow skip list

- **WHEN** a user with `reports.access` opens Sekilas bisnis
- **THEN** the tab includes the existing implemented Laporan Laba Rugi card
- **AND** the tab includes disabled placeholders for Neraca, Buku Besar, Arus Kas, and Neraca Saldo
- **AND** the tab does not show Jurnal
- **AND** the tab does not show Perubahan Modal
- **AND** the tab does not show Ringkasan Bisnis

#### Scenario: Penjualan bridge placeholders are visible to sales report users

- **WHEN** a user with `saleReports.access` opens Penjualan
- **THEN** existing implemented Penjualan cards remain available according to their current permissions
- **AND** disabled placeholders are shown for Piutang Pelanggan, Usia Piutang, Pengiriman Penjualan, Penjualan Per Produk, Penyelesaian Pesanan Penjualan, Daftar Faktur Proforma, and Daftar Tukar Faktur

#### Scenario: Pembelian bridge placeholders are visible to purchase report users

- **WHEN** a user with `purchaseReports.access` opens Pembelian
- **THEN** existing implemented Pembelian cards remain available according to their current permissions
- **AND** disabled placeholders are shown for Utang Supplier, Daftar Pengeluaran, Detail Pengeluaran, Usia Utang, Pengiriman Pembelian, Pembelian Per Produk, and Penyelesaian Pesanan Pembelian

#### Scenario: Produk bridge placeholders are visible to product report users

- **WHEN** a user with the relevant Produk report permissions opens Produk
- **THEN** existing implemented Produk cards remain available according to their current permissions
- **AND** disabled placeholders are shown for Ringkasan Persediaan Barang, Kuantitas Stok Gudang, Nilai Persediaan Barang, Nilai Stok Gudang, Detail Persediaan Barang, and Pergerakan Barang Gudang

#### Scenario: Pajak bridge placeholders are visible to general report users

- **WHEN** a user with `reports.access` opens Pajak
- **THEN** disabled placeholders are shown for Pajak Pemotongan and Pajak Penjualan
- **AND** neither placeholder links to a report route

#### Scenario: Out-of-scope tabs and cards remain absent

- **WHEN** a user with all report permissions opens the landing page
- **THEN** Bank is not shown
- **AND** Aset is not shown
- **AND** Produksi is not shown
- **AND** no Bank, Aset, or Produksi placeholder cards are rendered

#### Scenario: Placeholder card does not navigate

- **WHEN** the landing page renders a permitted placeholder card
- **THEN** the card displays its title, bridge-derived description, and `Belum tersedia` status
- **AND** the card does not render an anchor to a report route
- **AND** the card does not show an enabled `Lihat laporan` action
