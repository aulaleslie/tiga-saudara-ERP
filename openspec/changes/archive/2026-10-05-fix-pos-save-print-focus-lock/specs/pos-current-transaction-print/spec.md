# Spec Delta

## MODIFIED Requirements

### Requirement: Print uses the existing draft receipt presentation
The printed document SHALL use the existing POS draft receipt layout and transaction snapshot, including the persisted transaction code. **Simpan dan Cetak** SHALL open that receipt in a new browser tab through the dedicated current-transaction print route. All new user-facing labels and messages SHALL be in Bahasa Indonesia.

#### Scenario: Receipt is opened after save
- **WHEN** saving and retaining the draft succeeds
- **THEN** a new browser tab shows the same draft receipt presentation used for a manually saved draft, with current saved lines, totals, and transaction code
- **AND** the browser print dialog does not open automatically
- **AND** the cashier can print from the receipt page using **Cetak Struk**

## ADDED Requirements

### Requirement: Simpan dan Cetak SHALL NOT start printing automatically
Opening the current transaction receipt after **Simpan dan Cetak** SHALL leave the browser print dialog closed until the cashier explicitly chooses to print.

#### Scenario: Receipt tab closed without printing
- **WHEN** the cashier closes the receipt tab without clicking **Cetak Struk**
- **THEN** the POS screen remains usable without reload
- **AND** opening **Cari Produk** allows its keyword input to receive focus and typing
