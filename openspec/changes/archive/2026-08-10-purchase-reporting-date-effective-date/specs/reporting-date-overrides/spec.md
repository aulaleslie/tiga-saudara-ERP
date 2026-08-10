## MODIFIED Requirements

### Requirement: Operational document views and defined purchase reports show the effective reporting date
The purchase and sale operational list and detail views SHALL display the effective document date, defined as `reporting_date` when present and the original document `date` otherwise. The original date and reporting-date change history SHALL remain available in the document's audit/history view.

The Primary Purchase Report, Purchase by Supplier Report, Purchase by Product Report, Purchase Order Completion Report, and purchase-side Sales Tax Report SHALL use the effective purchase reporting date, defined as the active purchase `reporting_date` when present and the original purchase `date` otherwise. Within each applicable report, date-range filters, date sorting or date grouping, displayed date values, and exported date values SHALL use that same effective date. A clear override SHALL cause the reports to use the original purchase date.

The Primary Purchase Report's transaction-date basis SHALL mean the effective purchase reporting date; its due-date basis SHALL continue to use the purchase due date.

The reporting-date audit history SHALL NOT be queried to determine the active report date; the current nullable `reporting_date` stored on the purchase SHALL be authoritative.

Purchase Delivery Report date filtering and ordering SHALL continue to use approved receiving-note dates. Aged Payables and Supplier Payables reports SHALL continue to use original purchase-date, due-date, and as-of ageing semantics; reporting-date overrides SHALL NOT change their inclusion, ageing, maturity, displayed date, sorting, or export behavior.

This requirement SHALL NOT change payables/receivables balances, stock, inventory valuation, or general-ledger behavior.

#### Scenario: List displays override as the document date
- **WHEN** a purchase or sale with a reporting-date override appears on its operational list
- **THEN** the displayed document date SHALL be the reporting-date override

#### Scenario: Detail displays original date when no override exists
- **WHEN** a purchase or sale without a reporting-date override is viewed
- **THEN** the displayed document date SHALL be the original document date

#### Scenario: Original date remains visible in history
- **WHEN** a user views a document with reporting-date audit entries
- **THEN** the audit/history view SHALL display the original document date and each recorded override change

#### Scenario: Purchase report includes a purchase by its active reporting date
- **WHEN** an in-scope purchase report is filtered for a date range containing a purchase's active reporting date but not its original purchase date
- **THEN** the report SHALL include that purchase
- **AND** the displayed and exported purchase date SHALL be the active reporting date

#### Scenario: Replaced reporting date is used by reports
- **WHEN** an authorized user replaces a purchase reporting-date override
- **THEN** each in-scope purchase report SHALL use the replacement value for subsequent filtering, sorting or grouping, display, and export
- **AND** the prior audit record SHALL NOT determine the report date

#### Scenario: Cleared reporting date restores original-date reporting
- **WHEN** an authorized user clears a purchase reporting-date override
- **THEN** each in-scope purchase report SHALL use the original purchase date for subsequent filtering, sorting or grouping, display, and export

#### Scenario: Operational and ageing reports retain their own date semantics
- **WHEN** a purchase has an active reporting-date override
- **THEN** Purchase Delivery Report SHALL continue to use approved receiving-note dates
- **AND** Aged Payables and Supplier Payables reports SHALL continue to use their original document-date, due-date, and as-of rules
