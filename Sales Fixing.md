# Sales Fixing
Date: 2026-01-31
Scope: Sales + POS only (no Sales Return flows)

## High-risk bugs
1) Sale update writes the wrong product_id in SaleDetails (Modules/Sale/Http/Controllers/SaleController.php)
   - In edit(), cart items are rebuilt with a UUID as the cart item `id`, while the real product_id is stored in options.
   - In update(), SaleDetails::create uses `$cart_item->id` as product_id.
   - Impact: sale_details.product_id becomes a UUID/string, breaking product relations and future stock/dispatch logic.
   - Fix: use `$cart_item->options->product_id` (and also persist tax_id if needed) when re-creating SaleDetails.

2) Editing a sale via Livewire ignores existing payments (app/Livewire/Sale/EditForm.php)
   - update() sets due_amount = grandTotal and never preserves paid_amount/payment_status.
   - Impact: paid sales revert to "unpaid" and due_amount becomes incorrect.
   - Fix: recompute due based on current paid_amount (or re-sum salePayments), and update payment_status accordingly.

3) Deleting a sale payment does not restore sale balances (Modules/Sale/Http/Controllers/SalePaymentsController.php)
   - destroy() deletes the payment but does not adjust paid_amount / due_amount / payment_status on the Sale.
   - Impact: sales show paid even after payment deletion.
   - Fix: recalc totals on delete (and on edit/update) or use model events to keep balances consistent.

4) POS "store as quotation" references an undefined $posSession (Modules/Sale/Http/Controllers/PosController.php)
   - storeAsQuotation() uses `$posSession->id` but never defines $posSession.
   - Impact: runtime error on quotation flow.
   - Fix: mirror the POS store() flow (resolve session via PosSessionManager).

5) POS serial-number tracking is inconsistent with current schema (Modules/Sale/Http/Controllers/PosController.php + Modules/Sale/Entities/SaleDetails.php + Modules/Sale/Services/SerialNumberSearchService.php)
   - The code checks for a `sale_details.serial_numbers` column, but the schema uses `serial_number_ids`.
   - POS dispatch creation reads serials from `$saleDetail->serial_numbers` which will be null.
   - markSerialNumbersAsSold() writes ProductSerialNumber.dispatch_detail_id = sale_detail_id, not dispatch_detail_id.
   - Impact: serial numbers from POS sales are not searchable and may be linked to the wrong entity.
   - Fix: standardize on `sale_details.serial_number_ids` and ensure dispatch_details.serial_numbers is populated; update ProductSerialNumber.dispatch_detail_id with the actual DispatchDetail id.

6) Bundle quantities are saved as base quantity only (Modules/Sale/Services/SaleCartAggregator.php + app/Livewire/Sale/CreateForm.php + app/Livewire/Sale/EditForm.php + Modules/Sale/Http/Controllers/SaleController.php)
   - Aggregation does not multiply bundle item quantity by parent cart qty.
   - SaleBundleItem::quantity is stored as base quantity even when parent qty > 1.
   - Impact: dispatch totals and stock expectations for bundle items are undercounted.
   - Fix: multiply bundle item quantities (and sub_total) by parent qty at aggregation time, or store both base_qty and total_qty.
   - Also update duplicate-prevention to use composite key: product_id + tax_id + bundle_id.

## Medium-risk / data consistency
7) UpdateSaleRequest validates paid_amount against the old total_amount (Modules/Sale/Http/Requests/UpdateSaleRequest.php)
   - max uses `$this->sale->total_amount`, not the new total_amount from request.
   - Impact: valid edits are rejected or overpayments are allowed after total changes.
   - Fix: validate against incoming total_amount (or compute after validation).

8) Status strings are inconsistent across the codebase (Modules/Sale/Entities/Sale.php + Modules/Sale/Http/Controllers/SaleController.php + POS flow)
   - Constants are uppercase (e.g., DISPATCHED), but code checks "Shipped"/"Completed".
   - Impact: inventory adjustments tied to status checks might never run.
   - Fix: unify on constants; migrate legacy statuses if they still exist in data.

9) Sale reference is always auto-generated, but user input is still required (Modules/Sale/Entities/Sale.php + Modules/Sale/Http/Requests/StoreSaleRequest.php)
   - Sale::boot() overwrites reference even if provided.
   - StoreSaleRequest requires reference input, but controller/store flows ignore it.
   - Impact: confusing UX and possible duplicate validation failures.
   - Fix: either respect provided reference (skip auto-gen when set) or remove reference from request validation/UI.

## Improvements / cleanup
10) Stock validation in standard (non-POS) sales only checks product existence, not availability (Modules/Sale/Http/Controllers/SaleController.php)
   - Consider validating requested quantity against stock before creating a sale.

11) Duplicate create/update logic exists in SaleController vs Livewire forms
   - Risk of divergent behavior and inconsistent totals.
   - Suggest consolidating into a shared service or use Livewire only.

12) Dispatch validation for serial-number products does not confirm serial location ownership
   - You accept a location per serial from the request but do not verify it matches ProductSerialNumber.location_id.
   - Suggest validating location_id and flags (is_broken, is_in_return_process) before dispatch.

13) Duplicate-prevention key should be composite (product_id + tax_id + bundle_id)
   - Current aggregation keys omit bundle_id, which can merge distinct lines incorrectly.
   - Apply the composite key consistently in cart aggregation, sale detail grouping, and dispatch grouping.

## Decisions (answers)
Q1) Which flow is the source of truth for standard (non-POS) sales?
   Answer: A) Livewire forms (app/Livewire/Sale/*)

Q2) For bundle items, how should quantity be stored?
   Answer: B) Expanded quantity (base * parent qty)

Q3) For serial tracking, which storage should be authoritative?
   Answer: B) dispatch_details.serial_numbers

Q4) Editing a sale with existing payments should:
   Answer: B) Block edits if any payment exists. Also block edits if sales already approved.
