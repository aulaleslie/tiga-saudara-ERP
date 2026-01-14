# Purchase Return Engineering Tickets

## Current Implementation Snapshot
- Purchase return create is Livewire-driven (`PurchaseReturnCreateForm` + `PurchaseReturnTable`) with validation in `ValidatesPurchaseReturnForm`.
- Header-level `location_id` exists on `purchase_returns` (migration `2025_09_07_000001`), with a `PurchaseReturn::location()` relation used in views like settlement.
- Line items are stored in `purchase_return_details` with `serial_number_ids` but no `location_id`.
- Serial entry uses `PurchaseOrderSerialNumberLoader` and `SerialNumberController@validatePurchaseReturnSerial`, both expecting a `location_id`.
- Approval happens in `PurchasesReturnController@approve` with no stock revalidation; stock mutation occurs later in `dispatchReturn` using global `Product::product_quantity`.
- Purchase create UI uses Livewire `Purchase/CreateForm` + `Purchase/ProductCart`, which displays and edits purchase price.

## Ticket 1 (Completed)
Title: Gate purchase return create by permission

Description:
Ensure only users with the return-creation permission can access the purchase return creation UI and API.

Scope:
- UI access control (menu/route guard).
- API endpoint authorization for create actions.
- Permission error messaging for blocked access.

Technical notes:
- Current gating exists in `PurchaseReturnCreateForm::authorizeCreate()` and `PurchasesReturnController@create` using `purchaseReturns.create`.
- Verify menu visibility in `resources/views/layouts/menu.blade.php` and ensure Livewire/API returns 403 consistently.

Dependencies:
- Existing permissions/roles framework.

Edge cases:
- User has UI access but API denies due to stale permission cache.
- Role changes mid-session.

## Ticket 2 (Completed)
Title: Update purchase return header (supplier required, remove header location)

Description:
Adjust the return header to require a single supplier and remove any header-level location fields.

Scope:
- UI form update to require supplier.
- API payload/schema update to drop header location.
- Migration strategy to preserve data integrity for existing records (no destructive changes).

Technical notes:
- `purchase_returns.location_id` exists and is read in settlement/show views; ensure null-safe handling if header location is removed.
- Livewire create currently does not set `location_id`, but `PurchaseReturnTable` still carries a `locationId` property; remove or repurpose it for line-level location.
- Validate supplier presence on create (already enforced in `ValidatesPurchaseReturnForm`).

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
- Add `location_id` to `purchase_return_details` and a relation on `PurchaseReturnDetail`.
- Update Livewire row data in `PurchaseReturnTable`, `PurchaseReturnCreateForm`, and `PurchaseReturnEditForm` to persist `location_id`.
- Update `ValidatesPurchaseReturnForm` to allow duplicate `product_id` when `location_id` differs and require `location_id` per line.

Dependencies:
- Product master data.
- Location master data.
- `purchase_return_details` schema migration.

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
- Build a per-line location selector (new Livewire component or extend `LocationBusinessLoader`) keyed by `product_id`.
- Query `product_stocks` joined to `locations` + `settings` and filter to positive availability for the product.
- Label format should be `Tenant Name - Location Name` (reverse of current `LocationBusinessLoader` display).

Dependencies:
- Accurate per-location stock data source (`product_stocks`).
- Tenant and location naming data (`settings`, `locations`).

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
- Update `PurchaseOrderSerialNumberLoader` to derive `location_id` from `ProductSerialNumber` and emit it to the row.
- Review `SerialNumberController@validatePurchaseReturnSerial` (route: `POST /serial-numbers/validate-purchase-return`) to remove the required `location_id` input and return resolved location.
- Lookup must validate serial matches the selected product and is not dispatched.

Dependencies:
- Global serial registry (`product_serial_numbers`).
- Product-to-serial tracking rules (`serial_number_required`).

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
- `ValidatesPurchaseReturnForm` currently enforces unique `product_id` only; update to enforce unique serials across all rows.
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
- Livewire create already sets `approval_status` to `pending` and `status` to `Pending Approval`; align legacy `PurchasesReturnController@store` to the same behavior or deprecate it.
- Ensure no `ProductSerialNumber` return flags are set at create time (currently unused).
- Audit log entry for document creation if required by policy.

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
- Hook into `PurchasesReturnController@approve` for validation before status transitions.
- Validation logic should mirror creation-time checks but against current stock per `location_id` in details.
- Current dispatch mutates global `Product::product_quantity`; coordinate validation rules with existing dispatch flow.

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
- Purchase create UI is `resources/views/livewire/purchase/product-cart.blade.php`; remove the `Harga Beli` column and inline edit there only.
- Keep backend calculations in `Purchase/ProductCart` intact; do not affect purchase edit/view screens.

Dependencies:
- Purchase create form components.
- Purchase create API endpoint.

Edge cases:
- User role with broader permissions still should not see price on create.
- Cached frontend data that includes price fields.
