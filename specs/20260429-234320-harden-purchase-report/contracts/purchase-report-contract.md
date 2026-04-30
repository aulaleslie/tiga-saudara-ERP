# Contract: Purchase Report UI/Export Behavior

## Scope
Internal web interface contract for Livewire purchase report and export actions.

## Inputs
- Trigger: `Tampilkan Laporan`
- Filters:
  - `startDate` (required date)
  - `endDate` (required date, >= `startDate`)
  - `supplierIds` (optional array, existing suppliers)
  - `withTax` (optional, `0|1`)
  - `selectedTags` (optional array, existing tags)
  - `status` (optional, allowed purchase status)
  - `paymentStatus` (optional, `Paid|Partial|Unpaid` canonicalized)

## Searchable Dropdown Contract (Supplier/Tag)
- Applies to: `supplierIds` and `selectedTags` controls only.
- Trigger behavior:
  - Do not call server before 2 characters are entered.
  - Apply 300ms debounce before each lookup request.
- Request contract:
  - `type`: `supplier|tag`
  - `query`: string (`len >= 2`)
- Response contract:
  - `items`: array of `{ id, label }`
  - Optional empty array when no match.
- Interaction contract:
  - Must support multi-select.
  - Each selected item displayed as a dismissible pill.
  - On clicking a suggestion: add pill, clear search input, close dropdown immediately.
- Constraints:
  - Full supplier/tag option sets must not be preloaded in initial page payload.
  - Lookup results must be scoped consistently with current user visibility rules.

## Standard Select Contract
- Applies to: Pajak, Status, Status Pembayaran.
- Styling: Must use the `form-control` CSS class for consistency with the CoreUI theme.

## Validation Rules
- Reject invalid date formats.
- Reject `endDate < startDate` with user-readable message.
- Reject out-of-set status, payment status, and tax flag values.
- Reject nonexistent supplier/tag references in arrays.

## Output Contract (On-screen)
- On success:
  - A paginated collection where every row satisfies validated filters and scope.
  - Deterministic empty state when no rows match.
- On validation failure:
  - No report query/execution.
  - User-facing field errors only (no internal exception details).

## Export Contract (Excel/CSV/PDF)
- Precondition:
  - Must have a successful latest report run for current filter hash/scope.
  - If missing, export is blocked with clear message.
- Behavior:
  - Export dataset must come from same validated query contract and snapshot context as last successful run.
  - Period metadata in output must match selected dates.

## Scope Contract
- Non-global mode: restrict to `setting_id = session('setting_id')`.
- Global mode: no setting restriction, but still enforce permission/access gate.
