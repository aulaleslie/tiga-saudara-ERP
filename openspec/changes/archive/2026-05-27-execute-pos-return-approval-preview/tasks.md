## 1. Schema And Model Foundations

 [x] 1.1 Add Sale payment invalidation migration for `sale_payments.status`, `invalidated_at`, `invalidated_by`, `invalidation_source`, and `invalidation_source_id` with active defaults and safe rollback
 [x] 1.2 Update `Modules/Sale/Entities/SalePayment.php` with active/invalidated constants, casts, relationships, scopes, helpers, and an `invalidateAllActiveForSale` style helper mirroring Purchase payments
 [x] 1.3 Add migration support for POS replacement dispatch lineage on dispatch or dispatch detail records using nullable fields or metadata compatible with existing dispatch rows
 [x] 1.4 Update Sale dispatch-related models to expose replacement lineage fields without changing historical dispatch behavior
 [x] 1.5 Add indexes needed for approval execution lookups across POS Return lines, linked Sales Returns, Sale payments, dispatch details, serial tracking, and replacement lineage

## 2. Preview Execution Gating

- [x] 2.1 Update approval preview route/controller behavior so opening preview remains non-mutating and final approval is a separate explicit POST action
- [x] 2.2 Add final approval control to `Modules/Pos/Resources/views/returns/approval-preview.blade.php` only when the latest preview has zero blockers and zero warnings
- [x] 2.3 Keep blockers, warnings, and informational notes visually separate while disabling final approval whenever warnings are present
- [x] 2.4 Ensure final approval requires only `pos.returns.approve` and redirects to the POS Return show page after success
- [x] 2.5 Rebuild the approval preview plan server-side during final approval submission and reject execution if blockers or warnings appear

## 3. Execution Plan Persistence

- [x] 3.1 Add a service method that converts ready preview groups/details into owner/sale/location/tax-aligned linked `SaleReturn` and `SaleReturnDetail` records
- [x] 3.2 Make execution create linked Sales Returns when none exist and the plan is derivable
- [x] 3.3 Make execution validate existing linked Sales Returns against the latest preview plan before reusing them
- [x] 3.4 Persist parent and component detail links back to `pos_return_lines` and `sale_return_details` for traceability
- [x] 3.5 Block execution when existing linked Sales Returns conflict with the latest plan

## 4. Atomic Final Approval Lifecycle

- [x] 4.1 Add a `executeApprovalFromPreview` lifecycle entry point in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] 4.2 Lock the POS Return, POS Return lines, source Sales, dispatch details, product stocks, serials, Sale payments, and linked Sales Returns needed by the execution plan
- [x] 4.3 Apply approval audit fields to the POS Return and linked Sales Returns inside the execution transaction
- [x] 4.4 Execute line effects by `PosReturnLine::resolution` so mixed cash-return and product-replacement lines are handled in the same POS Return
- [x] 4.5 Mark linked Sales Returns completed and mark the POS Return completed only after all line effects succeed
- [x] 4.6 Ensure any exception rolls back all lifecycle, stock, serial, dispatch, payment, Sale, and Sales Return mutations

## 5. Cash Return Sale Correction

- [x] 5.1 Implement source Sale detail quantity and amount reduction for cash-return lines with prorated discount and tax values
- [x] 5.2 Use `expected_cash_amount` as the cash-return monetary source of truth when rounding differs from prorated Sale amounts
- [x] 5.3 Reduce active `dispatch_details.dispatched_quantity` for cash-return lines while preserving historical serial display data
- [x] 5.4 Recalculate source Sale subtotal, tax, discount, shipping, total, paid, due, and payment status after cash-return reductions
- [x] 5.5 Split or invalidate active Sale payments last-payment-first so active payment totals match the corrected Sale paid amount
- [x] 5.6 Create `SaleReturnPayment` refund evidence for cash-return amounts and link it to the completed Sales Return
- [x] 5.7 Archive the source Sale with returned status and audit note when customer-facing Sale quantity and active dispatched quantity are both zero
- [x] 5.8 Preserve the source Sale payment status capitalization style where possible when recalculating partial returns

## 6. Receiving, Stock, And Mutation Transactions

- [x] 6.1 Receive returned stock for cash-return and replacement lines back into the original source owner/location/tax bucket
- [x] 6.2 Update returned serials to active stock at the source location and clear their outbound dispatch linkage while preserving sale return lineage
- [x] 6.3 Record `SALE_RETURN_GOOD_TAX` or `SALE_RETURN_GOOD_NON_TAX` mutation transaction rows for every stock-managed received product
- [x] 6.4 Apply parent and component stock receiving for cash-return bundle parent returns, recording one mutation transaction row per stock-mutated product
- [x] 6.5 Keep stockless/audit-only rows from mutating stock while preserving their Sales Return and POS Return traceability

