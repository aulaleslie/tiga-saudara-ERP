# Spec Delta

## MODIFIED Requirements

### Requirement: Invoice sales are aggregated by product
The report SHALL aggregate current persisted sale-detail and bundle-item quantities and values by product, unit, and sale role only from report-eligible sales, scoped to the selected settings and effective sale reporting date. These persisted values SHALL already represent any approved settlement modification.

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
- **THEN** those sale details are combined into a single product/unit/role aggregate row

#### Scenario: Component sold quantity is reported independently
- **WHEN** an eligible sale contains a persisted bundle component
- **THEN** its own persisted quantity contributes under its component product and role
- **AND** no quantity is synthesized for a zero-quantity parent representing only another business's components

### Requirement: Tax-exclusive value calculation
The report SHALL calculate `Total Nilai terjual` as tax-exclusive line commercial values. For tax-included sale lines, the report MUST subtract the line tax amount from the line subtotal before aggregation.

#### Scenario: Tax-included sale value excludes tax
- **WHEN** a tax-included sale line has subtotal 111,000 and product tax amount 11,000
- **THEN** the report contributes 100,000 to `Total Nilai terjual`

#### Scenario: Tax-exclusive sale value uses subtotal
- **WHEN** a tax-exclusive sale line has subtotal 100,000 and product tax amount 11,000
- **THEN** the report contributes 100,000 to `Total Nilai terjual`

#### Scenario: Average sales price is zero-safe
- **WHEN** a product row has zero sold quantity
- **THEN** `Harga Penjualan Rata-rata` is shown as zero instead of causing a division error

#### Scenario: POS bundle revenue and tax are decomposed once
- **WHEN** a known POS parent subtotal includes assigned component subtotals
- **THEN** each component contributes its own persisted tax-exclusive assigned revenue
- **AND** the parent contributes only the remaining tax-exclusive revenue, preserving the commercial total without duplication

#### Scenario: Document discount behavior remains unchanged
- **WHEN** a sale has a separately persisted document-level discount
- **THEN** the report preserves its existing line-value treatment rather than introducing a new discount allocation

### Requirement: Product aggregate presentation
The report SHALL show separate rows by product, compatible unit, and sale role, with code, name, role, sold quantity, unit, sales total, weighted average selling price, Total Modal, and Modal Rata-rata/unit. It SHALL distinguish direct/parent sales from bundle components, and mark unavailable or partial values explicitly.

#### Scenario: Product row displays aggregate columns
- **WHEN** a product has matching sold quantities
- **THEN** the row displays Kode Produk, Nama Produk, Dijual sebagai, Kuantitas Terjual, Satuan, Total Nilai terjual, Harga Penjualan Rata-rata, Total Modal, and Modal Rata-rata/unit
- **AND** obsolete separate return columns are not introduced

#### Scenario: Direct and component quantities stay separate
- **WHEN** the same product sold directly and as a bundle component matches the filters
- **THEN** separate rows prevent bundle quantities without assigned selling values from diluting direct-sale average prices

#### Scenario: Product without code remains reportable
- **WHEN** a matching persisted line has no product code
- **THEN** the report includes it with a blank or fallback code display

#### Scenario: Total row is shown
- **WHEN** the report has matching rows
- **THEN** the total row sums sales and known HPP over all matching pages without double-counting bundle costs or revenue
- **AND** incomplete totals are visibly marked partial and unit averages are not summed

#### Scenario: Empty result state is shown
- **WHEN** filters match no report-eligible sold items
- **THEN** the report shows an empty state instead of fabricated totals

### Requirement: Report filtering and sorting
The report SHALL support date range, period presets, company scope, customer, tag, product category, brand, and product filters, with configurable tag match logic, and sorting by product name, product code, sold quantity, sales value, and average sales value. Categories, brands, and products SHALL match any selected value within a filter and combine with other filters using AND; selecting a category includes its descendants. Empty catalog selections SHALL add no restriction.

Filter option lookups for products, product categories, brands, and customers SHALL NOT be scoped by `setting_id`. Master data records `setting_id` as the setting in which the record was created, not the business that owns it, so scoping option lookups by it would offer a set of options that disagrees with the rows the report can return. Only transaction records (`sales`, `sale_returns`) SHALL be scoped by setting.

