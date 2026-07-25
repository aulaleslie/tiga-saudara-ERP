## ADDED Requirements

### Requirement: Receipt displays the POS transaction code prominently
The printed POS receipt SHALL display the POS transaction code in the receipt metadata block, with the same prominence previously given to the receipt number. The code SHALL be the same value recorded on the POS transaction and written into the generated Sale notes.

#### Scenario: Transaction code appears in the metadata block
- **WHEN** a POS receipt is rendered for a completed checkout
- **THEN** the metadata block displays a labelled POS transaction code row

#### Scenario: Receipt code matches the transaction record
- **WHEN** a POS receipt is rendered for a completed checkout
- **THEN** the displayed transaction code matches the code persisted on that checkout's POS transaction

#### Scenario: Checkout without a linked POS transaction
- **WHEN** a POS receipt is rendered for a checkout that has no linked POS transaction
- **THEN** the receipt renders without error and omits or neutrally fills the transaction code row

### Requirement: Receipt number is demoted to a de-emphasised footer
The printed POS receipt SHALL move the receipt number out of the metadata block into a small, visually de-emphasised element at the bottom of the receipt. The receipt number SHALL remain present and legible so that it can still be used to look up a return.

#### Scenario: Receipt number appears at the bottom
- **WHEN** a POS receipt is rendered
- **THEN** the receipt number appears in a de-emphasised element below the receipt footer text

#### Scenario: Receipt number no longer appears in the metadata block
- **WHEN** a POS receipt is rendered
- **THEN** the metadata block does not display the receipt number row

#### Scenario: Receipt number remains present
- **WHEN** a POS receipt is rendered for a checkout with a receipt number
- **THEN** the receipt number value is still printed on the receipt
