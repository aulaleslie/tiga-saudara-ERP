# Contract: POS Return UI and Server Behavior

## Scope
Internal web interface contract for POS return lookup, submission, approval, receiving, cash settlement, and replacement dispatch. This is not a public API contract.

## Routes and Permission Gates
- `GET /pos/returns`
  - Permission: `pos.returns.view`
  - Shows POS return list.
- `GET /pos/returns/create`
  - Permission: `pos.returns.create`
  - Shows transaction-number lookup and return form.
- `POST /pos/returns/lookup`
  - Permission: `pos.returns.create`
  - Resolves a POS transaction or receipt number and returns the source snapshot.
- `POST /pos/returns`
  - Permission: `pos.returns.create`
  - Creates and submits a POS Return from a valid snapshot.
- `GET /pos/returns/{pos_return}`
  - Permission: `pos.returns.view`
  - Shows POS Return details and linked Sales Return execution records.
- `GET /pos/returns/{pos_return}/edit`
  - Permission: `pos.returns.edit`
  - Allowed only before approval.
- `PUT /pos/returns/{pos_return}`
  - Permission: `pos.returns.edit`
  - Allowed only before approval and must revalidate snapshot hash/quantity caps.
- `DELETE /pos/returns/{pos_return}`
  - Permission: `pos.returns.delete`
  - Allowed only before approval.
- `POST /pos/returns/{pos_return}/approve`
  - Permission: `pos.returns.approve`
  - Moves pending return into approved/awaiting receiving path.
- `POST /pos/returns/{pos_return}/reject`
  - Permission: `pos.returns.approve`
  - Rejects pending return; requires or accepts rejection reason according to UI convention.
- `POST /pos/returns/{pos_return}/receive`
  - Permission: `pos.returns.receive`
  - Receives returned goods through linked Sales Return records.
- `POST /pos/returns/{pos_return}/cash-refund`
  - Permission: `pos.returns.receive` or settlement permission chosen during implementation; must be restricted to cash return option.
- `POST /pos/returns/{pos_return}/dispatch`
  - Permission: `pos.returns.dispatch`
  - Dispatches replacement goods only for product replacement option.

## Lookup Input Contract
- `transaction_number` (required string)
  - Accepts POS transaction code and POS receipt number.
  - Lookup must trim whitespace and compare within current setting/scope.

## Lookup Success Output
- `transaction`
  - `id`, `code`, `status`
- `checkout`
  - `id`, `receipt_number`, `status`, `finalized_at`, `cashier`, `terminal`
- `customer`
  - `id`, `name`
- `payment_summary`
  - `subtotal`, `discount_total`, `tax_total`, `grand_total`, `paid_total`, `change_total`, staged/split payment rows when present
- `owner_groups`
  - Array of generated sale groups from `pos_checkout_sales`
  - Includes `source_setting_id`, `source_location_id`, `tax_bucket`, sale reference, dispatch ids, and paid allocation
- `returnable_lines`
  - Array of products/components with original sale id, sale detail id, dispatch detail id, owner/location, tax id, dispatched quantity, cumulative returned quantity, still-returnable quantity, unit price, line amount, serial requirement, serial options, and bundle context
- `snapshot_hash`
  - Canonical hash that must be posted back during submission

## Lookup Failure Contract
- Unknown transaction number: block with user-facing correction message.
- Draft, loaded, cancelled, failed, or unposted transaction: block with non-posted transaction message.
- Out-of-scope transaction: block without exposing protected transaction details.
- Fully returned transaction: block with no-returnable-quantity message.

## Submit Input Contract
- `transaction_number`
- `pos_transaction_id`
- `pos_checkout_id`
- `snapshot_hash`
- `return_option`: `cash_return | product_replacement`
- `lines`: array
  - `dispatch_detail_id`
  - `sale_id`
  - `sale_detail_id`
  - `pos_checkout_sale_id`
  - `quantity`
  - `serial_number_ids` (required for serial-tracked quantities)
  - `bundle_group_key` when line is part of a bundle return

## Submit Validation Contract
- User still has `pos.returns.create`.
- Source transaction is still completed/posted and in scope.
- Server-rebuilt source snapshot hash matches submitted hash.
- At least one line has positive quantity.
- Each quantity is <= still-returnable dispatched quantity.
- Active cumulative returns cannot exceed original dispatched quantity.
- Bundle groups include all required components proportionally.
- Return option is exactly one of the two supported options.

## Lifecycle Contract
- Approval must occur before receiving.
- Receiving must occur before cash refund settlement or replacement dispatch.
- Cash return option:
  - Allows manual cash refund settlement only.
  - Blocks replacement dispatch.
  - Refund amount is capped by returned line amount and allocated to the original owner/sale-aligned line.
- Product replacement option:
  - Allows replacement dispatch only.
  - Blocks cash refund settlement.
  - Dispatch source must match original source setting/location.
- Direct edit/delete is blocked after approval.
- Receiving permanently blocks direct edit/delete.
- All lifecycle actions record actor and timestamp.

## UI Contract
- POS Return screens should follow existing Sales Return information hierarchy:
  - Header/status area
  - Source transaction snapshot
  - Returnable lines table
  - Return option controls
  - Approval/receive/settlement/dispatch actions in familiar positions
  - Status labels consistent with Sales Return partials where practical
- Bundle rows must show parent/group context and every required component.
- Owner/sale groups must be visible enough for operators to understand which sale will be reversed.
- User-facing errors must be clear for invalid transaction, unauthorized scope, non-posted transaction, stale snapshot, invalid bundle selection, quantity limit, and invalid lifecycle action.
