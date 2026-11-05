## ADDED Requirements

### Requirement: Global workspace supports projected-HPP source purchase drilldown
The standalone global purchase workspace SHALL accept a durable product projection filter. In this mode it SHALL list distinct non-archived `APPROVED` and `RECEIVED PARTIALLY` purchases having a positive outstanding line for that product across all businesses. The view SHALL identify the product and active projection scope, independent of payment status.

#### Scenario: Approved purchase without receipts is listed
- **WHEN** an authorized user opens the projection link for a product with an approved unreceived purchase
- **THEN** that purchase appears even though it is not eligible for the normal global payment list

#### Scenario: Only the selected product has to remain outstanding
- **WHEN** a purchase contains the selected product fully received and another product still outstanding
- **THEN** the purchase is excluded from the selected product's drilldown

#### Scenario: Multiple contributing lines do not duplicate documents
- **WHEN** one purchase has multiple positive outstanding lines for the selected product
- **THEN** the list displays that purchase once

#### Scenario: Paid purchases remain visible
- **WHEN** an otherwise contributing purchase is fully paid
- **THEN** it remains visible because this drilldown represents goods outstanding rather than money outstanding

#### Scenario: Source set changes after page load
- **WHEN** a contributing purchase is archived or its remaining quantity is approved as received
- **THEN** a refreshed drilldown excludes that purchase if it no longer contributes

### Requirement: Projection drilldown has durable and coherent filtering
The product projection filter SHALL survive refresh, search, sorting, pagination, and business/date filter changes. The initial link SHALL select all businesses without date, payment, or summary-card restrictions. The view SHALL hide payment summary cards in projection mode and SHALL show an empty state when no contributing purchase remains.

#### Scenario: Refresh and pagination retain product scope
- **WHEN** the user reloads or moves between pages in a product projection drilldown
- **THEN** only purchases contributing to that product remain eligible

#### Scenario: Additional filters narrow the source set
- **WHEN** the user applies business, date, or search filters
- **THEN** results satisfy those filters and the product's positive-outstanding constraint

#### Scenario: No source documents remain
- **WHEN** no eligible purchase has positive outstanding quantity for the product
- **THEN** the view shows no purchase rows and retains its product scope label

#### Scenario: Invalid product identifier
- **WHEN** a projection link names a missing product or an invalid identifier
- **THEN** the request returns a validation or not-found response rather than an unrestricted purchase list

### Requirement: Projection navigation preserves global purchase authorization and payment eligibility
Projection list and document detail access SHALL require `purchasePayments.global.access`. Approved unreceived source documents SHALL be readable in projection context without becoming payment candidates. Normal standalone and supplier-embedded global payment behavior SHALL retain existing eligibility, permissions, summaries, and allocation rules.

#### Scenario: User inspects an approved source document
- **WHEN** an authorized user opens an approved unreceived purchase from the product drilldown
- **THEN** the matching document is readable across businesses without changing the active business
- **AND** no payment action becomes available solely because it appears in projection mode

#### Scenario: Projection context cannot expose unrelated documents
- **WHEN** a projection-context detail request targets a purchase that does not contribute to the selected product
- **THEN** the system denies or returns not found for that projection-context request

#### Scenario: Unauthorized direct request
- **WHEN** a user without global purchase access directly requests the filtered list or source detail
- **THEN** access is denied

#### Scenario: Standard payment views are unchanged
- **WHEN** the standalone or supplier-embedded workspace is opened without projection context
- **THEN** existing payment eligibility and summary behavior apply
- **AND** approved unreceived purchases do not become payment candidates
