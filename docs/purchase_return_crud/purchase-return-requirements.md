# Purchase Return Requirements (Create Flow - Current Implementation)

## 1. Overview
- The purchase return create flow is Livewire-driven (`PurchaseReturnCreateForm` and `PurchaseReturnTable`).
- Scope covers creation of a pending purchase return document with line-level location and serial handling; inventory mutation happens later in the dispatch flow.

## 2. Goals & Non-goals
### Goals
- Require a supplier before adding return lines.
- Filter product selection to items previously received from the selected supplier.
- Support multiple return lines with required per-line location.
- Allow duplicate products only when the location differs.
- Filter locations to positive stock for the selected product and show `Tenant Name - Location Name` labels.
- Enforce serial-driven location locking for serial-tracked products.
- Hide purchase price in the create UI while still calculating totals.
- Create pending return documents without inventory mutation.
- Gate price-related columns in list and detail views by a price-view permission.
- Restrict approve/reject actions to users with an approval permission.
- Ensure role create/update screens include the new purchase return permissions.
- Ensure the permission seeder includes the new purchase return permissions.
- Keep edit flow aligned with create behavior and UI.

### Non-goals
- Removing `purchase_returns.location_id` from the schema or migrating legacy data.
- Purchase order selection or auto-locking purchase order from serials in the create flow.
- Inventory reservation or stock deduction during creation.
- Editing pricing, discounts, or taxes during creation.

## 3. Personas
- Return Creator: creates purchase return documents and needs accurate line locations without seeing purchase prices.
- Inventory Controller: ensures returns map to correct locations and serials before approval.

## 4. User Journeys
- Create return with non-serial product: select supplier, add product rows, choose locations with positive stock, enter quantities, submit to create a pending return.
- Create return with serial-tracked product: scan/enter serials, system auto-fills and locks location, quantity auto-syncs to serial count, submit to create a pending return.
- Create return with duplicate product lines: add the same product on multiple rows with different locations; submission is allowed.

## 5. Functional Requirements
- Access control:
  - Only users with `purchaseReturns.create` permission can access the create page and submit.
  - Only users with `purchaseReturns.approval` permission can approve or reject purchase returns.
  - Only users with `purchaseReturns.viewPrice` permission can see price-related columns in list and detail views.
  - Role create/update UIs must expose `purchaseReturns.viewPrice` and `purchaseReturns.approval` for assignment.
  - Permission seeder must include `purchaseReturns.viewPrice` and `purchaseReturns.approval`.
- Return header:
  - Supplier is required.
  - Date is required and defaults to today.
  - Header-level location is not set during create.
- Product selection:
  - Product list is limited to items with purchases for the selected supplier where purchase status is `RECEIVED` or `RECEIVED PARTIALLY`.
- Return lines:
  - Each line requires `product_id`, `quantity`, and `location_id`.
  - Lines are unique by `(product_id, location_id)`.
  - Quantity is manual for non-serial products; for serial products it is derived from serial count.
  - Stock at the selected location is displayed per line.
- Location selection:
  - Location list is filtered by `ProductStock` with `quantity > 0` for the selected product.
  - Labels are formatted as `Tenant Name - Location Name` and searchable by tenant or location name.
- Serial handling:
  - Serial input is required when `serial_number_required` is true.
  - Serials must exist in `ProductSerialNumber`, match the product, and not be dispatched.
  - All serials in a row must share the same location.
  - Location is auto-filled and locked from the first serial entry.
  - Serials are unique across the return (case-insensitive).
- Validation and totals:
  - The return total must be greater than 0.
  - Totals use the product last purchase price even though price is hidden in the UI.
  - Create-time stock validation checks that the selected location has positive stock (no quantity comparison).
- Persistence:
  - Header fields include supplier, date, totals, and pending approval status.
  - Detail rows store `location_id` and `serial_number_ids`.
- Lifecycle:
  - Created returns are `approval_status = pending` and `status = Pending Approval`.
  - No inventory mutation or serial status changes occur during creation.
- Edit flow:
  - Edit uses the same UI and validation rules as create (line-level location, serial locking, price hidden).
  - Edits are locked when approval status is approved.
- List and detail visibility:
  - Price-related columns (total, paid, due) are hidden in the purchase return list for users without `purchaseReturns.viewPrice`.
  - Price-related fields (unit price, discount, tax, subtotal, totals, and cash/deposit values) are hidden in the detail view for users without `purchaseReturns.viewPrice`.
  - UI and backend must both enforce permission gating.

## 6. Non-Functional Requirements
- Performance: product, location, and serial lookups respond quickly for expected load.
- Data integrity: validations prevent invalid serials, duplicate lines, or zero-stock locations.
- Auditability: create and validation failures are logged.

## 7. Assumptions
- `ProductStock` and `ProductSerialNumber` are authoritative sources.
- Session `setting_id` exists and is required when creating return headers.
- Supplier master data is valid and available.
- New permissions (`purchaseReturns.viewPrice` and `purchaseReturns.approval`) are defined and assigned via roles.

## 8. Constraints
- Location is strictly line-level and required for every row.
- Serial-tracked rows cannot manually set location; location is derived from serials.
- Location dropdown results are capped (10 results per search query).
- Purchase price is hidden in the create UI.
- Price-related fields must not be rendered or returned for users without `purchaseReturns.viewPrice`.
- Edit flow must remain consistent with create UI and validation rules.

## 9. Open Questions
- None at this time.
