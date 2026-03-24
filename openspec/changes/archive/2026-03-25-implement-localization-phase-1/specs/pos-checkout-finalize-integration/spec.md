## ADDED Requirements

### Requirement: Indonesian Error Messages for Checkout
Exception messages in the POS checkout finalization flow must be in Bahasa Indonesia.

#### Scenario: Empty Cart Error
- **WHEN** Finalizing checkout with an empty cart
- **THEN** The system returns 'Keranjang harus berisi setidaknya satu item baris.' instead of 'Cart must contain at least one line item.'

#### Scenario: Invalid POS Session Error
- **WHEN** Finalizing checkout with an invalid session context
- **THEN** The system returns 'Konteks sesi POS yang aktif tidak valid.' instead of 'Active POS session context is invalid.'
