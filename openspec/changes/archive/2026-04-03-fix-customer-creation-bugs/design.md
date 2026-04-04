## Context

The application has three customer creation entry points:

1. **POS sell page** (`POST /pos/sell/customers`) — lightweight quick-add from the POS terminal
2. **Admin form** (`POST /customers`) — full-featured form at `/customers/create`
3. **Livewire quick-add modal** — used in various admin contexts

These entry points use different field mappings and handle optional fields differently, causing two bugs:
- POS stores name in `customer_name` but the listing shows `contact_name` → blank display
- Admin stores empty email as `''` but the DB unique constraint on `(setting_id, customer_email)` rejects the second empty-string row

The `customers` table has composite unique constraints on `(setting_id, customer_email)`, `(setting_id, customer_phone)`, and similar for `identity_number` and `npwp`.

## Goals / Non-Goals

**Goals:**
- Customers created from any entry point display correctly in the customer list
- Empty optional-unique fields don't cause constraint violations
- Constraint violation errors surface as user-friendly messages, not generic "Hubungi Administrator"

**Non-Goals:**
- Unifying the three creation forms into a single endpoint
- Changing the database schema or unique constraints
- Migrating existing data (existing placeholder emails from POS are already unique)

## Decisions

### D1: Copy `customer_name` into `contact_name` in POS create

**Decision**: In `PosSellController::customerStore()`, set `contact_name` to the provided `customer_name` value instead of empty string.

**Rationale**: The customer listing DataTable displays `contact_name` as "Nama Pelanggan". The POS form only collects one name field. Storing it in both `customer_name` and `contact_name` ensures display consistency without changing the DataTable or the admin form's field semantics (where `contact_name` = contact person, `customer_name` = company name).

**Alternative considered**: Change the DataTable to display `customer_name` instead. Rejected because `contact_name` is the correct "Nama Pelanggan" for admin-created customers and changing it would break that convention.

### D2: Store NULL instead of empty string for optional unique fields

**Decision**: In `CustomersController::store()`, convert empty strings to `NULL` for `customer_email`, `identity_number`, and `npwp` before insertion.

**Rationale**: MySQL allows multiple NULL values in a unique index but treats empty strings as equal. This is the standard pattern and requires no schema migration. The POS endpoint already avoids this by generating placeholder emails, but the admin form should use NULLs since the user explicitly left these blank.

**Alternative considered**: Generate placeholder values (like POS does with `noemail-xxx@placeholder.local`). Rejected for admin because admin-created customers should have clean data and admins expect to see real values, not placeholders.

### D3: Catch `QueryException` for duplicate entries and surface as validation errors

**Decision**: In the `catch` block of `CustomersController::store()`, detect `SQLSTATE[23000]` (integrity constraint violation) and redirect back with a descriptive validation error instead of the generic toast.

**Rationale**: The validation layer checks uniqueness with custom closures, but race conditions or edge cases can still cause DB-level duplicates. Surfacing these as form errors provides actionable feedback to the user.

## Risks / Trade-offs

- **[Risk] Existing POS customers with empty `contact_name`** → These won't be retroactively fixed. Only new POS customers will have `contact_name` populated. This is acceptable since "Nama Pelanggan" was already blank for these records.
- **[Risk] NULL vs empty string semantics** → Code that checks `$customer->customer_email === ''` will break for new records. Mitigation: use `empty()` checks which handle both NULL and empty string.
