# Sales Fixing Tickets (Sales + POS)

## Theme
- Stabilize sales editing, payment integrity, POS serial tracking, bundle aggregation, and status/reference consistency.

## Alignment with existing system (required)
- **Source of truth:** standard (non-POS) sales use Livewire flows in `app/Livewire/Sale/*`.
- **Tenant isolation:** keep `setting_id` checks (e.g., `ensureSaleBelongsToCurrentSetting`) and POS location ownership rules.
- **Permissions:** continue to use existing Gate permissions (`sales.*`, `salePayments.*`, `pos.*`).
- **Serial tracking authority:** `dispatch_details.serial_numbers` is authoritative.
- **Bundles:** store expanded bundle quantities (base * parent qty).
- **Edits with payments:** block edits when any payment exists (in addition to approved/dispatch rules).
- **No Sales Return scope:** do not touch Sales Return models, controllers, or reports.

## Dependencies
- Existing sales models: `Sale`, `SaleDetails`, `Dispatch`, `DispatchDetail`, `SalePayment`.
- POS models/services: `PosReceipt`, `PosSessionManager`, `PosLocationResolver`.
- Product inventory models: `Product`, `ProductStock`, `ProductSerialNumber`, `Transaction`.

## Standard DoD Requirements (applies to ALL tickets)
1) **Tests:** add/adjust PHPUnit tests for touched flows when feasible.
2) **Run tests:** `php artisan test` (or `vendor/bin/phpunit`) passes.
3) **No Sales Return changes:** verify no edits in Sales Return code paths.
4) **Data integrity:** any data migration/backfill includes a rollback plan.

---

## EPIC SF-0: Sale Edit + Payment Integrity

Goal: prevent sales data corruption when editing or changing payments.

### SF0-BE-01 - Fix SaleController update product_id + tax_id

Scope:
- `Modules/Sale/Http/Controllers/SaleController.php`
- In `update()`, use `product_id` from cart options (not cart row id).
- Ensure `tax_id` is persisted when re-creating `SaleDetails`.
- Verify cart rebuild in `edit()` still provides correct `options->product_id`.

Test Scenarios:
- Update a sale via SaleController path and confirm `sale_details.product_id` remains a valid product id.
- Update a sale line with tax and confirm `tax_id` is preserved after update.

DoD:
- `sale_details.product_id` is always a valid product id after update.
- `tax_id` is not lost during update.
- If any existing tests break, fix them as part of this ticket.

### SF0-BE-02 - Block edits when payments exist (Livewire flow)

Scope:
- `app/Livewire/Sale/EditForm.php`
- Block edit if the sale has any payments (`salePayments` count > 0 or paid_amount > 0).
- Preserve existing "approved/dispatch" edit blocks.
- Provide clear user message (toast/flash).

Test Scenarios:
- Attempt to edit a sale with `paid_amount > 0` and confirm edit is blocked with a clear message.
- Attempt to edit a sale with no payments and confirm edit is allowed.
- Approved/dispatched sales remain blocked as before.

DoD:
- Editing is denied for paid/partially paid sales.
- Approved/dispatch rules remain intact.
- If any existing tests break, fix them as part of this ticket.

### SF0-BE-03 - Recalculate balances when deleting payments

Scope:
- `Modules/Sale/Http/Controllers/SalePaymentsController.php`
- In `destroy()`, recalc `paid_amount`, `due_amount`, and `payment_status` after delete.
- (Optional) Centralize balance recalculation to a helper/service to reuse in create/update/destroy.

Test Scenarios:
- Delete a payment from a partially paid sale and confirm `paid_amount` and `due_amount` are updated.
- Delete the last payment and confirm `payment_status` becomes `Unpaid`.

DoD:
- Deleting a payment updates sale balances correctly.
- If any existing tests break, fix them as part of this ticket.

### SF0-BE-04 - Validate paid_amount against new total_amount

Scope:
- `Modules/Sale/Http/Requests/UpdateSaleRequest.php`
- Validate `paid_amount` against incoming `total_amount`, not the old value.
- Ensure validation still respects tenant scoping on reference.

Test Scenarios:
- Update with `paid_amount <= total_amount` passes validation.
- Update with `paid_amount > total_amount` fails validation.

DoD:
- Edit validation matches the new total.
- If any existing tests break, fix them as part of this ticket.

---

## EPIC SF-1: POS Serial Tracking Consistency

Goal: make serials reliably searchable and correctly linked to dispatch details.

### SF1-BE-01 - Persist serials into dispatch_details.serial_numbers (authoritative)

Scope:
- `Modules/Sale/Http/Controllers/PosController.php`
- Ensure dispatch creation stores JSON serial numbers in `dispatch_details.serial_numbers`.
- Remove reliance on `sale_details.serial_numbers` in POS flow.
- Make sure serial arrays are normalized (ids + serial strings) before persistence.

Test Scenarios:
- Complete a POS sale with serials and confirm `dispatch_details.serial_numbers` is populated.
- Search by serial number and confirm the sale is returned.

DoD:
- Dispatch records contain serial numbers for POS sales.
- Serial search finds POS sales via dispatch details.
- If any existing tests break, fix them as part of this ticket.

