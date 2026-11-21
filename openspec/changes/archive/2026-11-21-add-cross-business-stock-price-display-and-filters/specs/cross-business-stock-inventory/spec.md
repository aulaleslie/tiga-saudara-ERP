# Spec Delta

## MODIFIED Requirements

### Requirement: System SHALL support category, brand, and availability filters alongside pagination
The report SHALL provide searchable checkbox multi-selects for categories (including descendants), brands, and products, with OR within each selector and AND across selectors and other applied criteria. It SHALL retain the existing availability filter and pagination. Selection changes SHALL remain draft until Apply; applying SHALL reset pagination to page one.

#### Scenario: Filtering by category
- **WHEN** a user selects 2 categories
- **THEN** products belonging to at least one of the 2 selected categories are shown

#### Scenario: Filtering to available stock only
- **WHEN** a user selects the "available" availability filter
- **THEN** only products with a nonzero Good or Bad quantity in at least one visible business are shown

#### Scenario: Filtering to non-available stock only
- **WHEN** a user selects the "non-available" availability filter
- **THEN** only products with zero Good and Bad quantity across all visible businesses are shown

#### Scenario: Combined descendant and product filtering
- **WHEN** the user applies parent category ATK, two brands, and selected products
- **THEN** matching products belong to ATK or its descendants, one selected brand, and the selected product set without duplicate rows

### Requirement: System SHALL export the report to Excel, always fully expanded to per-location detail
Excel export SHALL offer Stok saja (default), Harga saja, and Stok + Harga independently of the price-display toggle. All modes SHALL respect applied criteria and authorized businesses. Exports containing stock SHALL always expand locations and retain existing Good/Bad and in-delivery quantities. Harga saja SHALL omit stock/location columns. Each price-bearing mode SHALL show four prices once per business, product identity, category, brand, and base-unit Satuan.

#### Scenario: Exporting while a business is collapsed on screen
- **WHEN** a user with one business collapsed on screen triggers the Excel export
- **THEN** the exported file shows that business broken out per location, not as a collapsed subtotal

#### Scenario: Export respects business-visibility scope
- **WHEN** a non-Super-Admin user assigned to 2 businesses exports the report
- **THEN** the exported file contains columns only for those 2 businesses' locations

#### Scenario: Export respects applied filters
- **WHEN** a user has applied a category filter and a search term before exporting
- **THEN** the exported rows match exactly the filtered result set shown on screen (before pagination)

#### Scenario: Price-only export with availability filter
- **WHEN** the user exports Harga saja with Tersedia applied and prices hidden on screen
- **THEN** the file contains only products matching applied availability and selected businesses, four prices per business, and no stock quantities or location columns

#### Scenario: Combined export does not repeat prices per location
- **WHEN** the user exports Stok + Harga for a business with three locations
- **THEN** stock appears per location and business prices appear once with base-unit Satuan

## ADDED Requirements

### Requirement: Filter selectors SHALL retain visible multi-selection state
Category, brand, and product selectors SHALL keep search text and checkbox options open during selection, show all selected chips in a bounded scroll area above inputs, and provide counts, individual removal, and clear-all. Search SHALL follow Sales by Product token matching, result caps, and overflow feedback.

#### Scenario: Repeated selection and removal
- **WHEN** the user checks two search results then removes one chip
- **THEN** search text persists, the list remains available, and chips and count reflect the draft selection

### Requirement: Draft filters SHALL be separate from applied report state
General search, business, availability, and selector criteria SHALL remain draft until Apply. Cancel SHALL restore applied criteria; Reset SHALL apply default criteria with all authorized businesses and clear search/selections. Applied summary, pagination, serial markers, and every export SHALL use applied criteria. Exports SHALL be blocked while draft criteria differ.

#### Scenario: Search and cancel preserve current results
- **WHEN** the user changes a barcode or serial search or business selection without Apply
- **THEN** rows, visible business columns, serial marker, and applied summary remain unchanged and export is blocked
- **AND** Cancel restores applied inputs and export eligibility

#### Scenario: Apply and reset
- **WHEN** the user applies valid criteria from another page
- **THEN** page one and its applied summary load once using those criteria
- **AND** Reset returns to initial all-stock, all-authorized-business criteria with no category, brand, product, or search restriction

#### Scenario: Empty business selection
- **WHEN** the user attempts Apply with no authorized business selected
- **THEN** the report requests at least one business rather than substituting another business or querying all businesses

### Requirement: Price display SHALL be optional and independent of filter application
Tampilkan Harga SHALL default off and immediately show or hide four price columns once per selected business without applying draft criteria. Prices SHALL remain once per business during location expansion. Satuan SHALL identify the base unit; quantity conversion mode SHALL NOT change price units or values.

#### Scenario: Show prices with unapplied selections
- **WHEN** an authorized user enables Tampilkan Harga while business changes are still draft
- **THEN** prices appear for the currently applied businesses and products and the draft business changes remain unapplied

#### Scenario: Expand and convert quantities
- **WHEN** prices are visible and the user expands locations or switches quantity format
- **THEN** business prices appear once, unchanged and denominated per base unit

### Requirement: Business prices SHALL use current stored values and preserve missingness
Each price section SHALL show Harga Modal from average purchase price, Harga Jual, Tier 1, and Tier 2 from that product's current selected-business price record. Prices SHALL NOT fall back to another business, legacy product values, historical sales snapshots, or calculated stock values. Missing records or null fields SHALL display an em dash and export blank; saved zero SHALL remain numeric zero.

#### Scenario: Distinct business prices with a missing record
- **WHEN** business A has four stored prices and business B has no price record
- **THEN** A shows its own values and B shows unavailable values without borrowing A's prices

#### Scenario: Zero and numeric magnitude
- **WHEN** stored prices include zero and 2500000.00
- **THEN** display and serialized export preserve those magnitudes, and zero remains distinct from missing

### Requirement: Price access SHALL require the existing price-view permission
Price display and price-bearing exports SHALL require both report access and products.view_prices. Business scope SHALL be reauthorized server-side on relevant actions. Unauthorized users SHALL retain stock-only access and SHALL NOT receive price values through rendered content, component state, or downloads, including manipulated requests.

#### Scenario: Stock-only user attempts price access
- **WHEN** a report user without price-view permission requests a price-bearing export or manipulates the display toggle
- **THEN** price access is denied server-side without disclosing price values and stock-only reporting remains available

#### Scenario: Business access changes
- **WHEN** an applied business is no longer authorized before pagination, price display, or export
- **THEN** its stock and prices are excluded or access is rejected without disclosure

### Requirement: Filter and price interactions SHALL use bounded report loading
Draft selector searches SHALL NOT rerun stock or purchase aggregation. Apply and pagination SHALL load each result page once. Price reads SHALL be bulk scoped to page or export-batch products and authorized businesses, without per-cell queries. Presentation changes SHALL reuse loaded stock aggregates. Export source processing SHALL remain batched.

#### Scenario: Apply and presentation query counts
- **WHEN** the user applies from page two, types draft searches, then toggles prices and quantity formatting
- **THEN** Apply loads one page, draft searches perform no report aggregation, and presentation changes perform no stock or purchase aggregation

#### Scenario: Large export price loading
- **WHEN** an export spans several processing batches and selected businesses
- **THEN** every matching product is exported and related source data is loaded per batch without per-product or per-business queries
