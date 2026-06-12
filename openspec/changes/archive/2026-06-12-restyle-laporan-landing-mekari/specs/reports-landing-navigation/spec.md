## MODIFIED Requirements

### Requirement: Categorized report tabs

The reports landing page SHALL organize reports into tabs using the Mekari taxonomy, declared in the fixed order: Sekilas bisnis, Penjualan, Pembelian, Produk, Aset, Bank, Pajak, Produksi, followed by a trailing Lainnya tab for custom tools. Tabs SHALL be rendered in this declared order. Each tab SHALL display only the report cards the current user is permitted to access, and any tab with no permitted cards SHALL be hidden (including tabs that currently have no reports mapped, such as Aset, Bank, Pajak, and Produksi). The tab navigation SHALL be presented as underline-style tabs, with the active tab marked by a bottom border rather than a filled pill.

#### Scenario: All-permission user sees all populated tabs in Mekari order

- **WHEN** a user with all report permissions opens the landing page
- **THEN** the Sekilas bisnis, Penjualan, Pembelian, Produk, and Lainnya tabs are shown in that order
- **AND** the Aset, Bank, Pajak, and Produksi tabs are not shown because they have no mapped reports
- **AND** each visible tab contains the report cards mapped to its category

#### Scenario: Restricted user sees only permitted tabs

- **WHEN** a user holds only `saleReports.access`
- **THEN** the Penjualan tab is shown with the Daftar Penjualan and Penjualan Per Customer cards
- **AND** the Pembelian, Produk, Sekilas bisnis, and Lainnya tabs are not shown
- **AND** the Penjualan Global card is not shown (it requires `saleReports.global.access`)

#### Scenario: Empty tab is hidden

- **WHEN** a category would contain zero cards for the current user's permissions
- **THEN** that tab is not rendered, regardless of its position in the declared Mekari order

#### Scenario: Future report lights up its declared tab

- **WHEN** a report card is later mapped to a currently-empty Mekari tab (e.g. Aset) and the user is permitted to see it
- **THEN** that tab appears in its declared Mekari position without reordering the other tabs

### Requirement: Permission-aware report cards

Each report card SHALL be gated by the same permission as its corresponding sidebar entry was previously, and SHALL link to the existing report route without altering that report's behavior. Each card SHALL display a leading icon, a title, a descriptive sentence explaining what the report shows, and a "Lihat laporan" call-to-action. The entire card SHALL remain a navigable link to its report route; the call-to-action button is a visual affordance and SHALL navigate to the same route.

#### Scenario: Card permission and tab mapping is preserved

- **WHEN** the landing page renders cards
- **THEN** Sekilas bisnis shows Laporan Laba Rugi (gated by `reports.access`, linking to `profit-loss-report.index`)
- **AND** Penjualan shows Daftar Penjualan and Penjualan Per Customer (gated by `saleReports.access`) and Penjualan Global (gated by `saleReports.global.access`)
- **AND** Pembelian shows Daftar Pembelian and Pembelian Per Supplier (gated by `purchaseReports.access`) and Pembelian Global (gated by `purchaseReports.global.access`)
- **AND** Produk shows Mutasi Stok (gated by `stockMutationReports.access`), Mutasi Stok Global (gated by `stockMutationReports.global.access`), and Valuasi Stok (gated by `inventoryValuationReports.access`)
- **AND** Lainnya shows Mekari Converter and Mekari Invoice Generator (gated by `reports.access`)

#### Scenario: Card shows description and call-to-action

- **WHEN** the landing page renders a permitted report card
- **THEN** the card displays the report title, a descriptive sentence, and a "Lihat laporan" button
- **AND** both the card body and the button navigate to the report's route

#### Scenario: Card navigates to existing report

- **WHEN** a user clicks a permitted report card
- **THEN** the system navigates to the existing report route for that card
- **AND** the report page behaves exactly as before
