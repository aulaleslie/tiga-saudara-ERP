# Purchase Return Engineering Tickets (Current Implementation)

## Current Implementation Snapshot
- Create flow is Livewire-driven (`PurchaseReturnCreateForm` + `PurchaseReturnTable`) and guarded by `purchaseReturns.create`.
- Supplier selection is required; product list is filtered to items received from that supplier.
- Line items are stored in `purchase_return_details` with `location_id` and `serial_number_ids`.
- Location dropdown is filtered by `ProductStock` with `quantity > 0` and labeled `Tenant Name - Location Name`.
- Serial entry uses `PurchaseOrderSerialNumberLoader` to validate serials, auto-fill location, and lock the row.
- Form validation enforces required fields, duplicate line prevention, serial uniqueness, and positive-stock locations.
- Create sets `approval_status = pending` with no inventory mutation; approval re-validates stock.
- Create UI hides purchase price, but totals are computed using the last purchase price.
- Legacy controller `store` exists but is deprecated in favor of Livewire.
- Purchase return list and detail views always show price-related fields (no price-view permission gating).
- Approve/reject actions are gated by `purchaseReturns.edit` (no separate approval permission).

## Ticket Status

## Ticket 1 (Done)
Title: Gate purchase return create by permission

Implementation:
- `PurchasesReturnController@create` and `PurchaseReturnCreateForm::authorizeCreate()` both enforce `purchaseReturns.create`.

## Ticket 2 (Done)
Title: Require supplier; remove header location usage

Implementation:
- Supplier selection is required and validated.
- Create does not set header-level `location_id` (legacy column remains unused in create flow).

## Ticket 3 (Done)
Title: Multi-line return items with per-line location

Implementation:
- Each line requires `product_id`, `quantity`, and `location_id`.
- Duplicate lines are blocked for the same `(product_id, location_id)`.

## Ticket 4 (Done)
Title: Location dropdown filtered by positive stock

Implementation:
- Location dropdown queries `ProductStock` with `quantity > 0` per product.
- Labels are `Tenant Name - Location Name`.

## Ticket 5 (Done)
Title: Serial lookup to auto-select and lock location

Implementation:
- `PurchaseOrderSerialNumberLoader` validates serial existence and dispatch status.
- First serial sets and locks the location for the row.
- No purchase order auto-locking is implemented in create.

## Ticket 6 (Done)
Title: Enforce serial uniqueness and consistency per return

Implementation:
- Serials are unique across rows (case-insensitive).
- Serial location is validated against the row location.

## Ticket 7 (Done)
Title: Create pending return document without inventory mutation

Implementation:
- Create saves header + details with `approval_status = pending` and `status = Pending Approval`.
- No stock mutation or serial status updates occur on create.

## Ticket 8 (Done)
Title: Re-validate stock at approval

Implementation:
- `PurchasesReturnController@approve` calls `validateStockForApproval` to check quantity and serial status/location.

## Ticket 9 (Done)
Title: Hide purchase price on purchase return create

Implementation:
- Create UI excludes price/subtotal columns; totals are calculated using last purchase price.

## Ticket 10 (Not Implemented)
Title: Auto-lock purchase order from serials

Implementation:
- The create flow does not capture or lock purchase order IDs from serials.

## Ticket 11 (Not Implemented)
Title: Gate price-related columns in list and detail views

Implementation:
- Add `purchaseReturns.viewPrice` permission and gate price-related columns in the list (total/paid/due) and detail view (unit price, discount, tax, subtotal, totals, and cash/deposit values).
- Ensure exports/print paths also respect the permission.

## Ticket 12 (Not Implemented)
Title: Require approval permission to approve or reject

Implementation:
- Add `purchaseReturns.approval` permission and enforce it in approve/reject endpoints and UI actions.
