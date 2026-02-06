# Sales Return Tasks

## Theme
- Align Sales Return workflow with Purchase Return locking/approval behavior, scanner-first serial input, and new settlement resolutions.

## Alignment with existing system (required)
- **Source of truth:** Sales Return uses Livewire flows under `app/Livewire/SalesReturn/*`.
- **Sale eligibility:** keep `SaleReturnEligibilityService::ELIGIBLE_STATUSES` for searchable sales.
- **Permissions:** continue to use existing Gate permissions (`saleReturns.*`).
- **Serial authority:** serials must belong to the selected `dispatch_detail_id` and cannot be reserved by other sale returns.
- **No post-approval edits:** approved Sale Returns are not editable.
- **Settlement timing:** settlement only after receiving (`Awaiting Settlement`).

## Dependencies
- Sales return models: `SaleReturn`, `SaleReturnDetail`, `SaleReturnPayment`, `SaleReturnGood`, `CustomerCredit`.
- Sales models: `Sale`, `DispatchDetail`.
- Inventory: `ProductSerialNumber`, `ProductStock`.
- Livewire components: `SaleReturnCreateForm`, `SaleReturnEditForm`, `SaleReturnTable`, `SaleSerialNumberLoader`.

## Standard DoD Requirements (applies to ALL tickets)
1) **Tests:** add/adjust PHPUnit tests for touched flows when feasible.
2) **Run tests:** `php artisan test` (or `vendor/bin/phpunit`) passes.
3) **Data integrity:** any data migration/backfill includes a rollback plan.

---

## EPIC SR-0: Sales Number Selection UX

Goal: replace typeahead with a dropdown that matches Purchase Return supplier dropdown UX.

### SR0-FE-01 - Sale reference searchable dropdown (reference-only)

Scope:
- Create `app/Livewire/SalesReturn/SaleReferenceSearchDropdown.php`.
- Create `resources/views/livewire/sales-return/sale-reference-search-dropdown.blade.php`.
- Replace `SaleReferenceLoader` usage in `resources/views/livewire/sales-return/sale-return-create-form.blade.php` (and edit form if used).
- Search by sale reference only; still filter by `SaleReturnEligibilityService::ELIGIBLE_STATUSES`.
- Emit `saleReferenceSelected` payload identical to `SaleReferenceLoader`.

Test Scenarios:
- Search by partial reference shows eligible sales only.
- Selecting a sale emits payload and hydrates rows in `SaleReturnCreateForm`.
- Clearing selection resets sale and rows.

DoD:
- Dropdown UX matches supplier dropdown pattern.
- `SaleReturnCreateForm::handleSaleSelected` works unchanged.
- No regressions in sale return creation.

---

## EPIC SR-1: Scanner-Friendly Serial Input

Goal: allow barcode scanner enter-to-add for serials, with strict dispatch validation.

### SR1-BE-01 - Add scanner-style serial input (reject wrong dispatch)

Scope:
- Update `app/Livewire/SalesReturn/SaleSerialNumberLoader.php`:
  - Add `addSerial()` similar to purchase return loader.
  - Validate serial belongs to selected `dispatch_detail_id`.
  - Reject duplicates within row and serials reserved by other sale returns.
- Update `resources/views/livewire/sales-return/sale-serial-number-loader.blade.php`:
  - Single input with `wire:keydown.enter.prevent="addSerial"`.
  - Auto-clear and refocus on success; keep focus/select on error.

Test Scenarios:
- Scan valid serial: serial added, quantity increments, input clears.
- Scan serial from a different dispatch detail: rejected with error.
- Scan duplicate serial: rejected.
- Scan reserved serial (other sale return): rejected.

DoD:
- Serial scanning is scanner-friendly and strict to dispatch detail.
- Quantity remains computed from serial count.

---

## EPIC SR-2: Quantity Rules & Locking

Goal: ensure quantities are optional per line, but total return must be > 0; lock after approval.

### SR2-BE-01 - Quantity validation (at least one qty > 0)

