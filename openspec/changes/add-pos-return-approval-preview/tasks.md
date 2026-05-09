## 1. Preview Routing And Authorization

- [x] 1.1 Change the pending POS Return approve UI action to navigate to an approval preview route instead of submitting approval.
- [x] 1.2 Add a GET approval preview route for `/pos/returns/{return}/approval-preview` guarded by existing POS view access and `pos.returns.approve`.
- [x] 1.3 Ensure preview access is blocked unless the POS Return is in `pending_approval` status with `pending` approval status.
- [x] 1.4 Disable or block direct web approval POST submission during this preview-only change with a clear lifecycle message and no mutation.
- [x] 1.5 Keep reject behavior unchanged unless route or toolbar compatibility requires minor adjustment.

## 2. Approval Preview Planner

- [x] 2.1 Add a side-effect-free POS Return approval preview planner service.
- [x] 2.2 Have the planner treat persisted POS Return lines as selected return intent and verify source snapshot freshness plus live source checkout sales, source sales, sale details, dispatch details, product serial numbers, and replacement serials.
- [x] 2.3 Build grouped target preview output by source generated sale and owner/location context.
- [x] 2.4 Build planned Sales Return header preview data for each target group without creating `sale_returns`.
- [x] 2.5 Build planned Sales Return Detail preview data for each actionable line without creating `sale_return_details`.
- [x] 2.6 Resolve serialized dispatch anchors from the returned serial's current `product_serial_numbers.dispatch_detail_id` first, then verify sale/product context.
- [x] 2.7 Resolve non-serial stock-managed dispatch anchors from persisted line, sale detail, or exactly-one safe dispatch match; block ambiguous or missing matches.
- [x] 2.8 Include stock movement intent, serial movement intent, cash-return amount, replacement serial, tax context, and bundle/component trace data in the preview output.
- [x] 2.9 Use actionable line-level resolutions as authoritative preview intent and report header `return_option` mismatch as warning unless execution is ambiguous.
- [x] 2.10 Return structured `blockers`, `warnings`, and `info` arrays, treating zero linked Sales Returns as non-blocking when planned targets are resolvable.

## 3. Preview Page

- [x] 3.1 Add a read-only approval preview page using existing POS Return and Sales Return Bootstrap/CoreUI conventions.
- [x] 3.2 Show source transaction, receipt, customer, and payment summary at the top of the preview.
- [x] 3.3 Show split generated sales and planned Sales Return target groups.
- [x] 3.4 Show line-level planned target details, including dispatch detail, owner/location, tax, product, quantity, resolution, amount, returned serial, and replacement serial.
- [x] 3.5 Show preview blockers prominently when the planner returns a blocked state.
- [x] 3.6 Do not render an enabled final approve or confirm approval button in this change.
- [x] 3.7 Provide navigation back to the POS Return detail page.

## 4. Mutation Safety

- [x] 4.1 Verify opening approval preview does not change `pos_returns.status`, `approval_status`, `approved_by`, or `approved_at`.
- [x] 4.2 Verify opening approval preview does not create or update `sale_returns` or `sale_return_details`.
- [x] 4.3 Verify opening approval preview does not create stock transactions, serial history, dispatches, dispatch details, or payment records.
- [x] 4.4 Verify opening approval preview does not change `product_serial_numbers`, `product_stocks`, `products.product_quantity`, or `dispatch_details.dispatched_quantity`.
- [x] 4.5 Verify blocked preview states also leave all lifecycle and execution tables unchanged.

## 5. Focused Tests

- [x] 5.1 Add a route authorization test proving users without `pos.returns.approve` cannot open approval preview.
- [x] 5.2 Add a route/lifecycle test proving non-pending returns cannot open approval preview.
- [x] 5.3 Add a feature test proving the approve action opens preview and does not approve immediately.
- [x] 5.4 Add a feature test proving direct approval POST is blocked during preview-only phase and does not approve immediately.
- [x] 5.5 Add a planner test for split-sale preview output grouped by generated sale and source owner/location when no linked Sales Returns exist.
- [x] 5.6 Add a planner test for serial dispatch resolution using `product_serial_numbers.dispatch_detail_id`.
- [x] 5.7 Add planner tests for missing dispatch detail blocker and ambiguous non-serial dispatch fallback blocker.
- [x] 5.8 Add a planner test for mixed `cash_return` and `product_replacement` blocker while final line-level approval execution is not implemented.
- [x] 5.9 Add a planner test proving header `return_option` mismatch is warning/info when line-level intent is otherwise resolvable.
- [x] 5.10 Add planner tests proving source snapshot drift and live source identity mismatch are blockers with no mutations.
- [x] 5.11 Run focused POS Return tests with `php artisan test --filter=PosReturn` or narrower filters, avoiding parallel SQLite test runs.
