# Purchase Return User Stories (Create Flow)

## Access & Permissions
- As a return creator, I want access to the purchase return create form only if I have `purchaseReturns.create` permission.

## Supplier & Product Selection
- As a return creator, I want to select a supplier before adding lines, so the product list is scoped correctly.
- As a return creator, I want the product list to show only items previously received from the selected supplier.

## Return Lines & Location Selection
- As a return creator, I want to add multiple product lines, so I can return several items in one document.
- As a return creator, I want to select a location per line, so the return maps to the correct stock location.
- As a return creator, I want the location list to show only locations with positive stock for that product.
- As a return creator, I want location labels formatted as `Tenant Name - Location Name` to avoid ambiguity.
- As a return creator, I want to see the current stock at the selected location per line.
- As a return creator, I want duplicate product lines allowed only when locations differ.

## Serial-Tracked Products
- As a return creator, I want to scan or enter serial numbers, so the system can verify and capture serial-tracked returns.
- As a return creator, I want the location to auto-fill and lock after the first serial, so the row stays consistent.
- As a return creator, I want the quantity to auto-sync to the number of serials I add.
- As a return creator, I want errors when a serial is invalid, dispatched, or belongs to a different location.
- As a return creator, I want serials to be unique across the entire return.

## Submission & Lifecycle
- As a return creator, I want to submit a return and create a pending document without inventory mutation.
- As an inventory controller, I want approval-time validation of stock and serial status before approval proceeds.

## Pricing Visibility
- As a return creator, I want purchase prices hidden on the create form, even though totals are calculated in the background.
- As a user without price-view permission, I want price-related columns hidden on the purchase return list and detail pages.
- As a user with price-view permission, I want to see totals and line pricing on the purchase return list and detail pages.

## Approval Permissions
- As an approver, I want to approve or reject returns only if I have approval permission.
- As a non-approver, I want approve/reject actions hidden or blocked.

## Role Management
- As an admin, I want the new purchase return permissions available when creating or updating roles.

## Edit Consistency
- As a return editor, I want the edit form to match the create form behavior and UI, so I can edit with the same rules.
