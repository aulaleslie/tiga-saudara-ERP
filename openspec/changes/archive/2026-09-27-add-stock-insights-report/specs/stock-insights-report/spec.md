# Spec Delta

## Purpose

Provide authorized operational users with an understandable, current cross-business stock view that combines actionable minimum-stock attention, recent product sales and profitability, and familiar business/location drill-down without changing inventory or transaction state.

## ADDED Requirements

### Requirement: Permission-gated report entry point
The system SHALL add a `Pantauan Stok` card under `Laporan → Produk` and a dedicated report route guarded by `stockInsights.access`. The same permission SHALL authorize viewing the report and updating a product's global minimum-stock value from the report; every other report operation SHALL be read-only.

#### Scenario: Authorized user opens Pantauan Stok
- **WHEN** a user holding `stockInsights.access` opens the Produk reports tab and follows the `Pantauan Stok` card
- **THEN** the system displays the report

#### Scenario: Unauthorized user is denied everywhere
- **WHEN** a user lacking `stockInsights.access` views the reports landing page or requests the report route or minimum-stock update action directly
- **THEN** the card is hidden and the direct request is denied without exposing report data or applying an update

#### Scenario: Existing reports remain available
- **WHEN** the `Pantauan Stok` capability is introduced
- **THEN** existing product and inventory report cards, routes, and behavior remain available and unchanged

### Requirement: Indonesian operational presentation
The report SHALL use `Pantauan Stok` as its page and card title, and all user-facing labels, statuses, filters, explanations, validation errors, tooltips, modal content, and empty states SHALL be in Bahasa Indonesia.

#### Scenario: User views the report interface
- **WHEN** an authorized user opens the report
- **THEN** all visible operational terminology is presented in Bahasa Indonesia

### Requirement: Eligible product population
The report SHALL show one row per active, non-merged, stock-managed product and SHALL exclude services and other non-stock-managed products.

#### Scenario: Mixed product catalog
- **WHEN** the catalog contains active stock-managed goods, non-stock service items, inactive products, and retired merged products
- **THEN** only the active non-merged stock-managed goods are eligible for report rows

### Requirement: Current global Good stock is authoritative for attention
For each product, the system SHALL calculate `Stok Global` as the current sum of `quantity_tax + quantity_non_tax` across every business and active location. Broken tax and non-tax quantities SHALL never contribute to `Stok Global`, minimum-stock comparisons, or attention statuses, but SHALL remain available as informational detail. The calculation SHALL represent current live stock only and SHALL NOT offer a historical stock-as-of date.

#### Scenario: Good and Broken stock coexist
- **WHEN** a product has 7 tax Good units, 3 non-tax Good units, 2 tax Broken units, and 1 non-tax Broken unit across the system
- **THEN** `Stok Global` is 10
- **AND** the 3 Broken units are available only as informational detail

#### Scenario: Stock exists across businesses and locations
- **WHEN** a product has Good stock in multiple active locations owned by multiple businesses
- **THEN** `Stok Global` equals the sum of all those Good quantities

#### Scenario: Inactive location has stock
- **WHEN** a product has a stock row belonging to an inactive location
- **THEN** that stock does not contribute to the report hierarchy or `Stok Global`

### Requirement: Additive global-to-business-to-location stock columns
The report SHALL initially show one sortable `Stok Global` column. Expanding it SHALL retain that single global column and add one non-sortable quantity column per business. Expanding an individual business SHALL replace that business's aggregate quantity column with separate non-sortable location quantity columns grouped beneath the business header, while leaving `Stok Global` and all other business groups unchanged. Businesses SHALL expand and collapse independently.

#### Scenario: Initial stock presentation
- **WHEN** the report first loads
- **THEN** one `Stok Global` quantity column is shown without business or location quantity columns

#### Scenario: Global expansion shows separate businesses
- **WHEN** the user expands `Stok Global`
- **THEN** the global column remains visible
- **AND** each business appears as a separate Good-stock quantity column
- **AND** the sum of business quantities equals `Stok Global`

#### Scenario: Business expansion shows separate locations
- **WHEN** the user expands a business with multiple active locations
- **THEN** that business's aggregate quantity column is replaced by one quantity column per active location under the business header
- **AND** the sum of location quantities equals the replaced business aggregate

#### Scenario: Global sorting remains authoritative while expanded
- **WHEN** any business or location columns are visible and the user sorts by `Stok Global`
- **THEN** product rows are ordered by global Good stock
- **AND** business and location headers do not offer sorting

### Requirement: Tax and condition composition is informational
At global, business, and location levels, the report SHALL make Good tax, Good non-tax, Broken tax, and Broken non-tax composition available through concise informational UI without changing the displayed Good-stock quantity or attention calculation.