Filter option lookups SHALL match search input by splitting it on whitespace and requiring every resulting token to match, rather than matching the input as a single literal phrase. Each token MAY match any of the fields searched by that lookup. The product lookup SHALL search both `product_name` and `product_code`, and SHALL present the product code alongside the product name so that products with similar names remain distinguishable.

#### Scenario: Customer filter narrows rows
- **WHEN** the user selects one or more customers and applies filters
- **THEN** only sales and returns for those customers are included

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with "Mencakup semua"
- **THEN** only sales containing every selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with "Salah satu"
- **THEN** product rows in at least one selected category are included

#### Scenario: Product filter narrows rows
- **WHEN** the user selects one or more products and applies filters
- **THEN** only rows for those selected products are included

#### Scenario: Brand and product filters intersect categories
- **WHEN** the user selects ATK and LAPTOP categories, two brands, and several products
- **THEN** only selected products belonging to either category branch and either selected brand contribute

#### Scenario: Parent category includes descendants
- **WHEN** ATK is selected and a product belongs to its child or deeper descendant
- **THEN** that product is eligible, without duplication from overlapping parent/child selections

#### Scenario: Sort by return quantity
- **WHEN** a legacy request supplies the obsolete return-quantity sort
- **THEN** the report falls back to deterministic product-name ordering rather than adding a return column or querying a nonexistent aggregate

#### Scenario: Product search finds a product created in another setting
- **WHEN** a sale in the selected company scope references a product whose `products.setting_id` differs from that of the sale
- **AND** the user searches for that product by name in the product filter
- **THEN** the product SHALL be offered as a selectable option
- **AND** selecting it SHALL narrow the report to that product's rows

#### Scenario: Category search is not restricted by setting
- **WHEN** the user searches product categories in the filter panel
- **THEN** matching categories SHALL be offered regardless of the setting in which they were created

#### Scenario: Duplicate category names are listed separately
- **WHEN** categories under different parent paths share a display name
- **THEN** options SHALL preserve their distinct identities and show sufficient parent context to distinguish them

#### Scenario: Customer search is not restricted by setting
- **WHEN** the user searches customers in the filter panel
- **THEN** matching customers SHALL be offered regardless of the setting recorded on the customer record

#### Scenario: Partial words across tokens match a product
- **WHEN** the user enters a search term whose whitespace-separated tokens each appear in a product's name but which does not appear as a single contiguous phrase
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Every token must match
- **WHEN** the user enters a search term in which one token matches no searched field of a product
- **THEN** that product SHALL NOT be offered as a selectable option

#### Scenario: Token order does not affect matching
- **WHEN** the user enters the same set of tokens in a different order
- **THEN** the same set of products SHALL be offered

#### Scenario: Product code is searchable
- **WHEN** the user enters a term matching a product's `product_code`
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Product options display the product code
- **WHEN** product options are presented in the filter panel
- **THEN** each option SHALL display the product code together with the product name

#### Scenario: Tokens may match across different searched fields
- **WHEN** the user enters a search term in which one token matches a product's name and another matches that product's code
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Minimum search length still applies
- **WHEN** the user enters a search term shorter than the minimum search length
- **THEN** no options SHALL be offered

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
- **WHEN** an applied result includes component rows, valid zero costs, partial HPP, or unavailable selling values
- **THEN** XLSX and CSV include the same fields, all matching rows, status labels, and totals as preview
- **AND** unavailable amounts are not exported as numeric zero

#### Scenario: Catalog or business filters change
- **WHEN** the user changes category, brand, product, or business selection after applying filters
- **THEN** export is refused until the changed filters are applied

## ADDED Requirements

### Requirement: Historical snapshot HPP and weighted averages
The report SHALL calculate HPP from persisted sales cost snapshots and their provenance, using the included quantity basis. Complete row average HPP SHALL equal total HPP divided by sold quantity. It SHALL not read current catalog purchase prices or generate new snapshots to fill gaps.

#### Scenario: Quantity-weighted cost across businesses
- **WHEN** selected businesses sold 2 units at recorded unit cost 100 and 3 units at 200
- **THEN** the combined row shows quantity 5, Total Modal 800, and Modal Rata-rata/unit 160

