## ADDED Requirements

### Requirement: Global projected HPP is visible and permanently read-only
The price-management page SHALL display `Proyeksi HPP` beside `Harga Beli Rata-rata` in the base-unit table, repeating one global estimate on every business row. It SHALL remain read-only in every editing state and display the tooltip `Estimasi HPP jika seluruh sisa pembelian yang disetujui diterima sekarang.`

#### Scenario: Business rows share the estimate
- **WHEN** an authorized user opens a stock-managed product with multiple business rows
- **THEN** every row displays the same projected global HPP using the page's whole-Rupiah formatting
- **AND** a business without a stored price row displays that same projection

#### Scenario: Editing cannot persist the projection
- **WHEN** the user enters edit mode, cancels, saves prices, or submits a fabricated projected price
- **THEN** the projection remains informational and cannot modify stored prices, HPP, or stock

#### Scenario: Product has no stock management
- **WHEN** the selected product is not stock managed
- **THEN** the projection displays `Tidak berlaku` rather than a stock valuation estimate

### Requirement: Projection combines global stock with eligible outstanding purchase cost
For stock-managed products the system SHALL estimate `(Q × H + V) / (Q + R)`, where Q is current global stock, H is current global HPP, R is eligible outstanding base-unit quantity, and V is its tax-exclusive discounted cost. It SHALL use the existing current-global-HPP display baseline and preserve fractional quantities and costs until final display rounding.

#### Scenario: Incoming purchases belong to different businesses
- **WHEN** global stock is 100 units at HPP 100 and approved outstanding quantities are 10 units at cost 200 and 30 units at cost 300 in different businesses
- **THEN** the unrounded estimate is `(10000 + 2000 + 9000) / 140`
- **AND** both businesses display Rp150

#### Scenario: Receipt valuation matches discounted tax-exclusive cost
- **WHEN** an eligible line has a discounted subtotal and line tax
- **THEN** its unit cost is `(subtotal - line tax) / ordered base-unit quantity`
- **AND** the line discount is not subtracted a second time
- **AND** purchase-header discount and shipping are not newly allocated by this feature

#### Scenario: No outstanding quantities
- **WHEN** R is zero
- **THEN** the projection equals the current global HPP without division

#### Scenario: No positive resulting stock
- **WHEN** R is positive but Q + R is zero or negative
- **THEN** the estimate uses the weighted incoming cost V / R without division by zero

#### Scenario: Existing business HPP values differ
- **WHEN** stored business averages diverge
- **THEN** the projection uses the same selected current-HPP baseline as the existing global-HPP dialog
- **AND** the existing discrepancy warning remains visible and no stored value is normalized by reading the page

### Requirement: Only approved unreceived quantities contribute
The system SHALL include non-archived purchases in `APPROVED` or `RECEIVED PARTIALLY` status across all businesses. Each product line SHALL contribute `max(0, ordered quantity - approved received quantity)`. Pending or rejected receipt notes SHALL NOT reduce outstanding quantity. Only positive outstanding quantities SHALL contribute cost or appear as source lines.

#### Scenario: Partially received purchase
- **WHEN** a line ordered 10 units has 4 units on approved receipts and 2 units on a pending receipt
- **THEN** exactly 6 units contribute to projected incoming quantity and cost

#### Scenario: Over-receipt cannot offset another line
- **WHEN** one product line is over-received and another has an outstanding remainder
- **THEN** the over-received line contributes zero and does not reduce the other line's remainder

#### Scenario: Ineligible purchases and other products
- **WHEN** purchases are unapproved, rejected, archived, fully received, or contain only other products
- **THEN** they do not contribute to this product's estimate

### Requirement: Projection links to contributing global purchases
The page SHALL show `Lihat pembelian terkait ↗` beside the projection heading only to users with existing global purchase access. It SHALL open a new tab with the product's outstanding approved purchases across all businesses, initially unrestricted by payment status or dates. The destination SHALL enforce its own permission checks.

#### Scenario: Authorized navigation
- **WHEN** a user with price-management and global purchase permissions activates the link
- **THEN** a new tab opens the product's contributing purchase list without changing the active business session

#### Scenario: Price permission without global purchase access
- **WHEN** a user has price-management permission but lacks global purchase access
- **THEN** the projection remains visible and the document link is hidden
- **AND** direct navigation to the destination is denied
