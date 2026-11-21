# Spec Delta

## MODIFIED Requirements

### Requirement: Invoice sales are aggregated by product
The report SHALL aggregate current persisted sale-detail and bundle-item quantities and values by product and compatible unit only from report-eligible sales, scoped to the selected settings and effective sale reporting date. These persisted values SHALL already represent any approved settlement modification.

#### Scenario: Eligible sale contributes current detail values
- **WHEN** a `DISPATCHED` or `RETURNED PARTIALLY` sale is inside the selected scope and period
- **THEN** its current persisted detail quantities and values are included

#### Scenario: Partial dispatch is excluded
- **WHEN** a sale is only `DISPATCHED PARTIALLY`
- **THEN** none of its details contribute to the product aggregate

#### Scenario: Sales are scoped to the selected settings
- **WHEN** a setting outside the selected company scope has sale details in the selected period
- **THEN** those sale details are not included in the report

#### Scenario: Sales across selected settings are combined
- **WHEN** two settings are both within the selected company scope and each has sale details for the same product in the selected period
- **THEN** those sale details are combined into a single product/unit aggregate row

#### Scenario: Component sold quantity is reported independently
- **WHEN** an eligible sale contains a persisted bundle component
- **THEN** its own persisted quantity contributes under its component product, combined with direct sales of that product/unit
- **AND** no quantity is synthesized for a zero-quantity parent representing only another business's components

### Requirement: Product aggregate presentation
The report SHALL present exactly six columns in this order: Kode Produk, Nama Produk, Kuantitas Terjual, Satuan, Total Nilai terjual, Harga Penjualan Rata-rata. Each product and compatible unit SHALL have one aggregate row combining direct and bundle-item sales. It SHALL not display role, HPP/modal, status, or additional attribution/completeness labels.

#### Scenario: Product row displays aggregate columns
- **WHEN** matching sold products are displayed
- **THEN** each row contains exactly the six required columns in their required order, with numeric sales quantity/value/average cells
- **AND** no Dijual sebagai, modal, status, Included in bundle, partial, or unavailable output is added

#### Scenario: Direct and component quantities stay separate
- **WHEN** a product has direct-sale and bundle-component source quantities
- **THEN** each source quantity is counted once and both contribute to one product/unit row rather than separate role rows

#### Scenario: Product without code remains reportable
- **WHEN** a matching persisted line has no product code
- **THEN** it remains reportable with the existing blank or fallback code behavior

#### Scenario: Total row is shown
- **WHEN** matching rows exist
- **THEN** the six-cell footer sums Total Nilai terjual over the complete filtered result without adding a modal total or summing average prices

#### Scenario: Empty result state is shown
- **WHEN** filters match no report-eligible sold products
- **THEN** preview shows an empty state without fabricated product rows or totals

### Requirement: Snapshot-validated exports
The report SHALL export Excel and CSV only when the current filters match the last applied filter snapshot. Exported rows and totals MUST match the on-screen report for the same applied filters.

#### Scenario: Export blocked before applying filters
- **WHEN** the user attempts to export before applying filters
- **THEN** the system refuses the export and asks the user to apply filters first

#### Scenario: Export matches current report rows
- **WHEN** the user exports after applying filters
- **THEN** the exported product rows and totals match the report data for those filters

#### Scenario: Export blocked after filter changes
- **WHEN** the user changes filters after applying them
- **THEN** the system refuses export until the filters are applied again

#### Scenario: XLSX includes report metadata
- **WHEN** the user exports Excel
- **THEN** the XLSX includes company name, report title `Penjualan dengan Produk`, selected date range, and `(dalam IDR)` metadata rows above the table

#### Scenario: New cost and bundle fields match exports
- **WHEN** direct and bundle-item sales are exported after applying filters
- **THEN** XLSX and CSV contain exactly the original six headings and product/unit rows, with combined numeric quantities, sales values, averages, and matching totals
- **AND** no cost, role, status fields, Included in bundle, or other attribution/completeness labels appear

#### Scenario: Catalog or business filters change
- **WHEN** the user changes category, brand, product, or business selection after applying filters
- **THEN** export is refused until the changed filters are applied

#### Scenario: Serialized files preserve six-column numeric output
- **WHEN** a valid zero selling value is exported to XLSX or CSV
- **THEN** zero remains numeric zero rather than blank, and every heading/data/footer row spans exactly six table columns

### Requirement: Bundle revenue attribution follows persisted origin
The report SHALL use persisted component commercial values for established POS allocations and subtract those shares from parent revenue once. Filters SHALL match each reported product's own category, brand, and identity. It SHALL not calculate new price allocations or show attribution labels; stored zero commercial values remain numeric zero.

