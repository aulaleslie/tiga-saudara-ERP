# Purchase Return Engineering Tickets

## Ticket 1
Title: Gate purchase return create by permission

Description:
Ensure only users with the return-creation permission can access the purchase return creation UI and API.

Scope:
- UI access control (menu/route guard).
- API endpoint authorization for create actions.
- Permission error messaging for blocked access.

Technical notes:
- Reuse existing auth/permission middleware.
- Return 403 with a consistent error payload for unauthorized access.

Dependencies:
- Existing permissions/roles framework.

Edge cases:
- User has UI access but API denies due to stale permission cache.
- Role changes mid-session.

## Ticket 2
Title: Update purchase return header (supplier required, remove header location)

Description:
Adjust the return header to require a single supplier and remove any header-level location fields.

Scope:
- UI form update to require supplier.
- API payload/schema update to drop header location.
- Migration strategy to preserve data integrity for existing records (no destructive changes).

Technical notes:
- If header location is stored in DB, deprecate it without breaking reads.
- Validate supplier presence on create.

Dependencies:
- Supplier master data.
- Migration process for legacy data.

Edge cases:
- Legacy returns with header location still present.
- Supplier disabled or deleted during creation.

## Ticket 3
Title: Implement multi-line return items with per-line location

Description:
Enable multiple product lines per return with required product, quantity, and location fields on each line.

Scope:
- UI support for adding/removing multiple lines.
- API accepts and validates line arrays.
- Allow duplicate product lines when locations differ.

Technical notes:
- Enforce quantity > 0 validation.
- Validate location is present for each line.
- Allow same product in multiple lines only if location differs.

Dependencies:
- Product master data.
- Location master data.

Edge cases:
- Same product + same location appears twice (should be blocked or merged).
- Empty line rows submitted.

## Ticket 4
Title: Location search dropdown filtered by positive stock across tenants

Description:
Provide a searchable location selector per line that shows only locations with positive stock for the selected product across tenants.

Scope:
- Location search UI with async filtering.
- Backend query that returns only positive-stock locations.
- Display label format: `Tenant Name - Location Name`.

Technical notes:
- Query should be tenant-aware but allow global visibility for return creators.
- Include stock availability in response if available for validation.

Dependencies:
- Accurate per-location stock data source.
- Tenant and location naming data.

Edge cases:
- No locations with positive stock (empty dropdown state).
- Same location name across tenants (label disambiguation).
- Stock changes between search and submit.

## Ticket 5
Title: Serial lookup to auto-select and lock location

Description:
For serial-tracked products, look up serials in the global registry and auto-fill the line location as read-only. Block submission if no match is found.

Scope:
- Serial input field per line for serial-tracked products.
- Serial lookup integration on input/change.
- Auto-fill and lock location on successful match.
- Prevent submission on lookup failure.

Technical notes:
- Lookup must also validate that serial matches the selected product.
- Location field becomes read-only only for serial-tracked lines with a match.

Dependencies:
- Global serial registry service/table.
- Product-to-serial tracking rules.

Edge cases:
- Serial exists but belongs to a different product.
- Serial exists but location is inactive.
- Serial lookup timeout or temporary failure.

## Ticket 6
Title: Enforce serial uniqueness and consistency per return

Description:
Prevent duplicate or conflicting serial entries within the same return document.

Scope:
- Validation to ensure serials are unique within the return.
- Ensure serial-linked location matches the line location.
- Clear error messages for conflicts.

Technical notes:
- Validate on both client and server to avoid race conditions.
- Normalize serial values (trim/case) before comparison.

Dependencies:
- Serial registry lookup from Ticket 5.

Edge cases:
- Same serial entered with different casing or whitespace.
- Serial entered on a non-serial-tracked product.

## Ticket 7
Title: Create pending return document without inventory mutation

Description:
Submitting a purchase return should create a pending document only; no stock mutation or reservation occurs at creation.

Scope:
- Set status to pending on create.
- Ensure no inventory mutation/reservation is triggered.
- Persist return header and lines as document data only.

Technical notes:
- Audit log entry for document creation.
- Block downstream mutation calls for pending returns.

Dependencies:
- Return status model/enum.
- Existing inventory mutation services.

Edge cases:
- Duplicate submissions creating multiple pending documents.
- Partial failure after header save but before line save.

## Ticket 8
Title: Re-validate stock at approval (hook)

Description:
On approval, re-validate actual stock availability for each line before final approval completes.

Scope:
- Add validation step in the approval pipeline.
- Reject approval if stock is insufficient or serial no longer matches location.

Technical notes:
- This is a backend hook only; no approval UI changes required.
- Validation logic should mirror creation-time checks but against current stock.

Dependencies:
- Approval workflow pipeline.
- Stock validation services.

Edge cases:
- Stock changed after creation (now insufficient).
- Serial moved to a different location before approval.

## Ticket 9
Title: Hide purchase price on purchase create

Description:
Remove purchase price visibility from the purchase create UI and ensure API responses do not expose it during creation.

Scope:
- UI: remove/hide price fields on create form.
- API: do not return or require price on create.

Technical notes:
- Ensure edit or view pages remain unchanged unless specified.

Dependencies:
- Purchase create form components.
- Purchase create API endpoint.

Edge cases:
- User role with broader permissions still should not see price on create.
- Cached frontend data that includes price fields.
