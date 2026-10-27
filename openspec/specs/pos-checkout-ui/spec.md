## Requirements

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

### Requirement: Checkout actions SHALL emphasize full payment
The payment panel SHALL show Bayar Lunas as the larger primary action with Tunai / Transfer / Multi Pembayaran supporting text. Simpan dan Buka Baru, Simpan dan Cetak, and Kasbon SHALL form a vertical secondary stack beside it when space permits, adapting to narrow screens.

#### Scenario: All actions authorized
- **WHEN** all four actions are authorized
- **THEN** Bayar Lunas occupies the prominent area beside the three vertically stacked secondary actions

#### Scenario: Narrow viewport
- **WHEN** the panel cannot accommodate adjacent columns
- **THEN** visible actions remain readable and usable without clipping or horizontal overflow

### Requirement: Checkout actions SHALL adapt to permission visibility
Actions without permission SHALL be hidden in the panel and modal, with no empty reserved slots. Authorized actions with unmet prerequisites MAY remain visible but disabled. Server authorization SHALL remain authoritative.

#### Scenario: Some secondary actions unavailable
- **WHEN** a user lacks permission for one or more secondary actions
- **THEN** those actions are absent and the remaining stack closes its gaps

#### Scenario: Only full payment available
- **WHEN** Bayar Lunas is the only authorized action
- **THEN** it fills the available action area

#### Scenario: Primary action unavailable
- **WHEN** full payment is unavailable or no checkout action is authorized
- **THEN** permitted save actions remain tidy without an empty primary column or unauthorized flow switch

#### Scenario: Empty cart and forged request
- **WHEN** an authorized cashier has an empty cart or an unauthorized user directly requests checkout
- **THEN** cart prerequisites prevent checkout and server authorization rejects the unauthorized request respectively

