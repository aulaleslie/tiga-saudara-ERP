# Phase 0 - Purchase Return Codebase Discovery

## Scope
This document maps the current implementation for:
- Purchase return document flow
- Settlement and payment recalculation
- Serial-number inventory lifecycle
- Accounting totals, persistence, and reporting touchpoints

Codebase snapshot basis: current repository state on this branch.

## 1) Project Overview (Tech Stack, Architecture Style)
- Backend: Laravel 10, PHP 8.1 (`composer.json`).
- Architecture: modular monolith via `nwidart/laravel-modules` (`Modules/*`) plus shared app layer (`app/*`).
- UI: Blade + Livewire 3 (`app/Livewire/*`, `resources/views/livewire/*`).
- Authorization: Spatie Permission + `Gate` checks.
- Data tables/reporting: Yajra DataTables + Livewire report screens.
- Audit-related infra:
  - Archiving via `archived_at` / `archived_by` and `Archivable` trait.
  - Serial history ledger in `serial_number_histories`.

## 2) Key Directories and Domain Boundaries

### Purchase Return Domain
- Routes: `Modules/PurchasesReturn/Routes/web.php`
- Controllers:
  - `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnController.php`
  - `Modules/PurchasesReturn/Http/Controllers/PurchaseReturnApprovalController.php`
  - `Modules/PurchasesReturn/Http/Controllers/PurchaseReturnDispatchController.php`
  - `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
  - `Modules/PurchasesReturn/Http/Controllers/PurchaseReturnPaymentsController.php`
- Entities:
  - `Modules/PurchasesReturn/Entities/PurchaseReturn.php`
  - `Modules/PurchasesReturn/Entities/PurchaseReturnDetail.php`
  - `Modules/PurchasesReturn/Entities/PurchaseReturnPayment.php`
  - `Modules/PurchasesReturn/Entities/PurchaseReturnSettlement.php`
  - `Modules/PurchasesReturn/Entities/PurchaseReturnItemSettlement.php`
  - `Modules/PurchasesReturn/Entities/SupplierCredit.php`
  - `Modules/PurchasesReturn/Entities/PurchasePaymentCreditApplication.php`
  - `Modules/PurchasesReturn/Entities/PurchaseReturnGood.php`

### Purchase + Payment Domain (cross-impact from settlement)
- `Modules/Purchase/Entities/Purchase.php`
- `Modules/Purchase/Entities/PurchaseDetail.php`
- `Modules/Purchase/Entities/PurchasePayment.php`
- `Modules/Purchase/Entities/ReceivedNote.php`
- `Modules/Purchase/Entities/ReceivedNoteDetail.php`

### Inventory + Serial Domain
- `Modules/Product/Entities/ProductSerialNumber.php`
- `Modules/Product/Entities/ProductStock.php`
- `Modules/Product/Entities/Transaction.php`
- `Modules/Product/Entities/SerialNumberHistory.php`
- `app/Services/SerialNumberHistoryService.php`
- Projection logic used by product UI/reporting:
  - `app/Services/ProductQuantityProjectionService.php`

### Purchase Return UI Boundary
- Livewire components:
  - `app/Livewire/PurchaseReturn/PurchaseReturnCreateForm.php`
  - `app/Livewire/PurchaseReturn/PurchaseReturnEditForm.php`
  - `app/Livewire/PurchaseReturn/PurchaseReturnTable.php`
  - `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`
  - `app/Livewire/PurchaseReturn/PurchaseOrderSerialNumberLoader.php`
  - `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php`
- Views:
  - `resources/views/livewire/purchase-return/*`
  - `Modules/PurchasesReturn/Resources/views/show.blade.php`
  - `Modules/PurchasesReturn/Resources/views/partials/settlement-items-table.blade.php`
  - `Modules/PurchasesReturn/Resources/views/payments/*`

## 3) Current Purchase Return Workflow (Code Paths, Services, UI, Events)

### A. Create / Edit Purchase Return
- Entry points:
  - `GET /purchase-returns/create` -> Livewire create form.
  - `GET /purchase-returns/{id}/edit` -> Livewire edit form.
- Main behavior:
  - Create/edit currently persists `purchase_returns` + `purchase_return_details`.
  - Livewire validator enforces per-row rules, serial uniqueness, location checks, active serial checks.
  - New-create path does not mutate stock immediately (dispatch flow handles stock lock/deduction).
- UI event flow:
  - Row composition through Livewire events (`productSelected`, `serialNumberSelected`, `locationSelected`, `updateRows`).
  - Serial rows can be explicitly removed in UI (`removeSerialNumber` in `PurchaseReturnTable`).

### B. Approval
- `POST /purchase-returns/{purchase_return}/approve`
- Controller: `PurchaseReturnApprovalController::approve`
- Checks:
  - Stock sufficiency by product/location.
  - Serial state must be `ACTIVE`, correct location, and not in another return process.
- Result:
  - `approval_status` -> approved, status -> `AWAITING_DISPATCH`.

### C. Dispatch Request / Approval
- Request: `PurchaseReturnDispatchController::requestDispatch`
- Approve dispatch: `PurchaseReturnDispatchController::approveDispatch`
- On dispatch approval:
  - `lockReturnStock()` deducts stock buckets and writes `transactions`.
  - Serials for details are set to `RETURN_IN_PROCESS`, `is_in_return_process = true`, and `purchase_return_id` set.
  - Purchase return marked dispatched (`return_dispatch_status = dispatched`, `return_dispatched_at` set).

### D. Settlement Definition and Submission
- UI: `PurchaseReturnSettlementForm` (per-line settlement methods).
- Each line maps to `purchase_return_item_settlements`:
  - method, nominal, target purchase, per-line status.
- Submission is per-line (`submitLine`) and/or batch draft save (`submit`).

### E. Settlement Approval and Effects
- `POST /purchase-returns/settlements/item/{itemSettlement}/approve`
- Controller: `PurchasesReturnSettlementController::approveItemSettlement`
- Core accounting/inventory mutation occurs in:
  - `applySettlementEffect()`

### F. Settlement Receive (repair/broken flows)
- `POST /purchase-returns/settlements/item/{itemSettlement}/receive`
- Controller: `receiveItemSettlement`
- Handles:
  - product repair receive (reactivate same serial or replacement serial logic),
  - broken stock receive (mark serial broken + stock bucket movements),
  - serial history events.

## 4) Current Settlement and Payment Recalculation Logic

Primary mutation function:
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php::applySettlementEffect`

### Method: `MODIFY_PURCHASE`
- Reduces purchase detail quantities/amounts, updates received-note detail quantities.
- Recalculates purchase totals via `recalculatePurchaseTotals`.
- For serial lines, marks serial to `RETURNED`, clears receive linkage, records serial history.
- If all purchase detail qty becomes zero, archives purchase (`archived_at`, `archived_by`).
- **Payment recalculation branch (destructive today):**
  - If surplus condition is met, runs `$purchase->purchasePayments()->delete()`.
  - Resets paid/due/payment_status and optionally reallocates surplus to another purchase by creating a new `PurchasePayment`.
- Records/updates one `PurchaseReturnPayment` row (using latest row increment pattern).
- Updates purchase-return paid/due/payment_status.

### Method: `CREDIT`
- Creates/increments `supplier_credits`.
- If target purchase has status paid/partial:
  - **Deletes all target purchase payments** (`$purchase->purchasePayments()->delete()`), resets purchase unpaid.
- Applies credit by creating `PurchasePayment` + linkage `purchase_payment_credit_applications`.
- Decrements `supplier_credits.remaining_amount` and closes when exhausted.
- Records/updates purchase-return payment and rollups.

### Method: `CASH`
- If target purchase selected, reduces purchase details and recalculates totals.
- If target purchase paid/partial:
  - **Deletes all target purchase payments** (`$purchase->purchasePayments()->delete()`), resets purchase unpaid.
- Records/updates purchase-return payment and rollups.

### Other methods
- `PRODUCT_REPAIR` and `BROKEN_STOCK` do not apply immediate financial effect at approval.

## 5) Payment Lifecycle Model Today (Create -> Apply -> Remove/Delete)

### Purchase Return Payment (`purchase_return_payments`)
1. Create:
   - Manual: `PurchaseReturnPaymentsController::store`.
   - Automatic: settlement approvals in `applySettlementEffect`.
2. Apply:
   - Header rollups (`purchase_returns.paid_amount`, `due_amount`, `payment_status`) updated in multiple flows.
3. Update:
   - `PurchaseReturnPaymentsController::update` recalculates header values.
4. Remove:
   - Manual delete is hard delete (`PurchaseReturnPaymentsController::destroy` -> `$purchaseReturnPayment->delete()`).

### Purchase Payment (`purchase_payments`) impacted by settlement
1. Existing payments can be removed by settlement approval logic.
2. `MODIFY_PURCHASE`, `CREDIT`, and `CASH` branches can hard-delete rows automatically.
3. New payments may be re-created for target allocations/credit applications.

### Important current characteristic
- No explicit "invalidate" state/column for payments exists today.
- Exclusion from settlement totals is currently achieved by deleting rows, not by invalidation flags.

## 6) Serial-Number Inventory Lifecycle (Active, Returned, Removed)

### States in model
- `ACTIVE`
- `RETURN_IN_PROCESS`
- `RETURNED`
- `BROKEN`

### Lifecycle observed
1. Selection for return creation:
   - Serial must be active, unsold (`dispatch_detail_id` null), and not in return process.
2. Dispatch approval:
   - Serial moves to `RETURN_IN_PROCESS`, linked to purchase return.
3. Settlement effects:
   - `MODIFY_PURCHASE` can set serial to `RETURNED`.
   - `PRODUCT_REPAIR` receive can:
     - Reactivate same serial (`ACTIVE`) or
     - Mark old serial `RETURNED` and activate/create replacement serial.
   - `BROKEN_STOCK` receive marks serial `BROKEN`.
4. History:
   - `serial_number_histories` records purchase-returned, repair-received, marked-broken events.

### "Removed" behavior today
- Physical serial record delete is not part of normal purchase return flow.
- Visibility/removal issue is at UI/query level:
  - Returned serials are excluded in shared loaders/tables (`status != RETURNED` filters).
  - Selected serial chips can be removed from create/edit UI rows.

## 7) Where Accounting Totals Are Computed and Persisted

### Purchase Return header totals
- Create/edit forms:
  - `PurchaseReturnCreateForm::validateAndPrepare`
  - `PurchaseReturnEditForm::submit`
- Payment CRUD:
  - `PurchaseReturnPaymentsController::store/update` adjusts header paid/due/status.
- Settlement:
  - `applySettlementEffect` updates header paid/due/status repeatedly by method.

### Purchase totals
- `PurchasesReturnSettlementController::recalculatePurchaseTotals`
  - Recomputes tax, discount, total, due, payment_status from purchase details + paid_amount.
- Called after detail quantity/amount reductions in settlement approval.

### Reporting consumption
- Payment and profit reports sum payment tables directly:
  - `app/Livewire/Reports/PaymentsReport.php`
  - `app/Livewire/Reports/ProfitLossReport.php`
  - `app/Http/Controllers/HomeController.php::paymentChart`
- No invalid-payment filter exists because invalidation state does not exist.

## 8) API Surface Involved (REST / WebSocket / Events)

### REST/Web routes
- Purchase return functional surface is web routes + Livewire requests.
- Module API route (`Modules/PurchasesReturn/Routes/api.php`) is effectively placeholder auth endpoint only.

### Livewire events (internal UI event bus)
- Used heavily for form/table synchronization in purchase return create/edit/settlement.

### WebSocket/eventing
- Global broadcast infra exists (`routes/channels.php`, `config/broadcasting.php`, `laravel-echo-server.json`).
- No dedicated purchase-return broadcast channel/event found for settlement or payment state transitions.

## 9) DB Schema Overview (Tables, Entities, Migrations Involved)

### Core purchase return tables
- `purchase_returns`
- `purchase_return_details`
- `purchase_return_payments`

### Settlement and credit tables
- `purchase_return_settlements`
- `purchase_return_item_settlements`
- `purchase_return_goods`
- `supplier_credits`
- `purchase_payment_credit_applications`

### Purchase/inventory tables touched by settlement
- `purchases`
- `purchase_details`
- `purchase_payments`
- `received_notes`
- `received_note_details`
- `product_serial_numbers`
- `serial_number_histories`
- `product_stocks`
- `transactions`

### Key migration groups
- Purchase return module: `Modules/PurchasesReturn/Database/Migrations/*`
  - status/approval fields, dispatch fields, settlement schema, per-item settlement approvals/receive columns, payment method FK, setting_id fields, normalization backfills.
- Product module: `Modules/Product/Database/Migrations/*`
  - serial table, serial status flags, return-process fields, history table, stock + transactions tables.
- App-level migrations:
  - archive columns (`database/migrations/2026_01_23_171055_add_archiving_columns_to_documents_tables.php`)
  - purchase return detail location and approval note augmentations.

## 10) Existing Test Strategy (Finance/Inventory Focus)

### Test structure
- Root PHPUnit suites (`phpunit.xml`) include:
  - `tests/Unit`
  - `tests/Feature`
- Module-local tests exist in `Modules/PurchasesReturn/Tests/*`.

### Important caveat
- Default `phpunit.xml` does not include `Modules/*/Tests` directories.
- This creates risk that module-specific settlement tests are not run unless explicitly targeted.

### Coverage observed (relevant)
- Finance/settlement behavior:
  - `tests/Feature/PurchaseReturnSettlementLogicTest.php`
  - `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementPhase2Test.php`
  - `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementArchivalTest.php`
- Serial lifecycle/history:
  - `tests/Feature/PurchaseReturnSerialHistoryTest.php`
  - `tests/Feature/PurchaseReturnRepairReceivedHistoryTest.php`
  - `tests/Feature/PurchaseReturnBrokenStockHistoryTest.php`
  - `tests/Feature/PurchaseReturnSerialReuseTest.php`
- Stock integrity/projection:
  - `tests/Feature/ProductQuantityProjectionTest.php`
- Create/edit/validation constraints:
  - `tests/Feature/PurchaseReturnNoMutationTest.php`
  - `tests/Feature/PurchaseReturnSerialLookupTest.php`
  - `tests/Feature/PurchaseReturnSerialUniquenessTest.php`

### Notable expectation currently encoded in tests
- Several tests explicitly assert payment deletion/reset behavior on settlement approval.
- Some tests assert returned serials are excluded from search.
- Other tests assert returned serials can be reactivated in repair replacement flows.

## 11) Notable Invariants (Current)
- Serial uniqueness is per `product_id + serial_number` (composite unique).
- Serial selected for return must be active and not already in another return process.
- Settlement nominal is capped by line max nominal in Livewire rules.
- Purchase return approval blocks insufficient stock or serial location/status mismatch.
- Purchase recalculation enforces derived `due_amount = max(total - paid, 0)` and status mapping.
- Unified purchase return status is derived from approval + dispatch + item-settlement finality.
- Archived documents are hidden by global scope unless `withArchived`/`onlyArchived` is used.

## 12) Risks, Ambiguity, and Fragility Points

### High risk (financial/audit)
1. Automatic hard-delete of `purchase_payments` in settlement approval branches (`MODIFY_PURCHASE`, `CREDIT`, `CASH`).
2. No payment invalidation model; removal from settlement math is destructive, not reversible.
3. Hard-delete on purchase return payments (`PurchaseReturnPaymentsController::destroy`) removes historical trail.

### High risk (traceability/inventory)
4. Returned serials are filtered out in shared serial loaders/tables, reducing historical visibility.
5. UI supports removing selected serials from return lines before save/edit completion.

### Medium risk (calculation consistency)
6. Mixed legacy money handling patterns (`*100` in legacy controllers/models vs decimal casts in newer code) can cause scaling errors.
7. Settlement code updates header paid/due/status in multiple branches with side effects not centralized.

### Medium risk (code correctness/maintainability)
8. `PurchaseReturnPayment::paymentMethod()` references `\App\Models\PaymentMethod` while actual model is `Modules\Setting\Entities\PaymentMethod`.
9. `PurchasesReturnSettlementController` still contains TODO endpoints (`store`, `submit`, `dispatchStock`) while routes are active.
10. Breadcrumb/view inconsistencies exist (for example payments index links to purchase show route naming mismatch), indicating UI wiring drift.

### Ambiguity surfaced by current code/tests
11. Returned-serial reuse policy is not consistently represented across test cases (some expect strict rejection; others expect reactivation behavior).
12. Module test suite execution in CI/local is ambiguous due to phpunit suite scope.
