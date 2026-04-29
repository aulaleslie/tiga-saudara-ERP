## ADDED Requirements

### Requirement: Purchase-context product quick-add SHALL expose sale pricing after sellable is enabled
When a user opens the shared product quick-add modal from a purchase page, the modal SHALL allow the user to convert the new product from purchase-only to sellable without leaving the flow.

#### Scenario: Purchase quick-add reveals sale pricing when sellable is enabled
- **WHEN** a user opens product quick-add from purchase create or purchase edit
- **AND** the modal starts with `Saya Jual Barang Ini` unchecked
- **AND** the user enables `Saya Jual Barang Ini`
- **THEN** the modal SHALL display the selling-price controls for `Harga Jual`, `Harga Jual Partai Besar`, `Harga Jual Reseller`, and `Pajak Jual`
- **AND** the user SHALL be able to enter sale-pricing data before saving the product

#### Scenario: Purchase quick-add hides inactive sale pricing when sellable is disabled
- **WHEN** a user opens product quick-add from purchase create or purchase edit
- **AND** the user enables `Saya Jual Barang Ini`
- **AND** the user later disables `Saya Jual Barang Ini`
- **THEN** the sale-pricing controls SHALL return to their inactive state
- **AND** the modal SHALL NOT present the product as currently configured for sale