#### Scenario: POS parent includes component shares
- **WHEN** a parent subtotal of 125000 includes an assigned component subtotal of 25000
- **THEN** parent revenue is 100000 and component revenue is 25000 before applicable tax exclusion, with each source contributing once

#### Scenario: Category filter selects only component
- **WHEN** an ATK component matches the filter while its LAPTOP parent does not
- **THEN** the component contributes its own persisted quantity and assigned selling value to its product row
- **AND** all siblings are considered before catalog filtering so the parent's residual is independent of filter visibility

#### Scenario: Non-POS included component
- **WHEN** a persisted non-billable component has zero commercial subtotal and an informational price
- **THEN** informational price is never promoted to sales revenue and no Included in bundle label is emitted
- **AND** zero remains its stored commercial value rather than an invented allocation

#### Scenario: Historical attribution cannot be established
- **WHEN** inspecting an older record whose price alone does not establish a parent/component commercial allocation
- **THEN** the report SHALL NOT reconstruct an allocation from informational prices or today's catalog values
- **AND** no historical allocation repair or new status output is introduced by this change

#### Scenario: Standalone item has own commercial context
- **WHEN** an established standalone commercial bundle item has no parent detail
- **THEN** its own quantity and tax-exclusive commercial value are included once in its product/unit aggregate without a role column

#### Scenario: Costs are counted once
- **WHEN** the revised sales report is generated or exported
- **THEN** HPP is not evaluated or output, and existing cost snapshots remain unchanged for their other consumers

### Requirement: Checkbox selection and authorized business scope
Business, customer, category, brand, and product selection SHALL follow the sold-serial-number report's searchable checkbox interaction, selected count, removable chips, and clear behavior. Only authorized businesses SHALL contribute; the active authorized business is the default with the existing fallback on empty selection. Catalog filters remain optional and global.

#### Scenario: Multiple checks and persistent selection
- **WHEN** a user checks several options and changes search text
- **THEN** the dropdown remains open while checking and selections persist until individually removed or cleared

#### Scenario: Business scope is tampered with
- **WHEN** a request includes an unassigned business
- **THEN** preview and exports exclude that business using server-side scope sanitization

#### Scenario: Product search interaction matches serial report
- **WHEN** product selections exceed three or search matches more than 25 products
- **THEN** the current refined widget keeps all selected chips above the input in its bounded scroll area and shows the existing 25-result notice
- **AND** explicit select-all-matching-products retains its 500-product ceiling

#### Scenario: Filter changes are pending
- **WHEN** a user modifies a selector after generating the report
- **THEN** the applied rows remain tied to the applied filter snapshot and a pending-change indication requests reapplication

## ADDED Requirements

### Requirement: Product-level weighted selling averages
Direct-sale and bundle-item quantities and assigned commercial values for the same product/unit SHALL combine before calculating average selling price. The average SHALL equal combined tax-exclusive sales value divided by combined sold quantity, with the existing zero-quantity guard. Sale role and business SHALL not split otherwise compatible rows.

#### Scenario: Same product sold directly and inside bundle
- **WHEN** direct sales contribute 2 units and 60000 and a bundle item contributes 1 unit and 25000
- **THEN** one product/unit row shows quantity 3, sales value 85000, and average selling price 85000 divided by 3

#### Scenario: Selected business contributes only component stock
- **WHEN** an eligible selected business has a zero-quantity placeholder parent and a fulfilled component with assigned revenue
- **THEN** the component contributes its actual product quantity and revenue without a phantom parent quantity or duplicate revenue

### Requirement: Existing filter refinements remain intact
The output revision SHALL preserve current filter interactions, customer/category/brand/product chips above inputs, applied summaries, cancel/reset, pending changes, authorized business scope, and period/date synchronization. Filter controls and applied summaries SHALL remain separate from the strict six-column result table.

#### Scenario: Presets and custom dates remain synchronized
- **WHEN** a user selects Tahun Ini or enters an exact January 1 through December 31 range for the current Makassar year
- **THEN** dates and preset synchronize without applying results automatically
- **AND** nonmatching ranges select Rentang khusus while selecting Rentang khusus leaves the dates intact

#### Scenario: Draft selections do not affect applied results
- **WHEN** a customer/category/brand/product/business selection is changed after Apply
- **THEN** the current applied summary and results remain stable and export is blocked until Apply

## REMOVED Requirements

### Requirement: Historical snapshot HPP and weighted averages
**Reason**: This report returns to numeric sales-only output without modal/HPP calculations.
**Migration**: Retain snapshot storage and other report consumers unchanged; use Product-level weighted selling averages for this report.

### Requirement: Cost completeness respects provenance and valid zero
**Reason**: Modal completeness fields, partial markers, and average-HPP presentation are removed from this report.
**Migration**: Remove this report's cost evaluation/output while preserving existing snapshot writer policy and strict numeric-zero export behavior.
