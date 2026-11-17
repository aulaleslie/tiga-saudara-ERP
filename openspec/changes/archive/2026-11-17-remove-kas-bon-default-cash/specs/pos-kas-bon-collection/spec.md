# Spec Delta

## MODIFIED Requirements

### Requirement: Checkout-style single-transaction payment
The payment UI SHALL accept one selected POS transaction and derive the payment amount directly from the sum of payment method amounts (Jumlah), which must be greater than zero and no greater than live due. It SHALL support existing cash, non-cash, and mixed payment methods with applicable evidence rules, localized monetary inputs for Jumlah, and free-text Keterangan for every method (including CASH). In the Keterangan column, every method row SHALL accept optional free text without monetary formatting or tender calculations. For Transfer and non-cash methods, the UI SHALL provide a separate Nomor Referensi text input, required according to the payment method's existing rules, while preserving evidence upload. Method Keterangan and transfer reference SHALL be persisted separately without mutual substitution. Uang Tunai Diterima and Kembalian Tunai SHALL be removed from this flow; the entered cash Jumlah SHALL be treated as cash received and applied, with zero change. Adding or removing payment method rows SHALL preserve all previously entered amounts, descriptions, references, and uploaded evidence. The UI SHALL display Total Pembayaran and Sisa Hutang Setelah Pembayaran without requiring a separate payment amount input. It SHALL provide no finish-as-Kas-Bon option. Each newly opened transaction review SHALL start with no selected payment methods and a blank method picker; CASH SHALL NOT be added automatically.

#### Scenario: Partial cash payment with free-text Keterangan
- **WHEN** live due is Rp150,000, cash Jumlah is Rp50,000, and Keterangan is "Cicilan kedua"
- **THEN** the debt reduces by Rp50,000, remaining debt is Rp100,000, change is zero, drawer cash increases by exactly Rp50,000, and the description "Cicilan kedua" is recorded without affecting calculations

#### Scenario: Mixed payment with separate description and transfer reference
- **WHEN** Rp100,000 is collected with Rp60,000 cash (Keterangan: "Cicilan tunai") and Rp40,000 transfer (Keterangan: "Titipan BCA", Nomor Referensi: "TRF-987654")
- **THEN** settlement records both methods, stores Keterangan and reference separately without substitution, and only Rp60,000 increases expected drawer cash

#### Scenario: Invalid amount or missing required reference
- **WHEN** amount to pay is non-positive, exceeds live due, or a method requiring reference lacks a valid reference
- **THEN** submission is rejected without settlement or session entries

#### Scenario: Review starts without a payment method
- **WHEN** the cashier opens a Kas Bon transaction review with positive live due
- **THEN** no payment method rows are selected and the method picker shows its empty placeholder
- **AND** Total Pembayaran is Rp0, Sisa Hutang Setelah Pembayaran equals the full live due, and submission is disabled

#### Scenario: Cashier explicitly adds a method
- **WHEN** the cashier chooses an available method and adds it to an empty payment form
- **THEN** only that method is added with its amount initialized to the live due
- **AND** existing amount, reference, and evidence validation applies before submission is enabled

#### Scenario: Another review starts empty
- **WHEN** the cashier opens another transaction review or closes and reopens collection after choosing or adding a payment method
- **THEN** the new review has no selected payment methods and a blank method picker
- **AND** payment total, remaining debt, and submission state reflect the new empty form

#### Scenario: Removing the last method restores the empty form
- **WHEN** the cashier removes the final selected payment method
- **THEN** Total Pembayaran is Rp0, the full live due remains, and submission is disabled