### SF1-BE-02 - Correct ProductSerialNumber.dispatch_detail_id assignment

Scope:
- `Modules/Sale/Http/Controllers/PosController.php`
- Update serial numbers only after `DispatchDetail` exists.
- Set `ProductSerialNumber.dispatch_detail_id` to the actual `dispatch_details.id`.

Test Scenarios:
- After POS sale dispatch, confirm each serial’s `dispatch_detail_id` points to a valid `dispatch_details.id`.

DoD:
- Serial numbers point to valid dispatch details for POS sales.
- If any existing tests break, fix them as part of this ticket.

---

## EPIC SF-2: Bundles + Duplicate Prevention

Goal: correct bundle quantities and prevent incorrect aggregation merges.

### SF2-BE-01 - Composite key for duplicate prevention (product_id + tax_id + bundle_id)

Scope:
- `Modules/Sale/Services/SaleCartAggregator.php`
- Use composite key `(product_id, tax_id, bundle_id)` for aggregation.
- Ensure bundle_id is derived consistently from cart options.

Test Scenarios:
- Add two cart lines with same product/tax but different bundle_id and confirm they do not merge.
- Add two cart lines with same product/tax/bundle_id and confirm they merge.

DoD:
- Lines with same product/tax but different bundle_id are not merged.
- If any existing tests break, fix them as part of this ticket.

### SF2-BE-02 - Expand bundle quantities (base * parent qty)

Scope:
- `Modules/Sale/Services/SaleCartAggregator.php`
- `app/Livewire/Sale/CreateForm.php`
- `app/Livewire/Sale/EditForm.php`
- `Modules/Sale/Http/Controllers/SaleController.php`
- Multiply bundle quantities by parent quantity during aggregation.
- Ensure `SaleBundleItem.quantity` reflects expanded qty.

Test Scenarios:
- Parent qty = 2, bundle base qty = 1 → stored bundle qty = 2.
- Bundle sub_total reflects expanded quantity.

DoD:
- Bundle quantities match actual dispatched quantity expectations.
- If any existing tests break, fix them as part of this ticket.

### SF2-BE-03 - Dispatch aggregation uses composite key

Scope:
- `Modules/Sale/Http/Controllers/SaleController.php`
- Update dispatch aggregation key to include `bundle_id` when relevant.
- Ensure bundled items are grouped correctly for dispatch UI and validation.

Test Scenarios:
- Dispatch view shows separate lines for same product with different bundle_id.
- Dispatched quantities are correct for mixed bundle/tax combinations.

DoD:
- Dispatch UI totals are correct for mixed bundles/tax combinations.
- If any existing tests break, fix them as part of this ticket.

---

## EPIC SF-3: Status + Reference Hygiene

Goal: consistent status handling and clear reference behavior.

### SF3-BE-01 - Normalize status checks to constants

Scope:
- `Modules/Sale/Entities/Sale.php`
- `Modules/Sale/Http/Controllers/SaleController.php`
- `Modules/Sale/Http/Controllers/PosController.php`
- Replace string literals (`Shipped`, `Completed`) with `Sale::STATUS_*` constants.
- If legacy data exists, add a one-time data migration plan.

Test Scenarios:
- Status-based checks use constants and still allow expected transitions.
- Legacy status handling follows the documented migration plan (if applicable).

DoD:
- All status checks use constants.
- No logic depends on legacy strings without a migration plan.
- If any existing tests break, fix them as part of this ticket.

### SF3-BE-02 - Reference input vs auto-generation

Scope:
- `Modules/Sale/Entities/Sale.php`
- `Modules/Sale/Http/Requests/StoreSaleRequest.php`
- Either: respect provided reference (skip auto-gen if set), or remove reference requirement from validation/UI.

Test Scenarios:
- Create a sale without a reference and confirm auto-generated reference is used.
- If user-provided reference is supported, confirm it is preserved and validated.

DoD:
- Reference behavior is consistent and not confusing to users.
- If any existing tests break, fix them as part of this ticket.

---

## EPIC SF-4: Stock Validation + Consolidation (optional)

Goal: improve stock safety and reduce duplicated logic.

### SF4-BE-01 - Validate stock availability on standard sales create

Scope:
- `Modules/Sale/Http/Controllers/SaleController.php`
- `app/Livewire/Sale/CreateForm.php`
- Add quantity validation (at least global stock) before creating a sale.

Test Scenarios:
- Attempt to create a sale with qty > available stock → validation error.
- Create a sale with qty <= stock → succeeds.

DoD:
- Sales cannot be created when stock is insufficient.
- If any existing tests break, fix them as part of this ticket.

### SF4-BE-02 - Consolidate sale create/update logic

Scope:
- Extract shared logic into a service (used by Livewire).
- Avoid duplicate implementations across controller + Livewire.

Test Scenarios:
- Create sale via Livewire uses shared service and produces correct totals/details.
- Update sale via Livewire uses shared service and preserves expected behavior.

DoD:
- One canonical service path for standard sales create/update.
- If any existing tests break, fix them as part of this ticket.