#### Scenario: User inspects a quantity composition
- **WHEN** a displayed stock scope contains tax, non-tax, or Broken quantities
- **THEN** the user can inspect the four-bucket composition for that scope
- **AND** the main quantity remains the sum of only Good tax and Good non-tax stock

### Requirement: Global minimum-stock attention statuses
The report SHALL compare the product-wide `product_stock_alert` value only with current `Stok Global`. It SHALL expose independent statuses using these rules: `Stok Habis` when global Good stock is less than or equal to zero; `Perlu Dibeli Lagi` when the minimum is greater than zero and global Good stock is greater than zero but less than or equal to the minimum; and `Batas Minimum Belum Diatur` when the minimum is zero. Business and location quantities SHALL NOT receive separate minimum-stock classifications.

#### Scenario: Global stock is exhausted
- **WHEN** a product's global Good stock is zero or negative
- **THEN** the product has status `Stok Habis`

#### Scenario: Global stock reaches its configured minimum
- **WHEN** a product has a global minimum of 5 and global Good stock of 5
- **THEN** the product has status `Perlu Dibeli Lagi`

#### Scenario: Minimum is not configured
- **WHEN** a product's global minimum is zero
- **THEN** the product has status `Batas Minimum Belum Diatur`

#### Scenario: One business is low but global stock is sufficient
- **WHEN** stock is concentrated in one business but global Good stock is above the global minimum
- **THEN** the report does not create a business-level low-stock or uneven-distribution status

### Requirement: Inline global minimum-stock maintenance
Each product name SHALL have a small, clearly labelled icon button that opens `Atur Batas Minimum Stok`. The modal SHALL show product identity, current global Good stock, informational Broken and tax/non-tax composition, recent sales context, and one global minimum field. Saving SHALL update only the global minimum-stock value, refresh the report using the current filter and expansion state, and provide clear feedback if the row no longer matches the active status filter.

#### Scenario: User opens the minimum modal
- **WHEN** an authorized user activates the minimum-stock button beside a product name
- **THEN** the modal opens without navigating to Product Edit
- **AND** it explains that the minimum applies to total Good stock across all businesses and active locations

#### Scenario: User saves a valid minimum
- **WHEN** the user enters a valid positive minimum and saves
- **THEN** only that product's global minimum is changed
- **AND** attention counts and the product row are recalculated
- **AND** current filters and stock-column expansion state are retained

#### Scenario: Updated row leaves the active filter
- **WHEN** a user configures a minimum while filtering by `Batas Minimum Belum Diatur`
- **THEN** the updated product is removed from the filtered result
- **AND** the system confirms that the product no longer matches the current filter

#### Scenario: Other mutations are unavailable
- **WHEN** the user interacts with Pantauan Stok
- **THEN** the page offers no stock adjustment, purchase, transfer, tax-bucket correction, Broken-stock update, or general product-edit operation

### Requirement: Recent sales period ends today
Sales and financial measures SHALL use an inclusive calendar period from a selected start date through today in the application timezone. The default SHALL be `7 Hari Terakhir`, meaning today and the preceding six calendar dates. Standard presets SHALL be 7, 30, and 90 days; no custom end date or future start date SHALL be allowed.

#### Scenario: Default period loads
- **WHEN** the report first loads or filters are reset
- **THEN** the selected period is `7 Hari Terakhir`
- **AND** the start date is six calendar days before today
- **AND** the end is today

#### Scenario: Preset changes the start date
- **WHEN** the user selects `30 Hari Terakhir`
- **THEN** the start date becomes 29 calendar days before today

#### Scenario: User selects a custom start date
- **WHEN** the user selects a valid start date whose inclusive duration is 18 days
- **THEN** the period control displays a system-generated `18 Hari Terakhir` option
- **AND** that generated option is visible as the current value but is not a reusable user-selectable preset

#### Scenario: Custom date matches a standard preset
- **WHEN** the selected start date produces an inclusive duration of 7, 30, or 90 days
- **THEN** the matching standard preset becomes selected

#### Scenario: Future date is rejected
- **WHEN** a future start date is submitted through the UI or a forged request
- **THEN** the system rejects it with a Bahasa Indonesia validation error

### Requirement: Current persisted sales drive product measures
For report-eligible sales whose effective reporting date falls from the selected start date through today, the report SHALL aggregate current persisted sale-detail values by product across all businesses. It SHALL NOT independently aggregate or subtract Sales Return records because approved return behavior is already represented by current persisted sales-document values.

#### Scenario: Eligible sale contributes current values
- **WHEN** an eligible sale detail currently stores quantity 8 after an original quantity of 10 was reduced by return handling
- **THEN** the report contributes quantity 8

