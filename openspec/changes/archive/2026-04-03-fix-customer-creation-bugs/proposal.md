## Why

Two bugs break the customer creation workflow:

1. Customers created from POS (`/pos/sell`) appear with a blank "Nama Pelanggan" on the customer list (`/customers`) because POS stores the name in `customer_name` while the listing displays `contact_name`.
2. Creating a customer from the admin form (`/customers/create`) with no email fails with a generic "Hubungi Administrator" error because empty-string emails violate the database unique constraint on `(setting_id, customer_email)`.

## What Changes

- **POS customer creation**: Store the customer name in `contact_name` (in addition to `customer_name`) so it appears correctly in the customer list.
- **Admin customer creation**: Store `NULL` instead of empty string `''` for optional unique fields (`customer_email`) to avoid unique constraint violations when the field is left blank.
- **Error messaging**: Surface duplicate-entry errors as user-friendly validation messages instead of the generic "Hubungi Administrator" catch-all.

## Capabilities

### New Capabilities
- `customer-creation-field-consistency`: Ensure customers created from any entry point (POS, admin, quick-add) populate fields consistently so they display correctly across all views.
- `customer-nullable-unique-fields`: Handle optional unique fields (email, identity_number, npwp) with NULL instead of empty string to satisfy database unique constraints.

### Modified Capabilities
- `customer-crud-observability`: The error handling in the admin store action needs to surface constraint violations as validation messages rather than swallowing them into the generic catch-all.

## Impact

- **Files**: `Modules/Pos/Http/Controllers/PosSellController.php`, `Modules/People/Http/Controllers/CustomersController.php`, `app/Livewire/Modules/People/Modals/CustomerQuickAddModal.php`
- **Database**: No schema changes needed — only changing what values are stored (NULL vs empty string).
- **APIs**: POS customer store endpoint response unchanged. Admin form redirect behavior unchanged.
- **Risk**: Low. Changes are to value normalization and error handling only.
