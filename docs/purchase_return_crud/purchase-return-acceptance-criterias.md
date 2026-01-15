# Purchase Return Acceptance Criteria (Current Implementation)

## Ticket 1: Gate purchase return create by permission
Scenario: Access with permission
Given a user has the `purchaseReturns.create` permission
When the user opens the purchase return create page
Then the page loads and the create flow is accessible

Scenario: Access without permission
Given a user lacks the `purchaseReturns.create` permission
When the user opens the purchase return create page or submits the form
Then access is blocked with a 403 authorization error

Scenario: Permission revoked mid-session
Given a user previously had permission but it is revoked
When the user submits a purchase return
Then the request is denied with a 403 authorization error

## Ticket 2: Supplier required; header location not set
Scenario: Create with supplier
Given the user is creating a purchase return
When the user selects a supplier and submits
Then the return is created and header location is not set

Scenario: Missing supplier
Given the user is creating a purchase return
When the user submits without selecting a supplier
Then submission fails with a supplier-required validation error

## Ticket 3: Multi-line return items with per-line location
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

## Ticket 4: Location dropdown filtered by positive stock
Scenario: Locations filtered by positive stock
Given a product is selected and some locations have positive stock
When the user searches for a location
Then only positive-stock locations are listed with labels `Tenant Name - Location Name`

Scenario: No positive stock locations
Given a product has zero stock across all locations
When the user opens the location dropdown
Then the dropdown shows an empty state and no selection can be made

Scenario: Stock unavailable at submit
Given a user selected a location with no stock
When the user submits the return
Then submission fails with a stock-unavailable validation error

## Ticket 5: Serial lookup to auto-select and lock location
Scenario: Serial lookup succeeds
Given a serial-tracked product and a valid serial in the registry
When the user enters the serial
Then the line location auto-fills and becomes read-only

Scenario: Serial lookup fails
Given a serial-tracked product and a serial that does not exist or is dispatched
When the user enters the serial
Then the row shows an error and submission is blocked until corrected

Scenario: Serials from different locations in one row
Given a serial-tracked product and an existing serial already selected
When the user adds a serial from a different location
Then the row shows an error and the serial is not added

## Ticket 6: Enforce serial uniqueness and consistency per return
Scenario: Unique serials per return
Given a return with serial-tracked lines using distinct serials
When the user submits the return
Then submission succeeds

Scenario: Duplicate serials with casing differences
Given a return includes serials "abc123" and "ABC123" on different rows
When the user submits the return
Then submission fails with a duplicate-serial validation error

Scenario: Serial entered on non-serial product
Given a product that is not serial-tracked
When a serial is submitted on that line
Then submission fails with a serial-not-allowed validation error

## Ticket 7: Create pending return document without inventory mutation
Scenario: Create pending document
Given a valid return submission
When the user submits the return
Then a pending return document is created and no inventory mutation occurs

Scenario: Verify line persistence
Given a return submission with line locations and serials
When the return is created
Then line items store `location_id` and `serial_number_ids`

## Ticket 8: Re-validate stock at approval
Scenario: Approval succeeds with sufficient stock
Given a pending return and current stock is sufficient
When approval is executed
Then approval succeeds and the return status updates to approved

Scenario: Approval fails with insufficient stock
Given a pending return and current stock is insufficient
When approval is executed
Then approval fails with a stock-validation error and status remains pending

Scenario: Serial location or status mismatch at approval
Given a pending return with serials whose locations or status changed
When approval is executed
Then approval fails with a serial-validation error

## Ticket 9: Hide purchase price on purchase return create
Scenario: Price fields hidden on create form
Given a user opens the purchase return create page
When the form renders
Then no purchase price or subtotal fields are visible

Scenario: Totals still computed on create
Given a user submits a purchase return
When the return is saved
Then totals are computed from the last purchase price even though price is hidden

## Ticket 11: Gate price-related columns in list and detail views
Scenario: List view without price permission
Given a user lacks `purchaseReturns.viewPrice`
When the user opens the purchase return list
Then total, paid, and due columns are hidden and not exported

Scenario: List view with price permission
Given a user has `purchaseReturns.viewPrice`
When the user opens the purchase return list
Then total, paid, and due columns are visible

Scenario: Detail view without price permission
Given a user lacks `purchaseReturns.viewPrice`
When the user opens a purchase return detail page
Then line prices, discounts, taxes, and totals are hidden

Scenario: Detail view with price permission
Given a user has `purchaseReturns.viewPrice`
When the user opens a purchase return detail page
Then line prices, discounts, taxes, and totals are visible

Scenario: Permission assignment available
Given an admin manages roles
When the admin opens role create or update
Then `purchaseReturns.viewPrice` is available for assignment

Scenario: Permission seeded
Given the permissions seeder runs
When permissions are created
Then `purchaseReturns.viewPrice` exists in the permissions list

## Ticket 12: Require approval permission to approve or reject
Scenario: Approve/reject without approval permission
Given a user lacks `purchaseReturns.approval`
When the user attempts to approve or reject a return
Then the action is blocked with a 403 authorization error and buttons are hidden

Scenario: Approve/reject with approval permission
Given a user has `purchaseReturns.approval`
When the user approves or rejects a pending return
Then the action succeeds and the status updates accordingly

Scenario: Permission assignment available
Given an admin manages roles
When the admin opens role create or update
Then `purchaseReturns.approval` is available for assignment

Scenario: Permission seeded
Given the permissions seeder runs
When permissions are created
Then `purchaseReturns.approval` exists in the permissions list

## Ticket 13: Edit flow matches create behavior and UI
Scenario: Edit uses same rules as create
Given a user opens a purchase return edit page
When the edit form renders
Then the UI and validation rules match the create form (line-level location, serial locking, price hidden)

Scenario: Edit locked after approval
Given a purchase return is approved
When a user opens the edit page
Then fields that are locked in create are also locked in edit
