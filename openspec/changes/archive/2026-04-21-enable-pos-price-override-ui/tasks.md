## 1. Backend: Relax Price Validation to Accept Zero

- [x] 1.1 Update `StorePosCartPriceOverrideRequest` validation rule from `'gt:0'` to `'gte:0'` in `Modules/Pos/Http/Requests/StorePosCartPriceOverrideRequest.php`
- [x] 1.2 Update `PosCartService::overrideLinePrice()` — change the entry guard from `$unitPrice <= 0` to `$unitPrice < 0` (line 485)
- [x] 1.3 Update `PosCartService::overrideLinePrice()` — change the approved-request payload validation from `$requestedUnitPrice <= 0` to `$requestedUnitPrice < 0` (line 530)
- [x] 1.4 Update `PosCartService::overrideLinePrice()` — change the final target price guard from `$targetUnitPrice <= 0` to `$targetUnitPrice < 0` (line 546)

## 2. Backend: Enrich Snapshot with Requested Unit Price

- [x] 2.1 In `PosCartService::buildSnapshot()`, add extraction of `unit_price` from `request_payload` alongside the existing `qty` extraction (after line 749), exposing it as `requested_unit_price` in the approval data

## 3. Frontend: Price Override Modal Partial

- [x] 3.1 Create `Modules/Pos/Resources/views/sell/modals/price_override.blade.php` with Bootstrap modal containing: current price display (read-only), new price input (numeric, min=0), validation error area, and submit button
- [x] 3.2 Add `@include('pos::sell.modals.price_override')` to `sell.blade.php` alongside existing modal includes

## 4. Frontend: Price Cell Rendering with Approval State

- [x] 4.1 In `buildLineRow()`, replace the static price `<td>` (line 1149) with a price cell that includes the formatted price plus a `js-price-edit` button
- [x] 4.2 Add PRICE_OVERRIDE approval state rendering: filter `line.pending_approvals` for `action_type === 'PRICE_OVERRIDE'`, render Periksa/Lanjutkan button variants with `data-approval-pending`/`data-approval-token`/`data-approved-price` attributes (matching the LINE_REMOVE delete button pattern)

## 5. Frontend: JavaScript Event Handlers

- [x] 5.1 Add `canOverridePrice` capability variable from `roleCapabilities.direct_permissions.price_override` in the JS init block
- [x] 5.2 Add pending state variables: `pendingPriceLineId`, `pendingPriceCurrentPrice`, `pendingPriceButton`
- [x] 5.3 Add DOM references for the price override modal elements (modal, current price display, new price input, error area, submit button)
- [x] 5.4 Add click handler for `js-price-edit` button in the `cartBody` click listener: route to modal open (no approval state), checkApproval (pending state), or wrapAction with token (approved state)
- [x] 5.5 Add price override modal input validation: enable/disable submit based on numeric >= 0, non-negative, different from current price
- [x] 5.6 Add price override modal submit handler: close modal → call `ApprovalManager.wrapAction()` with action type `PRICE_OVERRIDE`, target type `pos_cart_line`, payload `{ unit_price: newPrice }`, and action function that POSTs to price-override endpoint
- [x] 5.7 Add modal reset handler on `hidden.bs.modal` event to clear pending state variables
- [x] 5.8 Handle post-approval re-render: after approval request creation, store `clientPendingApprovals[lineId]` with price context, fetch fresh snapshot, and re-render cart

## 6. Testing

- [x] 6.1 Write feature test: privileged user can override price to a positive value directly (no approval required)
- [x] 6.2 Write feature test: privileged user can override price to zero directly
- [x] 6.3 Write feature test: non-privileged user attempting price override receives APPROVAL_REQUIRED
- [x] 6.4 Write feature test: non-privileged user with valid approval token can apply approved price
- [x] 6.5 Write feature test: negative price is rejected by validation
- [x] 6.6 Write feature test: snapshot includes `requested_unit_price` for PRICE_OVERRIDE approvals