## 7. Replacement Dispatch Execution

 [x] 7.1 Validate replacement lines use the same SKU, same received quantity, original source owner, and original source location
 [x] 7.2 Create approved replacement `Dispatch` records on the original source Sale with approval actor and timestamp
 [x] 7.3 Create replacement `DispatchDetail` rows with replacement lineage metadata and replacement serial JSON where applicable
 [x] 7.4 Reduce replacement stock from the original source location and record `DISPATCH_RETURN` mutation transaction rows
 [x] 7.5 Update replacement serials to sold status, assign their replacement dispatch detail, and record serial history
 [x] 7.6 Create or update `SalesOrderSerialTracking` rows for replacement serials on the original source Sale
 [x] 7.7 Dispatch only the parent product for replacement bundle parents while keeping mapped components informational

## 8. Sale Serial Lineage Display

- [x] 8.1 Extend `Modules/Sale/Services/SaleSerialDisplayResolver.php` to detect POS replacement serial lineage
- [x] 8.2 Keep returned original serials rendered as red badges using return date or Sales Return history
- [x] 8.3 Render replacement serials from POS replacement dispatch lineage as blue badges
- [x] 8.4 Ensure Sale show display tolerates dispatch active quantity differing from historical serial badge count
- [x] 8.5 Add readable badge titles for returned original serials and replacement serials

## 9. Bundle Execution Guards

- [x] 9.1 Block final approval when a bundle component is selected without its parent bundle return line
- [x] 9.2 Allow partial parent bundle returns and calculate proportional component quantities
- [x] 9.3 Block final approval when required parent targets are missing or warned, and when cash-return component movement targets are missing, ambiguous, or warned by the preview planner
- [x] 9.4 Correct cash-return bundle execution so parent and component reversals proportionally adjust every affected source Sale, including split-owner component Sales with zero-quantity Sale detail placeholders
- [x] 9.5 Ensure replacement bundle execution preserves parent Sale money/quantity while dispatching only the parent replacement movement
- [x] 9.6 Persist enough component row metadata from the approval preview plan to distinguish parent rows from component rows during execution, including component source Sale, sale detail, bundle item, dispatch detail, quantity source, and commercial value source
- [x] 9.7 Reduce cash-return component dispatch quantities and receive component stock back to the component's original owner/location/tax bucket
- [x] 9.8 Proportionally correct split-owner component Sale totals, active Sale payments, and Sale Return Payment refund evidence when component commercial value exists
- [x] 9.9 Keep product-replacement bundle components informational only while cash-return bundle components execute stock, dispatch, Sale, payment, and refund corrections

## 10. Tests And Verification

- [x] 10.1 Add approval preview route/view tests for final approve visibility with zero blockers/warnings and hidden/disabled state when warnings exist
- [x] 10.2 Add final approval mutation safety tests proving preview open is non-mutating and final POST revalidates stale source state
- [x] 10.3 Add cash-return execution tests for non-serial partial return, Sale detail reduction, dispatch quantity reduction, mutation transaction rows, Sale payment split, and Sale Return Payment creation
- [x] 10.4 Add cash-return full return tests proving source Sale totals become zero and the Sale is marked returned and archived
- [x] 10.5 Add serial cash-return tests proving returned serial is active again and appears red in Sale display while active dispatch quantity is reduced
- [x] 10.6 Add product-replacement serial tests proving original Sale money/quantity remain unchanged, original serial appears red, replacement dispatch is approved, replacement serial is sold, and replacement serial appears blue
- [x] 10.7 Add non-serial product-replacement tests proving receiving and replacement dispatch stock movements use the original owner/location and same quantity
- [x] 10.8 Add mixed cash-return and product-replacement tests for one POS Return touching the same source Sale
- [x] 10.9 Add bundle parent cash-return tests covering partial parent quantity, parent and component receiving transactions, and component-only approval block
- [x] 10.10 Add bundle replacement tests covering parent-only replacement movement and informational component context
- [x] 10.11 Add Sale payment invalidation unit/feature tests mirroring Purchase payment active/invalidated scopes and last-payment-first split behavior
- [x] 10.12 Add atomic rollback tests for failures during stock receive, Sale payment split, replacement dispatch, and Sale archival
- [x] 10.13 Run focused tests with `php artisan test --filter=POSReturn` and `php artisan test --filter=SalePayment`
- [x] 10.14 Run broader confidence verification with `composer test:fresh-sqlite -- --filter=POSReturn` when focused tests pass
- [x] 10.15 Add a regression test for POS Return approval execution where a bundle parent cash return expands to a split-owner component Sale whose `sale_details.quantity` is zero, proving final approval proportionally corrects the component Sale/payment/dispatch instead of throwing "no remaining quantity to reduce"
- [x] 10.16 Add regression coverage for mixed cash return plus product replacement on the same bundled SKU: cash-returned original serials remain red, replacement serials appear blue, replacement Sale money/quantity stay unchanged, and cash-return component Sales are corrected
