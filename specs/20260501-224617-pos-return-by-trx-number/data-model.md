# Data Model: POS Return by Transaction Number

## Entity: POS Return
- Description: Single parent return request initiated from one completed POS transaction.
- Proposed table: `pos_returns`
- Fields:
  - `id`
  - `reference` (generated POS return reference)
  - `setting_id` (current/business setting scope)
  - `pos_transaction_id` (required)
  - `pos_checkout_id` (required posted checkout)
  - `transaction_code` (snapshot of `pos_transactions.code`)
  - `receipt_number` (snapshot of `pos_checkouts.receipt_number`)
  - `customer_id` (nullable)
  - `customer_name` (snapshot)
  - `return_option` (`cash_return | product_replacement`)
  - `status` (`draft | pending_approval | approved | rejected | awaiting_receiving | awaiting_settlement | awaiting_dispatch | completed | archived | cancelled`)
  - `approval_status` (`draft | pending | approved | rejected`)
  - `is_reversed` (boolean, default false, true only for fully audited cancelled/archived returns that release eligibility)
  - `source_snapshot` (JSON canonical transaction snapshot)
  - `source_snapshot_hash` (string hash validated before submit)
  - `total_amount` (decimal)
  - `approved_by`, `approved_at`
  - `rejected_by`, `rejected_at`, `rejection_reason`
  - `received_by`, `received_at`
  - `settled_by`, `settled_at`
  - `archived_by`, `archived_at`, `archive_reason`
  - `cancelled_by`, `cancelled_at`, `cancel_reason`
  - `created_by`, `updated_by`
- Relationships:
  - Belongs to `PosTransaction`.
  - Belongs to `PosCheckout`.
  - Has many `POS Return Line`.
  - Has many linked `SaleReturn` records or has linked `sale_returns.pos_return_id`.
- Validation rules:
  - Source transaction must be completed and checkout must be posted.
  - Lookup must resolve to exactly one active posted POS transaction/checkout match.
  - User must have matching `pos.returns.*` permission for the attempted action.
  - Source setting/scope must be visible to current user.
  - `return_option` is exactly `cash_return` or `product_replacement`.
  - Direct edit/delete allowed only before approval.
  - After approval, correction must use an audited archive/cancel path that records actor, timestamp, reason, and linked Sales Return state impact.
  - Received, settled, dispatched, or completed returns cannot be silently archived/cancelled; any reversal must preserve financial, inventory, and dispatch audit history.
  - Submitted lifecycle actions must be atomic across POS Return, linked Sales Return, stock, settlement, and dispatch mutations.

## Entity: POS Return Line
- Description: Return quantity for a concrete original POS-generated sale/dispatch detail.
- Proposed table: `pos_return_lines`
- Fields:
  - `id`
  - `pos_return_id`
  - `pos_checkout_sale_id` (owner/sale split row)
  - `sale_return_id` (nullable until Sales Return record is created)
  - `sale_return_detail_id` (nullable until Sales Return detail is created)
  - `sale_id`
  - `sale_detail_id`
  - `dispatch_detail_id`
  - `source_setting_id`
  - `source_location_id`
  - `tax_id` (nullable)
  - `product_id`
  - `product_name`
  - `product_code`
  - `quantity`
  - `unit_price`
  - `line_total`
  - `serial_number_ids` (JSON nullable)
  - `bundle_group_key` (nullable)
  - `bundle_parent_sale_detail_id` (nullable)
  - `bundle_quantity` (nullable for component lines)
  - `component_quantity_per_bundle` (nullable)
  - `stock_behavior` (`stock_managed | stockless`)
  - `replacement_product_id` (nullable; must equal `product_id` for product replacement)
  - `replacement_quantity` (nullable; must equal received returned quantity for product replacement)
- Relationships:
  - Belongs to `POS Return`.
  - Belongs to original `Sale`, `SaleDetails`, and `DispatchDetail`.
  - Optionally belongs to linked `SaleReturn` and `SaleReturnDetail`.
- Validation rules:
  - Quantity must be positive and <= still-returnable dispatched quantity for the dispatch detail.
  - For stockless bundle components, quantity must be positive and <= original sold component quantity; it must not create dispatch or inventory reductions.
  - Cumulative active non-reversed returns for the same dispatch detail/component must not exceed original dispatched/sold quantity.
  - Serial-tracked lines must identify serials from the original dispatch detail.
  - Replacement source setting/location must equal original `source_setting_id`/`source_location_id`.
  - Replacement product/SKU must equal the returned line product/SKU and replacement quantity must equal the received returned quantity.

