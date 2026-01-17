# Purchase Return Settlement — Acceptance Criteria

## Ticket 1: Add per-line approval fields to settlement items
Scenario: Happy path - migration adds approval fields
Given the database is at the current schema
When the migration for per-line approval fields is run
Then `purchase_return_item_settlements` includes nullable `method` and approval metadata columns

Scenario: Edge case - existing settlements with approved header
Given settlement items exist with an approved header settlement
When the migration backfill runs
Then each existing line is marked approved and retains its method and nominal

Scenario: Failure - invalid existing data
Given settlement items exist with missing references or invalid foreign keys
When the migration backfill runs
Then the migration fails with a clear error and no partial schema changes are committed

## Ticket 2: Update settlement entry UI for drafts and per-line submit
Scenario: Happy path - save draft with pending lines
Given a purchase return has multiple settlement lines
When a user saves the settlement with some lines missing a method
Then the settlement saves and those lines persist with a null method

Scenario: Edge case - submit locks a single line
Given a settlement line has a selected method and valid values
When the user submits that line for approval
Then the line becomes read-only and shows its submitted status

Scenario: Failure - submit without method or missing required fields
Given a settlement line has no method or missing required fields
When the user submits that line for approval
Then the system blocks submission and shows validation errors

## Ticket 3: Implement per-line approval and settlement effects
Scenario: Happy path - approve monetary line
Given a submitted line with method CASH and valid nominal
When an approver approves the line
Then the line status becomes approved and the cash record is adjusted in place

Scenario: Edge case - approve multiple lines over time
Given multiple lines are submitted at different times
When the approver approves them in separate actions
Then financial totals update incrementally without duplicate postings

Scenario: Failure - approval with invalid balances
Given a submitted MODIFY_PURCHASE line with a nominal above due amount
When the approver attempts to approve it
Then approval is rejected and no financial updates are applied

## Ticket 4: Roll-up status derivation for header and purchase return
Scenario: Happy path - partial settlement roll-up
Given a purchase return with both approved and pending lines
When roll-up status is calculated
Then `purchase_returns.status` is set to "Settled Partially"

Scenario: Edge case - all lines approved
Given all settlement lines are approved
When roll-up status is calculated
Then `purchase_returns.status` is set to "Settled"

Scenario: Failure - no settlement lines exist
Given a purchase return has no settlement lines
When roll-up status is calculated
Then the status remains unchanged and no error is thrown

## Ticket 5: Detail, list, and print UI updates for per-line status
Scenario: Happy path - per-line status in detail view
Given a purchase return with mixed settlement methods
When a user opens the purchase return detail page
Then each line shows its settlement method and approval status

Scenario: Edge case - lines without methods
Given a purchase return has pending lines with null methods
When the user views the detail or print view
Then those lines display as pending without approval actions

Scenario: Failure - missing settlement items
Given a purchase return has no settlement records
When the user views list or print views
Then the UI shows a default "Belum Diproses" state without errors

## Ticket 6: Permissions and gating adjustments
Scenario: Happy path - authorized approval
Given a user with approve permission
When they attempt to approve a submitted line
Then the approval succeeds

Scenario: Edge case - permission removed mid-session
Given a user opened the settlement page with submit permission
When their permission is revoked before submit
Then submit is blocked and the server returns an authorization error

Scenario: Failure - unauthorized access
Given a user without approve permission
When they attempt to approve a line
Then the request is denied and no data changes occur

## Ticket 7: Tests and regression coverage
Scenario: Happy path - tests for drafts and approvals
Given the updated test suite
When the tests are executed
Then new tests for draft save, per-line submit, and approval pass

Scenario: Edge case - idempotent approvals
Given a test that approves the same line twice
When the test runs
Then the second approval is blocked or no-op without duplicate effects

Scenario: Failure - regression in roll-up status
Given a test that validates roll-up status calculations
When the roll-up logic is incorrect
Then the test fails with clear assertion output
