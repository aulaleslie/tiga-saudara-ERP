## MODIFIED Requirements

### Requirement: Gratitude modal displays after successful payment
The system SHALL display a gratitude modal after payment is finalized that clearly communicates the amount of change (if any) and reminds the cashier to thank the customer.

#### Scenario: Change amount displayed
- **WHEN** a checkout is finalized with payment amount exceeding the transaction total
- **THEN** the gratitude modal displays "Total Kembalian <formatted amount>" (e.g., "Total Kembalian Rp 30.000")

#### Scenario: No change amount displayed
- **WHEN** a checkout is finalized with exact payment or non-cash payment (no change)
- **THEN** the gratitude modal displays only "Jangan Lupa Ucapkan Terima Kasih!" without a change amount line

#### Scenario: Modal is modal-blocking
- **WHEN** the gratitude modal is shown
- **THEN** the user must click "Lanjut Jualan" to dismiss it (cannot click outside to close)
