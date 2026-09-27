## ADDED Requirements

### Requirement: Monetary columns store decimal rupiah
All monetary columns outside the POS module SHALL be typed `decimal(15,2)` and SHALL store values denominated in rupiah. The stored value and the in-memory value MUST be numerically identical.

#### Scenario: Payment amount round-trips unchanged
- **WHEN** a purchase payment, sale payment, expense, sale return payment, or purchase return payment is saved with an amount of `500000.00`
- **THEN** the persisted column value is `500000.00`
- **AND** reading the amount back from the model returns `500000.00`

#### Scenario: Product cost and price round-trip unchanged
- **WHEN** a product is saved with `product_cost` of `250000.00` and `product_price` of `400000.00`
- **THEN** the persisted column values are `250000.00` and `400000.00`
- **AND** reading those attributes back returns `250000.00` and `400000.00`

#### Scenario: Quotation amounts round-trip unchanged
- **WHEN** a quotation and its details are saved with monetary values
- **THEN** reading every monetary attribute back returns the value that was written
- **AND** no monetary attribute is scaled by 100 in either direction

### Requirement: Models perform no unit conversion on monetary attributes
Eloquent models SHALL NOT define accessors or mutators that multiply or divide monetary attributes by 100. Reading and writing a monetary attribute MUST NOT change its magnitude.

#### Scenario: No scaling mutators remain on affected entities
- **WHEN** the `PurchasePayment`, `Expense`, `SaleReturnPayment`, `Product`, `Quotation`, or `QuotationDetails` entity is inspected
- **THEN** it defines no monetary accessor or mutator that applies a factor of 100

#### Scenario: Accessor and direct column reads agree
- **WHEN** a monetary value is read through an Eloquent accessor and the same column is read directly via a query builder
- **THEN** both reads return the same numeric value

### Requirement: Aggregations over monetary columns apply no scaling factor
Query scopes, `withSum` aggregations, raw SQL, and report aggregations that read monetary columns SHALL consume those values directly without dividing or multiplying by 100.

#### Scenario: Outstanding balance aggregation is unscaled
- **WHEN** a purchase's effective paid amount is computed from the sum of its active payments, through either a `withSum` aggregate or a relation query
- **THEN** the resulting amount equals the plain sum of the payment amounts with no division by 100

#### Scenario: Balance filter scope is unscaled
- **WHEN** a query filters documents by outstanding balance using a raw SQL sum of payment amounts
- **THEN** the SQL sums the amount column directly without dividing by 100
- **AND** a document whose payments total less than its document total is included in the outstanding set

### Requirement: Report totals are unaffected by the storage change
Operational report figures SHALL remain identical before and after the storage conversion. Reports MUST derive amounts from stored values without inferring per-row units.

#### Scenario: Report aggregation does not infer row units
- **WHEN** an operational report aggregates purchase return, purchase return payment, sale return payment, purchase payment, or expense amounts
- **THEN** it sums the stored values directly
- **AND** it does not inspect reference prefixes, creation timestamps, or related-record existence to decide how to scale a row

#### Scenario: Reported figures are unchanged
- **WHEN** the balance sheet, cash flow, profit and loss, trial balance, or general ledger report is generated over a given scope and date range
- **THEN** the reported totals match the values produced before the storage conversion

### Requirement: POS minor-unit storage is exempt
POS columns named with a `_minor_units` suffix SHALL continue to store integer minor units and are explicitly excluded from this convention. Conversion between minor units and rupiah MUST occur only at POS boundaries.

#### Scenario: POS minor-unit columns are unchanged
- **WHEN** a POS checkout payment or payment allocation is stored
- **THEN** its `*_minor_units` column stores an integer count of minor units
- **AND** the column type and its boundary conversions are unchanged by this change
