## ADDED Requirements

### Requirement: Scan resolution SHALL route bundle parents through bundle intent capture
When scan resolution identifies a product that is a bundle parent, the POS scan action SHALL route the result through bundle intent capture before appending serial numbers, incrementing quantity, or otherwise choosing a target cart line.

#### Scenario: Product barcode scan for bundle parent opens bundle selection
- **WHEN** the cashier scans a product barcode that resolves to a bundle-parent product
- **THEN** the scan flow MUST open bundle selection or explicit no-bundle continuation before mutating the cart

#### Scenario: Serial scan for bundle parent preserves serial through bundle choice
- **WHEN** the cashier scans a serial number that resolves to a bundle-parent product
- **THEN** the scan flow MUST preserve the scanned serial while collecting bundle intent
- **AND** after the cashier chooses a bundle or no-bundle continuation, the serial MUST be appended only to the matching bundle-aware cart row

#### Scenario: Existing product row does not bypass bundle selection
- **WHEN** the cashier scans a code for a bundle-parent product and the cart already contains one or more rows for that product
- **THEN** the scan flow MUST NOT choose a target row by product id alone
- **AND** the scan flow MUST collect bundle intent before selecting the target row

#### Scenario: Non-bundle product scan remains direct
- **WHEN** the cashier scans a code that resolves to a product that is not a bundle parent
- **THEN** the scan flow MAY use the existing direct add or serial append behavior without showing bundle selection