#### Scenario: Associated Sales Return is not deducted twice
- **WHEN** a Sales Return record exists for the modification already reflected in the sale detail
- **THEN** the report does not subtract that return record again

#### Scenario: Historical result follows current document state
- **WHEN** a return changes a sale detail after its original sale period
- **THEN** a later report of that sale period reflects the sale detail's current persisted value

### Requirement: Sortable sales and financial measures
The report SHALL provide sortable global columns for `Kuantitas Terjual`, `Nilai Penjualan`, `Modal Terjual`, `Laba Kotor`, and `Penjualan Terakhir`. `Nilai Penjualan` SHALL be tax-exclusive and account for line discounts plus a deterministic proportional allocation of transaction-level discount. `Modal Terjual` SHALL use captured historical cost snapshots associated with current persisted quantities, including attributable bundle component costs. `Laba Kotor` SHALL equal sales value minus cost and SHALL be described as excluding operational expenses.

#### Scenario: User ranks products by units sold
- **WHEN** the user sorts `Kuantitas Terjual` descending
- **THEN** products with the largest current persisted quantity in the selected sales period appear first

#### Scenario: Tax-inclusive sale contributes tax-exclusive value
- **WHEN** an eligible line has a tax-inclusive subtotal and a product-tax amount
- **THEN** `Nilai Penjualan` excludes that product tax

#### Scenario: Header discount is allocated
- **WHEN** an eligible sale has a transaction-level discount covering multiple product lines
- **THEN** the discount is allocated proportionally and deterministically among the product measures
- **AND** allocated line shares reconcile with the transaction discount

#### Scenario: Profit is calculated
- **WHEN** a product has `Nilai Penjualan` of 1,000,000 and `Modal Terjual` of 600,000
- **THEN** `Laba Kotor` is 400,000

#### Scenario: Cost snapshot is incomplete
- **WHEN** one or more contributing stock-managed sale lines lack a usable cost snapshot
- **THEN** the report marks cost and gross profit as incomplete rather than presenting them as fully reliable

### Requirement: Product filtering and deterministic sorting
The report SHALL provide product identity search, multi-select attention-status filtering, category filtering, brand filtering, sales-period selection, and start-date selection. It SHALL NOT provide business, location, stock-condition, tax-bucket, generic availability, or custom-end-date filters. Changing the sales period SHALL not alter current stock or minimum-stock status.

#### Scenario: Search finds a product
- **WHEN** a user searches by a matching product name, product code, or barcode
- **THEN** matching eligible product rows are returned

#### Scenario: Multiple attention statuses are selected
- **WHEN** a user selects more than one attention status
- **THEN** a product matching any selected status is returned

#### Scenario: Period changes sales but not stock
- **WHEN** the user changes from 7 to 30 days
- **THEN** sales and financial measures are recalculated
- **AND** `Stok Global`, the global minimum, and minimum-stock statuses remain unchanged

#### Scenario: Default operational order
- **WHEN** the user has not selected an explicit column sort
- **THEN** rows prioritize `Stok Habis`, then `Perlu Dibeli Lagi`, then `Batas Minimum Belum Diatur`, then `Lama Tidak Terjual`, followed by remaining products with deterministic product-name and product-ID tie-breaking

### Requirement: Long-without-sale attention
The report SHALL assign `Lama Tidak Terjual` when an eligible product has positive global Good stock, is at least 90 days old, and has no current persisted eligible sale quantity during the 90 calendar days through today. Products with no Good stock or insufficient age SHALL not receive this status.

#### Scenario: Stocked mature product has no recent sale
- **WHEN** an eligible product is older than 90 days, has positive global Good stock, and has no eligible sales during the last 90 calendar days
- **THEN** it has status `Lama Tidak Terjual`

#### Scenario: New product lacks history
- **WHEN** an eligible product is less than 90 days old and has never sold
- **THEN** it does not have status `Lama Tidak Terjual`

#### Scenario: Out-of-stock product has no slow-sale status
- **WHEN** an eligible product has no global Good stock
- **THEN** it does not have status `Lama Tidak Terjual`

### Requirement: Focused verification and human browser acceptance
Automated verification for this capability SHALL be limited to focused unit and feature tests covering its calculations, permissions, filters, modal update, and rendering contracts. Browser acceptance SHALL be documented for a human to perform and SHALL not require an implementation agent to install or invoke Chrome, Chromium, Playwright, Selenium, or another browser automation tool.

#### Scenario: Implementation verification is planned
- **WHEN** implementation tasks and acceptance instructions are prepared
- **THEN** they identify focused automated test targets and a separate human browser checklist
- **AND** they do not require a full automated suite or agent-driven browser command
