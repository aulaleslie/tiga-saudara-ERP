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
  - Resolves a POS transaction or receipt number and returns the source snapshot only when exactly one active posted transaction/checkout match exists.
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
  - Lookup must trim whitespace, compare within current setting/scope, and reject zero or multiple active posted matches.

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
  - Stock-managed lines include dispatch/inventory context.
  - Stockless bundle component lines include audit/monetary context and no inventory-reduction instruction.
- `snapshot_hash`
  - Canonical hash that must be posted back during submission

## Lookup Failure Contract
- Unknown transaction number: block with user-facing correction message.
- Multiple active posted matches for the entered number: block with an ambiguity correction message.
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
  - `stock_behavior`: `stock_managed | stockless`

## Submit Validation Contract
- User still has `pos.returns.create`.
- Source transaction is still completed/posted and in scope.
- Server-rebuilt source snapshot hash matches submitted hash.
- At least one line has positive quantity.
- Each quantity is <= still-returnable dispatched quantity.
- Stockless bundle component quantity is <= original sold component quantity and cannot create inventory or dispatch reduction.
- Active non-reversed cumulative returns cannot exceed original dispatched/sold quantity.
- Bundle groups include all required components proportionally.
- Return option is exactly one of the two supported options.
- A stale snapshot is rejected when transaction/checkout status, generated sales, dispatch details or quantities, active prior returns, bundle composition, serial assignment, owner/location/tax mapping, or payment allocation changed after lookup.

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
  - Replacement product/SKU must match the returned product/SKU.
  - Replacement dispatch quantity must equal the received returned quantity.
- Direct edit/delete is blocked after approval.
- Receiving permanently blocks direct edit/delete.
- All lifecycle actions record actor and timestamp.
- Submit, approve, receive, cash refund, and replacement dispatch are atomic database operations. Failure rolls back the full action; non-rollbackable external effects block completion and require audited manual correction.

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
