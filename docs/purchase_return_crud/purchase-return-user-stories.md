# Purchase Return User Stories

## Access & Permissions
- As a return creator, I want to access the purchase return creation form, so that I can submit return documents.

## Return Header
- As a return creator, I want to select a single supplier for the return, so that all lines are associated to the correct supplier.

## Return Lines & Location Selection
- As a return creator, I want to add multiple product lines to a return, so that I can return several items in one document.
- As a return creator, I want to select a location per product line, so that each item is returned from the correct tenant location.
- As a return creator, I want the location list to show only locations with positive stock for the selected product, so that I can only choose valid locations.
- As a return creator, I want to see locations labeled as `Tenant Name - Location Name`, so that I can distinguish same-named locations across tenants.
- As a return creator, I want to add duplicate product lines when the locations differ, so that I can return the same product from multiple locations.

## Serial-Tracked Products
- As a return creator, I want the system to look up serials in the global serial registry, so that the correct location is identified.
- As a return creator, I want the location to auto-fill and become read-only after entering a serial, so that the return stays consistent with the registry.
- As a return creator, I want the system to block submission when a serial has no matched location, so that invalid returns are prevented.
- As a return creator, I want serials on a return to be unique and consistent, so that I avoid duplicate or conflicting serial entries.

## Submission & Approval Lifecycle
- As a return creator, I want to submit a return and create a pending document, so that approval can be completed before inventory changes.
- As an inventory controller, I want stock to be re-validated at approval, so that the final decision reflects actual availability.

## Purchase Return (Price Visibility)
- As a return creator, I want purchase prices hidden on the purchase return create form, so that pricing is not exposed during return creation.