#### Scenario: Settlement reduces quantity
- **WHEN** an eligible persisted line quantity is reduced by settlement
- **THEN** its unit-cost snapshot is applied to the remaining quantity
- **AND** returns and replacement costs are not independently deducted or added

#### Scenario: Complete zero quantity is safe
- **WHEN** a complete row has zero quantity
- **THEN** averages do not divide by zero and display zero consistently with existing report behavior

### Requirement: Cost completeness respects provenance and valid zero
A valid zero snapshot SHALL remain numeric zero and complete. Missing, invalid, or explicitly missing-cost snapshots SHALL mark the row Modal belum lengkap; known HPP SHALL be labeled partial and average HPP unavailable. This change SHALL not reinterpret a missing-cost source as valid zero or change snapshot capture policy.

#### Scenario: Zero differs from missing
- **WHEN** one item has an explicitly valid zero snapshot and another records MISSING_AVERAGE_PRICE with zero
- **THEN** the first is complete at Rp0 and the second is incomplete

#### Scenario: Mixed known and unknown costs
- **WHEN** some contributing lines lack valid HPP
- **THEN** known costs remain visible as partial without an average HPP for that row
- **AND** sales quantities and known selling values remain visible

#### Scenario: Genuine acquisition zero was recorded as missing
- **WHEN** historical provenance classifies zero acquisition cost as missing
- **THEN** the report preserves the incomplete classification without inferring a replacement cost

### Requirement: Bundle revenue attribution follows persisted origin
Known POS component allocations SHALL use persisted component commercial values and residual parent revenue. Standard non-POS included components SHALL show Included in bundle. Standalone commercial items SHALL use their own values. Ambiguous historical component revenue SHALL be unavailable, without creating allocations from informational or current catalog prices.

#### Scenario: POS parent includes component shares
- **WHEN** a POS parent subtotal is 125000 and includes a component subtotal of 25000
- **THEN** the parent contributes 100000 and the component 25000 before applicable tax exclusion

#### Scenario: Category filter selects only component
- **WHEN** an ATK component matches the filters and its bundle parent does not
- **THEN** the component's quantity, assigned selling value where known, and own HPP are included
- **AND** parent decomposition considers all siblings before report filters so amounts do not change with sibling visibility

#### Scenario: Non-POS included component
- **WHEN** a standard sale component has informational price but no independently billable selling value
- **THEN** it shows its quantity and snapshot HPP with Included in bundle for selling values
- **AND** its informational price does not become report revenue or reduce parent revenue

#### Scenario: Historical attribution cannot be established
- **WHEN** an old component price alone does not establish assigned revenue semantics
- **THEN** component selling values show unavailable and do not reduce parent revenue speculatively
- **AND** mixed component aggregates with unknown selling values label known sales partial and suppress average selling price

#### Scenario: Standalone item has own commercial context
- **WHEN** a bundle item has no parent sale-detail link and has established standalone commercial provenance
- **THEN** its own quantity, subtotal, tax, and cost snapshots contribute once

#### Scenario: Costs are counted once
- **WHEN** a parent and its components are reported
- **THEN** each persisted item's own HPP contributes once without rolling component HPP into the parent again

### Requirement: Checkbox selection and authorized business scope
Business, category, brand, and product selection SHALL follow the sold-serial-number report's searchable checkbox interaction, selected count, removable chips, and clear behavior. Only authorized businesses SHALL contribute; the active authorized business is the default with the existing fallback on empty selection. Catalog filters remain optional and global.

#### Scenario: Multiple checks and persistent selection
- **WHEN** a user checks several options and changes search text
- **THEN** the dropdown remains open while checking and selections persist until individually removed or cleared

#### Scenario: Business scope is tampered with
- **WHEN** a request includes an unassigned business
- **THEN** preview and exports exclude that business using server-side scope sanitization

#### Scenario: Product search interaction matches serial report
- **WHEN** product selections exceed three or search matches more than 25 products
- **THEN** chips can expand beyond the initial three and the dropdown shows a 25-result limit notice without silently selecting unseen matches
- **AND** the existing explicit bulk-selection action retains its documented 500-product ceiling

#### Scenario: Filter changes are pending
- **WHEN** a user modifies a selector after generating the report
- **THEN** the applied rows remain tied to the applied filter snapshot and a pending-change indication requests reapplication