Scope:
- `app/Livewire/SalesReturn/Concerns/ValidatesSaleReturnForm.php`
  - Change `rows.*.quantity` to `nullable|integer|min:0`.
- `app/Livewire/SalesReturn/SaleReturnCreateForm.php`
  - Keep aggregate check for at least one row with `quantity > 0`.

Test Scenarios:
- All quantities 0 → validation error.
- One quantity > 0 → pass.
- Non-serial quantity cannot exceed available quantity (UI clamp + validation check).

DoD:
- Form rejects empty-return submissions.
- Serial rows still enforce serial count = quantity.

### SR2-FE-02 - Lock inputs after approval

Scope:
- `resources/views/livewire/sales-return/sale-return-table.blade.php`
  - Disable quantity inputs and remove buttons when `approvalLocked`.
- `app/Livewire/SalesReturn/SaleReturnTable.php`
  - Respect a read-only flag to prevent updates/removals.
- `app/Livewire/SalesReturn/SaleReturnEditForm.php`
  - Block edits after approval (already blocked in UI, enforce server-side).

Test Scenarios:
- Approved sale return: cannot edit quantities or remove rows.
- Approved sale return: cannot edit via direct request (server-side guard).

DoD:
- Approved sale returns are immutable.

---

## EPIC SR-3: Receiving + Settlement Gating

Goal: settlement only after receiving; UI and controller enforce the sequence.

### SR3-BE-01 - Settlement access only after receiving

Scope:
- `Modules/SalesReturn/Http/Controllers/SalesReturnController.php`
  - In `settlement()`, require `status = 'Awaiting Settlement'`.
- `Modules/SalesReturn/Resources/views/show.blade.php`
  - Show Settlement button only after receiving.

Test Scenarios:
- Approved but not received → settlement blocked with message.
- After receive → settlement link available and allowed.

DoD:
- Settlement is gated strictly by receiving status.

---

## EPIC SR-4: Settlement Resolutions (New Types)

Goal: replace cash/replacement/credit with Kembali Tunai, Perbaikan, Tidak Dapat Diproses.

### SR4-BE-01 - Update settlement options + persistence

Scope:
- `app/Livewire/SalesReturn/SaleReturnSettlementForm.php`
  - Replace `return_type` options with: `cash_refund`, `repair`, `unprocessed`.
  - **cash_refund:** create `SaleReturnPayment` and require cash proof.
  - **repair:** header-level resolution only (no item tracking).
  - **unprocessed:** mark completed without additional stock changes.
- `resources/views/livewire/sales-return/sale-return-settlement-form.blade.php`
  - Update labels and descriptions.

Test Scenarios:
- Select cash_refund without proof → validation error.
- cash_refund creates payment, marks completed.
- repair completes without creating goods/credit.
- unprocessed completes without creating goods/credit/payment.

DoD:
- Settlement saves new resolution types correctly.
- Validation and UI reflect new options.

### SR4-FE-02 - Status/summary labels

Scope:
- `Modules/SalesReturn/Resources/views/partials/settlement-status.blade.php`
- `Modules/SalesReturn/Resources/views/show.blade.php`
  - Update method labels and display mapping for new resolution names.

Test Scenarios:
- Completed returns show correct resolution label.

DoD:
- UI shows consistent resolution labels.

---

## EPIC SR-5: Data Migration + Reporting

Goal: map legacy return_type values and keep reports consistent.

### SR5-BE-01 - Migrate old return_type values

Scope:
- Add migration or one-time script:
  - `cash` -> `cash_refund`
  - `replacement` -> `repair`
  - `credit` -> `unprocessed`
- Include rollback plan (reverse mapping).

Test Scenarios:
- Existing sale returns are mapped correctly after migration.

DoD:
- Legacy data mapped to new values without loss.
- Rollback strategy documented.

### SR5-BE-02 - Reporting alignment

Scope:
- Reports or dashboards referencing sale return `return_type` or `payment_method`.
- Update labels to match new resolutions.

Test Scenarios:
- Sales return report displays new resolution labels.

DoD:
- Reports show consistent labels after change.
