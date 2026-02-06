# Sales Return Plan

## Goal
Align Sales Return behavior with Purchase Return approval/locking and settlement flow, while keeping the Sales Return UX centered on sales number selection, scanned serial input, and controlled quantities.

## Current State (Observed)
- Sales Return uses `SaleReferenceLoader` (typeahead) for sale reference selection and hydrates rows via `SaleReturnEligibilityService`. (`app/Livewire/AutoComplete/SaleReferenceLoader.php`, `app/Livewire/SalesReturn/SaleReturnCreateForm.php`)
- Rows come from dispatch details, with `available_quantity` = dispatched - returned. (`app/Support/SalesReturn/SaleReturnEligibilityService.php`)
- Serial-required rows use a dropdown-based serial search (`SaleSerialNumberLoader`). Quantity is read-only and computed from selected serials in `SaleReturnTable`. (`app/Livewire/SalesReturn/SaleReturnTable.php`, `resources/views/livewire/sales-return/sale-return-table.blade.php`)
- Approval sets status to `Awaiting Receiving`, receive action updates stock and then moves to `Awaiting Settlement`. (`Modules/SalesReturn/Http/Controllers/SalesReturnController.php`)
- Settlement is a single header-level `return_type` with options cash/replacement/credit. (`app/Livewire/SalesReturn/SaleReturnSettlementForm.php`, `resources/views/livewire/sales-return/sale-return-settlement-form.blade.php`)
- Sales Return edit is only available while approval is pending; approved records are not editable in the UI.

## Target Workflow (Requested)
1) User selects a sales number via a searchable Livewire dropdown (same UX pattern as supplier dropdown in Purchase Return).
2) System loads returnable items from the selected sale.
3) Non-serial items: quantity input editable, cannot exceed sold quantity (available).
4) Serial items: quantity is read-only and computed from scanned serial inputs (scanner-friendly enter-to-add).
5) After creation, approval follows Purchase Return behavior.
   - On approved: sales number read-only; all quantities read-only.
   - Quantities are optional per line, but at least one line must have quantity > 0.
6) After approved, user can receive items.
7) After receiving, user performs settlement with resolutions: 
   - Kembali Tunai
   - Perbaikan
   - Tidak Dapat Diproses

## Proposed Changes (By Area)

### 1) Sales Number Search Dropdown (UX match to supplier dropdown)
- Create a new Livewire component patterned after `Modules/People/Livewire/SupplierSearchDropdown.php` and its view.
- Suggested location:
  - `app/Livewire/SalesReturn/SaleReferenceSearchDropdown.php`
  - `resources/views/livewire/sales-return/sale-reference-search-dropdown.blade.php`
- Behavior:
  - Click-to-open dropdown with search input.
  - Server-side search by sale reference only. (Answer 1)
  - Only show sales with statuses in `SaleReturnEligibilityService::ELIGIBLE_STATUSES`.
  - Emit `saleReferenceSelected` with the same payload shape as `SaleReferenceLoader` so `SaleReturnCreateForm::handleSaleSelected` remains unchanged.
- Replace usage in `resources/views/livewire/sales-return/sale-return-create-form.blade.php`.

### 2) Serial Number Input (scanner-friendly)
- Replace/extend `SaleSerialNumberLoader` UI to match `PurchaseOrderSerialNumberLoader` behavior:
  - Single text input with `wire:keydown.enter.prevent="addSerial"`.
  - On add: validate serial belongs to the dispatch detail, is not reserved in another sale return, and is not duplicated in current row. (Answer 8)
  - Emit `serialNumberSelected` with the same payload currently expected by `SaleReturnTable`.
  - Clear input and re-focus on success, keep focus/select on errors.
- Suggested approach:
  - Update `app/Livewire/SalesReturn/SaleSerialNumberLoader.php` to add `addSerial()` like purchase return.
  - Update view `resources/views/livewire/sales-return/sale-serial-number-loader.blade.php` to match scanner workflow (see purchase return loader view).
- Keep `SaleReturnTable` logic that recomputes quantity from serial count.

### 3) Quantity Rules
- Keep clamping on UI to `available_quantity` for non-serial items.
- Update validation so per-row quantity is optional, but the form requires at least one item with quantity > 0:
  - In `ValidatesSaleReturnForm`, change `rows.*.quantity` to `nullable|integer|min:0`.
  - Retain the existing aggregate check in `SaleReturnCreateForm::validateAndPrepare` (at least one row with quantity > 0).
- Ensure serial rows always use computed quantity.

### 4) Approval and Locking Behavior
- Align with Purchase Return behavior:
  - Do not allow edit after approval. (Answer 2)
  - Still enforce read-only values and block changes server-side if any edit endpoints are reached.
- Implementation details:
  - Pass `approvalLocked` or `isReadOnly` into `SaleReturnTable` and `SaleSerialNumberLoader` to disable inputs and remove actions.
  - Disable remove-row and serial remove buttons when locked.
  - In `SaleReturnCreateForm`/`SaleReturnEditForm`, ignore updates when locked (or validate that locked fields did not change).

### 5) Receiving Flow
- Keep existing receive controller, but change UI gating so settlement is only available after receiving. (Answer 3)
  - `SalesReturnController::settlement` should require `status = 'Awaiting Settlement'`.
  - `salesreturn::show` should only show the settlement button after receiving.

### 6) Settlement Resolution (new methods)
- Replace sale return settlement types with:
  - `cash_refund` (Kembali Tunai)
  - `repair` (Perbaikan)
  - `unprocessed` (Tidak Dapat Diproses)
- Update:
  - `SaleReturnSettlementForm` rules, UI labels, and persistence logic.
  - `salesreturn::partials/settlement-status.blade.php` mapping and labels.
  - `salesreturn::show` document summary (method labels).
- Data implications:
  - Kembali Tunai => create `SaleReturnPayment` and require cash proof upload. (Answer 4)
  - Perbaikan => header-level resolution only (no per-item tracking). (Answer 5)
  - Tidak Dapat Diproses => mark completed without additional stock changes (keep received stock). (Answer 6)
  - Map existing `return_type` values to new ones during migration. (Answer 7)

### 7) Status and Reporting
- Update any reports or dashboards that summarize sales return settlement methods or statuses.
- Update `sale_return` payment_method labels to match new resolution set.

### 8) Tests
Add or update tests for:
- Sale reference dropdown search and selection payload.
- Serial scanning input adds, duplicates, and reserved serial blocking; reject serials not tied to the selected dispatch detail. (Answer 8)
- Validation: at least one quantity > 0; quantity <= available for non-serial; serial count matches quantity.
- Approval lock behavior (fields read-only and server-side guarded).
- Settlement method options and persistence.
- Settlement available only after receiving.

## Suggested Implementation Steps
1) Build new sale number dropdown component (UI + backend search).
2) Replace sale number input in sales return create/edit view.
3) Swap serial loader to scanner-friendly input and update validation.
4) Add approval read-only behavior in table and server-side guards.
5) Gate settlement after receiving; update controller and view.
6) Replace settlement resolution types and update related UI/reporting.
7) Add/adjust tests.

## Decisions (from your answers)
1) Sale number search: reference only.
2) Editing after approval: not allowed.
3) Settlement timing: only after receiving.
4) Kembali Tunai: requires cash proof and creates `SaleReturnPayment`.
5) Perbaikan: header-level resolution only.
6) Tidak Dapat Diproses: complete without additional stock changes.
7) Migration: map cash -> Kembali Tunai, replacement -> Perbaikan, credit -> Tidak Dapat Diproses.
8) Serial scan: reject if serial not tied to the selected dispatch detail.
