## Why

The POS session summary page displays transaction and cash event data correctly from the API, but fails to render the cash reconciliation breakdown (Saldo Awal, Penjualan Kas, Pengambilan Kas). Users see all reconciliation values as 0, making it impossible to verify cash flow calculations before session finalization. The session summary should provide a complete cash reconciliation view so cashiers and supervisors can validate cash accountability before finalization.

## What Changes

- Add a new "Perhitungan Kas" (Cash Reconciliation) card to the session summary page that displays:
  - Saldo Awal (Opening Float) extracted from OPEN_FLOAT cash events
  - Penjualan Kas (Cash Sales) calculated from CASH_SALE_IN events
  - Pengambilan Kas (Safe Drops) calculated from SAFE_DROP_OUT events
  - Non-cash transaction amount (editable input field)
  - Reconciliation result: `opening_float + cash_sales + non_cash - safe_drops = expected_total_sales`
- Display the calculated expected cash total and actual sales total for comparison
- Ensure the reconciliation values match those shown in the finalization modal
- Add form submission to save non-cash transaction adjustments (if supervisor approval required)

## Capabilities

### New Capabilities
- `pos-session-cash-reconciliation`: Display cash flow breakdown with opening float, cash sales, safe drops, and non-cash transaction input on session summary page

### Modified Capabilities
- `pos-session-detail-page`: Add cash reconciliation section to the existing session summary view

## Impact

- **Files Modified**: `Modules/Pos/Resources/views/session/summary.blade.php` (add reconciliation card component), `Modules/Pos/Http/Controllers/PosSessionController.php` (may need data transformation), `Modules/Pos/Services/PosSessionSummaryService.php` (ensure all required fields are returned)
- **UI Impact**: New card in session summary with calculated fields and optional input field
- **API Impact**: None - all required data is already returned by `PosSessionSummaryService::getSummary()`
- **Data Impact**: Non-cash transaction totals may need to be stored or tracked separately
- **Permissions**: Reconciliation view visible to all users; editing non-cash amounts may require supervisor permission
