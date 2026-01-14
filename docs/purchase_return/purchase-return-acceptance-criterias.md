# Purchase Return Acceptance Criteria

## Ticket 1: Gate purchase return create by permission
Scenario: Access with permission
Given a user has the purchase-return-create permission
When the user opens the purchase return create page
Then the page loads and the create API is accessible

Scenario: Access without permission
Given a user lacks the purchase-return-create permission
When the user opens the purchase return create page or calls the create API
Then access is blocked and the API returns 403 with a permission error

Scenario: Permission revoked mid-session
Given a user previously had permission but it is revoked
When the user submits a purchase return
Then the API denies the request with 403 and the UI shows an authorization error

## Ticket 2: Update purchase return header (supplier required, remove header location)
Scenario: Create with supplier
Given the user is creating a purchase return
When the user selects a supplier and submits without a header location
Then the return is created and no header location is stored

Scenario: Missing supplier
Given the user is creating a purchase return
When the user submits without selecting a supplier
Then submission fails with a supplier-required validation error

Scenario: Legacy header location payload
Given a client includes a header location field in the create payload
When the create API processes the request
Then the header location value is ignored and not persisted

## Ticket 3: Implement multi-line return items with per-line location
Scenario: Multiple valid lines
Given a return with multiple product lines including quantity and location
When the user submits the return
Then the return is created with all lines stored as submitted

Scenario: Duplicate product with different locations
Given a return contains the same product on two lines with different locations
When the user submits the return
Then both lines are accepted and stored

Scenario: Duplicate product with same location
Given a return contains the same product on two lines with the same location
When the user submits the return
Then submission fails with a duplicate-line validation error

## Ticket 4: Location search dropdown filtered by positive stock across tenants
Scenario: Locations filtered by positive stock
Given a product is selected and some locations have positive stock
When the user searches for a location
Then only positive-stock locations are listed with labels formatted `Tenant Name - Location Name`

Scenario: No positive stock locations
Given a product has zero stock across all locations
When the user opens the location dropdown
Then the dropdown shows an empty state and no selection can be made

Scenario: Stock becomes unavailable before submit
Given a user selected a location that had positive stock
When the user submits after stock has dropped to zero
Then the server rejects the submission with a stock-unavailable error

## Ticket 5: Serial lookup to auto-select and lock location
Scenario: Serial lookup succeeds
Given a serial-tracked product and a valid serial in the global registry
When the user enters the serial
Then the line location auto-fills and becomes read-only

Scenario: Serial lookup fails
Given a serial-tracked product and a serial that does not exist in the registry
When the user enters the serial and submits
Then submission is blocked with a serial-not-found error

Scenario: Serial belongs to a different product
Given a serial is linked to a different product in the registry
When the user enters the serial on this product line
Then the system shows a mismatch error and blocks submission

## Ticket 6: Enforce serial uniqueness and consistency per return
Scenario: Unique serials per return
Given a return with serial-tracked lines using distinct serials
When the user submits the return
Then submission succeeds

Scenario: Duplicate serials with casing differences
Given a return includes serials "abc123" and "ABC123"
When the user submits the return
Then submission fails with a duplicate-serial validation error

Scenario: Serial entered on non-serial product
Given a product that is not serial-tracked
When a serial is submitted on that line
Then submission fails with a serial-not-allowed error

## Ticket 7: Create pending return document without inventory mutation
Scenario: Create pending document
Given a valid return submission
When the user submits the return
Then a pending return document is created and no inventory mutation or reservation occurs

Scenario: Verify no ledger changes
Given a return has been created in pending status
When inventory ledgers are checked
Then no mutation or reservation entries exist for the return

Scenario: Atomic persistence on failure
Given a database error occurs while saving return lines
When the submission is processed
Then no partial return records are persisted

## Ticket 8: Re-validate stock at approval (hook)
Scenario: Approval succeeds with sufficient stock
Given a pending return and current stock is sufficient
When approval is executed
Then approval succeeds and stock is reserved or mutated on final approval

Scenario: Approval fails with insufficient stock
Given a pending return and current stock is insufficient
When approval is executed
Then approval fails with a stock-validation error and status remains pending

Scenario: Serial location mismatch at approval
Given a pending return with serials whose locations changed
When approval is executed
Then approval fails with a serial-location-mismatch error

## Ticket 9: Hide purchase price on purchase create
Scenario: Price fields hidden on create form
Given a user opens the purchase create page
When the form renders
Then no purchase price fields are visible

Scenario: Create succeeds without price
Given a user submits a purchase create request without price fields
When the API processes the request
Then the purchase is created and the response excludes price data

Scenario: Price provided on create
Given a client submits a purchase create request with price fields
When the API validates the request
Then the request is rejected with a price-not-allowed-on-create error
