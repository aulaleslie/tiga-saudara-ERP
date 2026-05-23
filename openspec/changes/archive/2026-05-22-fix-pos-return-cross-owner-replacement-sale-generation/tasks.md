## 1. Schema And Existing Context

- [x] 1.1 Verify `sale_return_details.execution_context` exists in all migrated environments and add a guarded repair migration if the existing migration can be missed.
- [x] 1.2 Identify existing Sale reference, customer resolution, SalePayment, dispatch, and serial tracking helpers that can be reused for generated replacement-owner Sales.
- [x] 1.3 Add nullable audit/link metadata only if existing Sale, SaleDetail, DispatchDetail, SalePayment, or POS Return links cannot trace generated replacement-owner Sales back to the POS Return line.

## 2. Replacement Serial Owner Resolution

- [x] 2.1 Add a reusable resolver for replacement serial owner using `product_serial_numbers.location_id -> locations.setting_id`.
- [x] 2.2 Update replacement serial validation to require same `product_id`, active serial status, resolvable owner location, and no active draft/approval lock.
- [x] 2.3 Keep replacement selection strict by `product_id`; block same-code/different-product replacements with a clear message.
- [x] 2.4 Ensure create and edit Livewire forms surface replacement serial owner/location validation errors without mutating return state.

## 3. Approval Preview Planning

- [x] 3.1 Extend `PosReturnApprovalPreviewPlannerService` to classify each replacement line as same-owner or cross-owner.
- [x] 3.2 Add preview detail fields for replacement serial owner, replacement serial location, execution mode, and generated replacement-owner Sale effects.
- [x] 3.3 Add blockers for missing replacement owner, stale replacement serial location/status, different `product_id`, unavailable replacement stock, and missing generated Sale prerequisites.
- [x] 3.4 Update approval preview Blade output to show cross-owner replacement mode, original Sale correction, and replacement-owner Sale/payment/dispatch plan.
- [x] 3.5 Preserve existing same-owner replacement and bundle replacement preview behavior.

## 4. Approval Plan Persistence

- [x] 4.1 Extend persisted execution context for replacement lines with replacement owner setting, replacement location, execution mode, and generated Sale intent.
- [x] 4.2 Ensure existing linked Sales Return validation compares the new replacement execution fields when cross-owner replacement is planned.
- [x] 4.3 Keep persisted Sale Return details compatible with old same-owner replacement records.

## 5. Cross-Owner Approval Execution

- [x] 5.1 Branch final approval execution so same-owner replacement keeps the current replacement dispatch path.
- [x] 5.2 For cross-owner replacement, receive returned stock and serials to the original source location.
- [x] 5.3 Apply cash-return-style commercial correction to the original Sale detail, dispatch quantity, Sale totals, and active Sale payments.
- [x] 5.4 Create a generated replacement-owner Sale using the replacement owner setting, copied original Sale date/header/customer/payment context, and a new owner-specific reference.
- [x] 5.5 Create generated replacement-owner SaleDetail and SalePayment rows for the adjusted replacement amount.
- [x] 5.6 Create an approved replacement-owner Dispatch and DispatchDetail at the replacement serial's current location.
- [x] 5.7 Decrement ProductStock and create stock transaction rows using the replacement serial owner setting and location.
- [x] 5.8 Mark the replacement serial sold and link serial tracking/history to the generated replacement-owner Sale dispatch.
- [x] 5.9 Ensure all cross-owner replacement effects run inside the existing final approval transaction and roll back completely on any failure.

## 6. Tests

- [x] 6.1 Add preview tests showing same-owner replacement remains unchanged.
- [x] 6.2 Add preview tests showing cross-owner replacement owner, original Sale correction, and generated replacement-owner Sale plan.
- [x] 6.3 Add validation tests for missing replacement owner, different `product_id`, inactive replacement serial, and stale replacement serial location.
- [x] 6.4 Add final approval tests for Setting A original sale adjusted and Setting B replacement-owner Sale/payment/dispatch generated.
- [x] 6.5 Add serial lineage tests verifying serial A remains returned on Setting A Sale and serial B is sold under Setting B Sale.
- [x] 6.6 Add stock transaction tests verifying cross-owner replacement dispatch uses replacement owner setting and location.
- [x] 6.7 Add rollback tests proving generated Sale failure rolls back original Sale correction and serial/stock mutations.
- [x] 6.8 Add regression tests for the `sale_return_details.execution_context` schema dependency.

## 7. Verification

- [x] 7.1 Run focused POS Return approval preview and replacement dispatch tests.
- [x] 7.2 Run focused Sales Return lifecycle/payment reconciliation tests touched by cross-owner replacement.
- [x] 7.3 Run `php artisan test` filters for the new cross-owner replacement tests.
- [x] 7.4 Run `composer test:fresh-sqlite` if the focused tests pass and time permits.
