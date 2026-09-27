## 1. Fix POS customer name field mapping

- [x] 1.1 In `PosSellController::customerStore()`, set `contact_name` to the provided `customer_name` value instead of empty string
- [x] 1.2 In `CustomerQuickAddModal`, set `contact_name` to the provided name value instead of empty string

## 2. Fix nullable unique fields in admin customer creation

- [x] 2.1 In `CustomersController::store()`, convert empty-string values to `null` for `customer_email`, `identity_number`, and `npwp` before passing to `Customer::create()`

## 3. Improve error handling for constraint violations

- [x] 3.1 In `CustomersController::store()`, catch `QueryException` with SQLSTATE 23000 separately and map known unique constraint names to user-friendly field-level validation errors
- [x] 3.2 Redirect back with `withErrors()` and `withInput()` for constraint violations instead of generic toast

## 4. Verification

- [x] 4.1 Test: create two customers from admin form with no email — both succeed
- [x] 4.2 Test: create customer from POS sell page — "Nama Pelanggan" shows in customer list
- [x] 4.3 Test: trigger a duplicate phone from admin form — shows field-level error, not "Hubungi Administrator"
