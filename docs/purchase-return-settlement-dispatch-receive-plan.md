# Plan: Purchase Return Settlement Approval + Replacement Dispatch/Receive

## Context
The purchase return flow already has document approval, but settlement selection executes immediately and there is no dispatch/receive lifecycle for replacement goods. The current settlement implementation (in `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`) creates payments/credits/replacement goods and marks the return `Completed` on submit, which prevents a review/approval step and skips operational steps like dispatch/receive for exchanges.

## Goals
1. Add a settlement approval workflow so settlement details are locked and reviewed before execution.
2. Introduce dispatch + receive steps for exchange settlements to track outbound return and inbound replacement goods.
3. Provide a dedicated settlement list/menu for approvals and execution.
4. Fix serial number tracking to support return lifecycle, repaired vs replaced scenarios, and uniqueness per product globally.

## Current Behavior (Observed)
- Purchase return creation uses Livewire (`app/Livewire/PurchaseReturn/PurchaseReturnCreateForm.php`) and stores details + serial IDs.
- Approval uses `PurchasesReturnController@approve` and moves status to `Awaiting Settlement`.
- Settlement selection (`app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`) immediately:
  - Creates `PurchaseReturnGood` (exchange), `SupplierCredit` (deposit), or `PurchaseReturnPayment` (cash).
  - Updates `product_serial_numbers` for exchange.
  - Updates `purchase_returns` to `Completed`, sets `return_type`, `payment_status`, `paid_amount`, and `settled_at`.
- No dispatch/receive steps exist for exchange; replacement goods are treated as if received instantly.
- Settlement access is gated by `purchaseReturns.edit`, and no settlement list exists.

## Proposed Workflow

### A. Document approval (unchanged)
`Pending Approval` -> `Approved` / `Rejected`

### B. Settlement lifecycle (new)
1. Draft settlement (select method + planned details).
2. Settlement submitted for approval.
3. Settlement approved (locked).
4. Execution:
   - Cash: record receipt + proof.
   - Deposit: create supplier credit.
   - Exchange: dispatch returned goods, then receive replacements.
5. Completed.

### C. Status mapping (recommended)
Keep `purchase_returns.approval_status` as document approval, and add a separate settlement status:
- `settlement_status`: `draft`, `pending`, `approved`, `executing`, `completed`, `rejected`
This avoids overloading `purchase_returns.status` while still showing a user-facing status badge.

## Data Model Changes

### Settlement header
Add `purchase_return_settlements` (preferred) OR add columns to `purchase_returns`.
Suggested fields:
- `purchase_return_id`
- `method` (`cash`, `deposit`, `exchange`)
- `status` (`draft`, `pending`, `approved`, `executing`, `completed`, `rejected`)
- `submitted_by`, `submitted_at`
- `approved_by`, `approved_at`
- `rejected_by`, `rejected_at`, `rejection_reason`
- `cash_proof_path` (stored at execution for cash/deposit)

### Exchange goods
Reuse `purchase_return_goods` with explicit execution fields:
- Keep `quantity`, `unit_value`, `sub_total` as the planned replacement.
- Add `received_by` + `received_quantity` if partial receipts are required.
- Use existing `received_at` + `serial_number` to mark actual receipt.

### Dispatch tracking
Add outbound dispatch fields on `purchase_returns`:
- `return_dispatched_at`, `return_dispatched_by`, `return_dispatch_status`
Outbound quantities are already in `purchase_return_details`, so no new detail table is required.

## UI + Routes

### New list/menu
Add a "Settlement List" screen under the Purchases menu:
- Filters by `settlement_status` (Pending Approval, Approved, Executing).
- Actions: Approve, Reject, Execute, Dispatch, Receive.

### Purchase Return show
Update action buttons based on both approval and settlement status:
- After approval: "Create Settlement" (draft/submit).
- After settlement approval: "Execute Settlement".
- For exchange: "Dispatch Return" -> "Receive Replacement".

## Permissions (Suggested)
Add role permissions to mirror existing patterns (e.g., sale returns):
- `purchaseReturns.approve` (document approval; separate from edit).
- `purchaseReturnSettlements.access`
- `purchaseReturnSettlements.submit`
- `purchaseReturnSettlements.approve`
- `purchaseReturnSettlements.execute`
- `purchaseReturnSettlements.dispatch`
- `purchaseReturnSettlements.receive`
Update `Modules/User/Database/Seeders/PermissionsTableSeeder.php` and role views to expose these.

## Execution Logic (Per Settlement Type)

### Cash
Execution step creates `PurchaseReturnPayment`, stores proof, updates `payment_status`, `paid_amount`, and `settled_at`.

### Deposit
Execution step creates `SupplierCredit` and updates `purchase_returns` as settled.