## Entity: POS Return Bundle Group
- Description: Logical grouping for a returned POS bundle and all component return lines. May be represented by line fields rather than a separate table if implementation can preserve grouping without extra persistence.
- Fields:
  - `bundle_group_key`
  - `pos_return_id`
  - `bundle_parent_sale_detail_id`
  - `bundle_product_id`
  - `returned_bundle_quantity`
  - `component_lines` (derived from related POS Return Lines)
- Rules:
  - Parent-only return is invalid.
  - All component lines from the original bundle composition must be present.
  - Partial bundle returns are valid only when every component quantity is proportional to returned bundle quantity.
  - Stock-managed components affect Sales Return details, dispatch/inventory quantities, and serial handling.
  - Stockless components remain in POS Return lines for audit and monetary mapping and do not create dispatch/inventory reductions.

## Entity: Source Snapshot
- Description: Immutable source view populated after entering a POS transaction number.
- Fields:
  - POS transaction: id, code, status, customer, source session, completed checkout id
  - POS checkout: id, receipt number, status, cashier, terminal, finalized timestamp, totals, payment summary
  - Generated sales: `pos_checkout_sales`, sale references, owner/source setting, source location, tax bucket, payment allocation, dispatch ids
  - Returnable lines: sale detail, dispatch detail, product, location, tax, serials, bundle context, dispatched quantity, returned quantity, still-returnable quantity
  - Hash metadata: canonical hash and generated timestamp
- Rules:
  - Snapshot is read-only in UI.
  - Snapshot hash must match server-rebuilt source state before submission/update.
  - Snapshot must not include out-of-scope transactions.
  - Snapshot is stale when transaction/checkout status, generated sales, dispatch details or quantities, active prior returns, bundle composition, serial assignment, owner/location/tax mapping, or payment allocation changes.

## Existing Entity Extensions

### SaleReturn
- Add nullable `pos_return_id` to link owner/sale-aligned Sales Return records to POS Return.
- Add nullable POS source metadata if needed for audit/search:
  - `pos_transaction_id`
  - `pos_checkout_id`
  - `pos_return_option`
- Rules:
  - Linked Sale Return retains existing approval, receiving, settlement, and dispatch statuses.
  - POS wrapper status derives from linked Sale Return states.

### SaleReturnDetail
- Reuse existing fields:
  - `sale_detail_id`
  - `dispatch_detail_id`
  - `location_id`
  - `tax_id`
  - `serial_number_ids`
- Add only if needed for traceability:
  - `pos_return_line_id`
  - `bundle_group_key`
  - `stock_behavior`
- Rules:
  - The detail is the authoritative execution line for Sales Return receiving and settlement.
  - Stockless POS Return lines should not be converted into stock-moving Sales Return details unless the implementation introduces a no-stock audit-only detail path.

## Migration and Compatibility Rules
- Production schema changes target MySQL/MariaDB and must be generated/applied through Laravel migration tooling (`php artisan make:migration` / `php artisan migrate`).
- SQLite compatibility is only for focused automated tests where the existing test suite runs migrations against SQLite.
- `pos_returns` and `pos_return_lines` are new tables and must define reversible `down()` drops.
- Sales Return and Sale Return Detail linkage columns must be nullable/default-compatible so existing historical return rows remain valid after migration.
- Linkage migration `down()` methods must drop added indexes and columns in dependency-safe order.
- No migration should rewrite existing POS, Sale, Dispatch, or Sales Return history; source references are additive snapshots/links.
- Add indexes for lookup and constraints: `pos_transaction_id`, `pos_checkout_id`, `source_snapshot_hash`, `status`, `approval_status`, `is_reversed`, `dispatch_detail_id`, `sale_id`, `sale_return_id`, and bundle group lookup fields as needed.

## State Transitions
- `draft` -> `pending_approval` when a valid source snapshot and selected return lines are submitted.
- `pending_approval` -> `approved` when authorized approver approves.
- `pending_approval` -> `rejected` when authorized approver rejects with optional reason.
- `approved` -> `awaiting_receiving` when linked Sales Return records are ready to receive.
- `awaiting_receiving` -> `awaiting_settlement` after returned goods are received.
- `awaiting_settlement` -> `completed` for cash return after manual cash refund settlement completes.
- `awaiting_settlement` -> `awaiting_dispatch` for product replacement when settlement/dispatch request is prepared.
- `awaiting_dispatch` -> `completed` after replacement dispatch completes.
- `draft` or `pending_approval` -> `deleted` only when authorized and not approved.
- `approved` or `awaiting_receiving` -> `archived` or `cancelled` only through audited archive/cancel rules before receiving.
- Pending returns may be approved or rejected.
- Approved returns cannot be directly edited, deleted, or rejected; before receiving they may only be archived/cancelled through audited reversal rules.
- Receiving permanently blocks direct edit/delete/reject/archive/cancel actions.
