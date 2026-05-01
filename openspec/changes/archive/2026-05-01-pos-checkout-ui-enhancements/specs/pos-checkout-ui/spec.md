## ADDED Requirements

### Requirement: POS Customer Creation Refinement
The customer creation modal accessed from the POS page must be streamlined for faster checkout. The optional "Tier" selection field should be removed or visually hidden, as tier management is a back-office concern that slows down rapid customer onboarding at the checkout counter.

#### Scenario: Cashier Adds New Customer
- **WHEN** the cashier opens the "Add New Customer" modal
- **THEN** the form should display Name and Phone number inputs
- **AND** the "Tier" selection input should not be visible

### Requirement: Multi-Payment Workflow Guidance
When a customer opts to pay with multiple payment methods on a single transaction, cashiers must process non-cash transactions (e.g., card, transfer) before accepting cash. The system should provide an educational note directly within the payment composer to enforce this practice.

#### Scenario: Cashier Initiates Multi-Payment
- **WHEN** the cashier opens the payment modal (staged checkout or standard checkout)
- **THEN** a visible note should be displayed reading "Catatan: Untuk multi payment, silakan masukkan pembayaran non-tunai (transfer/debit/kredit) terlebih dahulu, dan pembayaran tunai (cash) di akhir."
- **AND** the note should be positioned prominently below the payment method selection field
