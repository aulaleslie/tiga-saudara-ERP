## MODIFIED Requirements

### Requirement: Permission-aware report cards

Each report card SHALL be gated by the same permission as its corresponding sidebar entry was previously, and SHALL link to the existing report route without altering that report's behavior. Each card SHALL display a leading icon, a title, a descriptive sentence explaining what the report shows, and a "Lihat laporan" call-to-action. The entire card SHALL remain a navigable link to its report route; the call-to-action button is a visual affordance and SHALL navigate to the same route. The "Penjualan per produk" sales by product report card SHALL be included in the Penjualan tab, gated by `saleReports.access`, and remain actionable without placeholder treatment. The "Usia utang" aged payables report card SHALL be included in the Pembelian tab, gated by `purchaseReports.access`, and remain actionable without placeholder treatment.

#### Scenario: Card permission and tab mapping is preserved

- **WHEN** the landing page renders cards
- **THEN** Sekilas bisnis shows Laporan Laba Rugi (gated by `reports.access`, linking to `profit-loss-report.index`)
- **AND** Sekilas bisnis shows Neraca (gated by `reports.access`, linking to `operational-balance-sheet-report.index`)
- **AND** Sekilas bisnis shows Buku Besar (gated by `reports.access`, linking to the Buku Besar report route)
- **AND** Sekilas bisnis shows Arus kas (gated by `reports.access`, linking to the Arus Kas report route)
- **AND** Penjualan shows Daftar Penjualan, Penjualan Per Customer, Piutang pelanggan, Usia piutang, Pengiriman penjualan, and Penjualan per produk (gated by `saleReports.access`) and Penjualan Global (gated by `saleReports.global.access`)
- **AND** Pembelian shows Daftar Pembelian, Pembelian Per Supplier, Utang supplier, Daftar pengeluaran, and Usia utang (gated by `purchaseReports.access`) and Pembelian Global (gated by `purchaseReports.global.access`)
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
