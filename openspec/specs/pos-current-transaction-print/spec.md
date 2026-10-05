# pos-current-transaction-print Specification

## Purpose

Allows an authorized POS user to print the current customer-facing draft receipt with a real transaction number while retaining the transaction in the active cart for continued editing.

## Requirements

### Requirement: Current transaction can be saved and printed without leaving the cart
The system SHALL provide a **Simpan dan Cetak** action that persists the current nonempty POS cart as a draft, retains that draft as the active transaction in the same POS session, and makes its existing draft receipt available for printing. The action SHALL NOT finalize checkout or post a sale.

#### Scenario: First print from a new cart
- **WHEN** an eligible user clicks **Simpan dan Cetak** on a nonempty cart with no active transaction
- **THEN** the system creates a draft transaction with a transaction code, leaves it loaded in the current POS cart, and opens that transaction's draft receipt for printing

#### Scenario: Print after editing a loaded draft
- **WHEN** an eligible user clicks **Simpan dan Cetak** after editing the current loaded draft
- **THEN** the system saves those edits to the same transaction and opens its updated draft receipt with the same transaction code

#### Scenario: Empty cart
- **WHEN** an eligible user attempts **Simpan dan Cetak** with no cart lines
- **THEN** the system rejects the action with a Bahasa Indonesia message and does not open a receipt

### Requirement: Current transaction print requires three permissions
The system SHALL require `pos.transactions.save`, `pos.transactions.load`, and `pos.transactions.print-current` for the combined action. It SHALL enforce all three permissions on the server and expose the action on the POS screen only to eligible users.

#### Scenario: One permission is missing
- **WHEN** a user lacks any one of the three permissions
- **THEN** the server rejects the combined action without saving the cart or producing a print receipt
- **AND** the POS screen does not present an enabled **Simpan dan Cetak** action

### Requirement: Print uses the existing draft receipt presentation
The printed document SHALL use the existing POS draft receipt layout and transaction snapshot, including the persisted transaction code. **Simpan dan Cetak** SHALL open that receipt in a new browser tab through the dedicated current-transaction print route. All new user-facing labels and messages SHALL be in Bahasa Indonesia.

#### Scenario: Receipt is opened after save
- **WHEN** saving and retaining the draft succeeds
- **THEN** a new browser tab shows the same draft receipt presentation used for a manually saved draft, with current saved lines, totals, and transaction code
- **AND** the browser print dialog does not open automatically
- **AND** the cashier can print from the receipt page using **Cetak Struk**

### Requirement: Simpan dan Cetak SHALL NOT start printing automatically
Opening the current transaction receipt after **Simpan dan Cetak** SHALL leave the browser print dialog closed until the cashier explicitly chooses to print.

#### Scenario: Receipt tab closed without printing
- **WHEN** the cashier closes the receipt tab without clicking **Cetak Struk**
- **THEN** the POS screen remains usable without reload
- **AND** opening **Cari Produk** allows its keyword input to receive focus and typing

### Requirement: Print flow handles failure and duplicate activation
The POS screen SHALL prevent repeated activation while one save-and-print request is running. It SHALL NOT show a receipt from a failed save or load, and SHALL show a Bahasa Indonesia error when the operation fails.

#### Scenario: Save or retain fails
- **WHEN** the combined operation fails before a printable draft is available
- **THEN** no receipt is printed, the POS screen reports the failure, and the user can retry after resolving it

#### Scenario: Double click
- **WHEN** the user activates **Simpan dan Cetak** repeatedly while its first request is pending
- **THEN** only one save-and-print request is submitted by that screen
