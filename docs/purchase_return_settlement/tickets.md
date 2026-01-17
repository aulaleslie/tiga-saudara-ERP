# Purchase Return Settlement — Engineering Tickets

## Ticket 1: Add per-line approval fields to settlement items
- Title: Add approval status fields to purchase_return_item_settlements.
- Description: Track per-line submit, approval, and rejection state directly on settlement item rows while allowing null methods for pending lines.
- Scope: Add migration to make `method` nullable and add status/metadata fields; backfill status for existing rows based on header settlement status; update model casts for new datetime columns.
- Technical notes: Update `Modules/PurchasesReturn/Database/Migrations` and `Modules/PurchasesReturn/Entities/PurchaseReturnItemSettlement.php`; suggested fields: `status`, `submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `rejected_at`, `rejected_by`, `rejection_reason`; consider default `status` = `draft` for null method rows.
- Dependencies: None.
- Edge cases: Existing rows with approved header settlement should backfill to approved; existing rows without header settlement should remain draft; null method rows must not violate validation or indexes.

## Ticket 2: Update settlement entry UI for drafts and per-line submit
- Title: Support null-method drafts and per-line submit-for-approval in settlement page.
- Description: Allow saving draft lines without a method and add per-line submit actions that lock the line once submitted.
- Scope: Relax Livewire validation for lines without method; save rows with null method; add per-line submit action and status display; lock submitted/approved lines; show rejection reason and reset rejected lines for editing.
- Technical notes: Update `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php` and `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php`; only enforce nominal/target purchase validation when a line is being submitted; keep header settlement record creation on "Simpan Penyelesaian" with roll-up status derived later.
- Dependencies: Ticket 1.
- Edge cases: Serial and non-serial lines mixed; method cleared after rejection should reset nominal/target fields; ensure submit is blocked when method is empty; ensure null method lines do not show approval buttons.

## Ticket 3: Implement per-line approval and settlement effects
- Title: Per-line approval endpoint with approval-time execution.
- Description: Replace header-level execute with per-line approvals that apply financial effects on approval and reset lines on rejection.
- Scope: Add approve/reject endpoints for item settlements; apply method-specific effects in a transaction; update line status/metadata; clear method/nominal/target on rejection; update roll-up status after each action.
- Technical notes: Repurpose logic in `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php::execute` into a per-line service; update or create supplier credit and cash payment records by incrementing existing rows for the purchase return; enforce Gate `purchaseReturnSettlements.approve`; ensure idempotency by checking line status before applying effects.
- Dependencies: Tickets 1 and 2.
- Edge cases: Approval called twice; due amount changes between submit and approval; line missing method; concurrency on the same line; partial failures must rollback.

## Ticket 4: Roll-up status derivation for header and purchase return
- Title: Derive header and purchase return status from line states.
- Description: Compute and persist roll-up status for `purchase_return_settlements.status` and `purchase_returns.status` without touching payment fields.
- Scope: Implement a status calculator that updates on save/submit/approve/reject; introduce roll-up labels such as "Settled Partially" and "Settled"; update settlement status display to use derived values.
- Technical notes: Update `Modules/PurchasesReturn/Resources/views/partials/settlement-status.blade.php` and any list/datatable rendering; avoid updating `payment_status` and `settled_at` per requirements.
- Dependencies: Tickets 1 and 3.
- Edge cases: All lines rejected; mix of approved and pending; all lines pending; no lines at all; ensure existing statuses like "Awaiting Settlement" still render sensibly.

## Ticket 5: Detail, list, and print UI updates for per-line status
- Title: Show per-line settlement status and approval actions in UI.
- Description: Replace header-level approve/execute actions with per-line approval controls and per-line method/status display.
- Scope: Update `Modules/PurchasesReturn/Resources/views/show.blade.php` to show per-line approval buttons and statuses; update print view `Modules/PurchasesReturn/Resources/views/print.blade.php` and list status summaries to display per-line methods; remove reliance on header `payment_method` and `return_type`.
- Technical notes: Use `purchaseReturn->settlementItems` and `purchaseReturnDetails` to render line-level method/status; ensure buttons are gated by permissions; keep dispatch flow untouched.
- Dependencies: Tickets 2, 3, and 4.
- Edge cases: Large returns with many serials; lines without method; approved lines with method PRODUCT_REPAIR or BROKEN_STOCK; mixed methods in summary.

## Ticket 6: Permissions and gating adjustments
- Title: Align permissions with per-line submit and approval flow.
- Description: Ensure role permissions match the new per-line submit/approve actions and UI visibility rules.
- Scope: Map existing permissions (`purchaseReturnSettlements.submit`, `purchaseReturnSettlements.approve`, `purchaseReturns.viewPrice`) to per-line actions; add a per-line submit permission if needed; update UI gating and controller checks.
- Technical notes: Update Gate checks in controllers and Livewire; update any permission seeders if new permission keys are added.
- Dependencies: Tickets 2 and 5.
- Edge cases: User loses permission after page load; ensure backend checks prevent unauthorized submit/approve even if UI is visible.

## Ticket 7: Tests and regression coverage
- Title: Add tests for per-line draft, submit, and approval.
- Description: Update automated tests to cover null-method drafts, per-line submission, approval-time effects, and roll-up status.
- Scope: Update Livewire tests for settlement form; add feature tests for per-line approval effects (credit/cash/modify purchase) and rejection reset; verify `payment_status` and `settled_at` remain unchanged.
- Technical notes: Modify tests in `Modules/PurchasesReturn/Tests/Feature` that assume header-level approval/execute; add assertions for new item status fields.
- Dependencies: Tickets 1 through 6.
- Edge cases: Approve line twice should be rejected or no-op; pending lines must not block approvals; ensure partial status roll-up is correct.