### Exchange
Two steps:
1. **Dispatch return to supplier**
   - Reduce stock at location (similar to `TransferStockController@dispatchShipment`).
   - Mark returned serials as not-sellable.
   - Update `return_dispatched_at/by`.
2. **Receive replacement goods**
   - Increase stock, add or update `product_serial_numbers`.
   - Set `purchase_return_goods.received_at` and optional `serial_number`.
   - Mark settlement completed once all planned replacement goods are received.

## Serial Number Handling (Fixes)

### Uniqueness per product globally
Change `product_serial_numbers` unique index:
- Drop unique index on `serial_number`.
- Add composite unique index on `(product_id, serial_number)`.
Update validation rules to enforce the composite uniqueness:
- `Modules/Product/Http/Requests/InputSerialNumbersRequest.php`
- `Modules/Product/Http/Controllers/SerialNumberController.php`
- Purchase return validation in `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php`

### Return lifecycle flags
Add fields to `product_serial_numbers`:
- `is_in_return_process` (bool)
- `purchase_return_id` (nullable FK)
- Optional: `is_retired` or `return_status` enum

Usage:
- On purchase return approval, mark selected serials as `is_in_return_process = true` and set `purchase_return_id`.
- Dispatch validation should block serials that are `is_in_return_process = true` or `is_retired = true`.
- **Repaired return**: on receive, update the same serial record, clear `is_in_return_process`, and restore `location_id`.
- **Replacement with new serial**: mark old serial as `is_retired = true` and create a new serial record with the replacement serial number.

## Step-by-step batches

### Batch 1: Discovery + alignment (Completed)
- **Current Flow Analysis**:
  - `PurchasesReturnController@store/update`: Stock is only reduced if the user manually sets status to 'Shipped' or 'Completed' during creation/edit.
  - `PurchasesReturnController@approve`: Updates status to `Awaiting Settlement`. **No stock reduction happens.**
  - `PurchaseReturnSettlementForm@submit`: Creates replacement goods/payments and marks return as `Completed`. **No stock reduction happens for the returned items.**
  - **Conclusion**: The current flow fails to reduce inventory for returned items when using the approval -> settlement path.
- **Stock Movement Decision**:
  - **Exchange**: Stock reduction for returned items MUST happen at the **Dispatch** step (Batch 6).
  - **Cash/Deposit**: Stock reduction for returned items MUST happen at the **Execution** step (Batch 5), effectively acting as an auto-dispatch if not already dispatched.
  - **Status Logic**: `Awaiting Settlement` -> (Settlement Approved) -> `Dispatching` (Exchange only) -> `Completed`.

### Batch 2: Settlement status storage (Completed)
- Add settlement status storage (new table or columns).
- Add basic model accessors/relations if a new table is used.
- Output: migrations + models only, no UI changes yet.

### Batch 3: Permissions + routing scaffolding (Completed)
- Add permissions in `Modules/User/Database/Seeders/PermissionsTableSeeder.php`.
- Add routes for settlement submit/approve/reject/execute (controller methods can be stubs).
- Output: routes + permission seed updates, no behavior changes.

### Batch 4: Settlement submit + approve/reject
- Implement settlement submit (draft -> pending) and approve/reject.
- Lock settlement edits after approval.
- Output: settlement lifecycle works, execution still manual or disabled.

### Batch 5: Execution for cash + deposit
- Implement execution for cash and deposit (create payment or supplier credit).
- Update `purchase_returns` settlement fields and statuses.
- Output: cash/deposit flow completes end-to-end.

### Batch 6: Exchange dispatch flow
- Add "Dispatch Return" action (reduce stock, flag serials).
- Store dispatch timestamps on `purchase_returns`.
- Output: exchange dispatch tracked, no receive yet.

### Batch 7: Exchange receive flow
- Add "Receive Replacement" action (restock, create/update serials).
- Update `purchase_return_goods.received_at` and completion checks.
- Output: exchange flow completes end-to-end.

### Batch 8: Serial number schema improvements
- Add return lifecycle fields to `product_serial_numbers`.
- Enforce composite uniqueness per product.
- Update validation rules for serial creation/selection.
- Output: schema + validation aligned with new return flow.

### Batch 9: UI + menu
- Add settlement list screen + menu item.
- Update purchase return show/actions for settlement states.
- Output: UI covers list, approval, execution, dispatch, receive.

### Batch 10: QA checklist
- Settlement cannot execute before approval.
- Exchange requires dispatch before receive.
- Serial numbers in return process cannot be dispatched or sold.
- Repaired vs replacement serial flows behave as expected.

## Assumptions
- Only one settlement method is allowed per return.
- No accounting/ledger constraints need to be enforced yet.
- Purchase return itself stays locked by approval status; settlement is separately locked once approved.
