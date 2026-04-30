# Data Model: Harden Purchase Report Validity

## Entity: PurchaseReportFilter
- Description: Validated filter input for report generation and export.
- Fields:
  - `start_date` (date, required)
  - `end_date` (date, required, must be >= `start_date`)
  - `supplier_id` (nullable, exists in suppliers)
  - `with_tax` (nullable enum: `"1" | "0"`)
  - `tag_id` (nullable, exists in tags)
  - `status` (nullable enum from active purchase statuses, default includes all)
  - `payment_status` (nullable enum: `PAID | PARTIAL | UNPAID`)
  - `is_global` (bool)
  - `scope_setting_id` (nullable integer; required when non-global)

## Entity: PurchaseReportSnapshot
- Description: Canonical validated report run state used to authorize and drive export.
- Fields:
  - `snapshot_key` (string)
  - `validated_filter_hash` (string)
  - `generated_at` (datetime)
  - `actor_user_id` (integer)
  - `is_global` (bool)
  - `scope_setting_id` (nullable integer)
  - `result_count` (integer)
- Relationships:
  - Belongs to acting user/session context.
  - References `PurchaseReportFilter` payload.

## Entity: PurchaseReportRow
- Description: One displayed/exported purchase transaction row after scope and filters.
- Fields (derived from `purchases` + related tables):
  - `purchase_id` (id)
  - `date`
  - `reference`
  - `supplier_name`
  - `status`
  - `payment_status` (effective; derived from active payments)
  - `total_amount`
  - `tax_amount`
  - `is_tax_included`
  - `due_amount`

## Entity: ActivePaymentSignal
- Description: Derived payment totals/status from `purchase_payments` where status is ACTIVE.
- Fields:
  - `purchase_id`
  - `effective_paid_amount` (sum active payments)
  - `effective_due_amount` (purchase total - effective paid)
  - `effective_payment_status` (`PAID | PARTIAL | UNPAID`)

## Entity: SearchableFilterLookup
- Description: Server-side lookup response contract for large Supplier/Tag filter option sets.
- Fields:
  - `type` (`supplier | tag`)
  - `query` (string, minimum 2 characters)
  - `items` (array of `{id, label}` option rows)
  - `limit` (integer, per-request option cap)
- Rules:
  - Lookup queries are debounced on client by 300ms.
  - Full supplier/tag option sets are never preloaded into the page payload.

## State/Flow Rules
- `Idle` -> `Validated` when `Tampilkan Laporan` succeeds.
- `Validated` -> `Exportable` only when filter hash equals latest successful snapshot.
- Any filter change invalidates exportability until report is re-run.
- Supplier/Tag lookup remains `Idle` until query length >=2, then transitions to `Searching` -> `Resolved` for option display.
