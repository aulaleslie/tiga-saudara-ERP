## ADDED Requirements

### Requirement: Display session cash reconciliation breakdown
The POS session detail/summary page SHALL display a cash reconciliation section that breaks down the opening float, cash sales, safe drops, and non-cash transactions to help users verify cash accountability.

#### Scenario: Reconciliation card displays on summary page
- **WHEN** user navigates to a closed POS session summary
- **THEN** the page displays a "Perhitungan Kas" (Cash Reconciliation) card showing:
  - Saldo Awal (Opening Float)
  - Penjualan Kas (Cash Sales)
  - Pengambilan Kas (Safe Drops)
  - Penjualan Non-Kas (Non-cash input field)
  - Calculated expected cash total
- **AND** the card is positioned between session overview and timeline sections

#### Scenario: Non-cash input field functionality
- **WHEN** user views the reconciliation card
- **THEN** the "Penjualan Non-Kas" input field is editable
- **AND** entering a value updates the reconciliation calculation in real-time

#### Scenario: Reconciliation values match API data
- **WHEN** the page loads session summary data
- **THEN** the reconciliation values are calculated from the same cash_events array returned by PosSessionSummaryService
- **AND** the opening float, cash sales, and safe drops match finalization modal values
