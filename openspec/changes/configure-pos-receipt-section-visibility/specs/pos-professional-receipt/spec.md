## MODIFIED Requirements

### Requirement: Receipt footer tax notice is below tail dash
When PPN visibility is enabled for the receipt business, the POS receipt SHALL render `Harga sudah termasuk PPN` below the receipt tail dash line when signature space is visible, or in its existing footer position without a reserved stamp gap when signature space is hidden in a very small font.

#### Scenario: Tax notice placement
- **WHEN** a POS receipt or reprint receipt is rendered
- **AND** PPN visibility is enabled for the receipt business
- **THEN** the text `Harga sudah termasuk PPN` MUST appear in the footer, below the dashed tail line when that line is present
- **AND** the text MUST use a font smaller than the normal receipt line item font

#### Scenario: Tax notice is customer-facing footer text
- **WHEN** the receipt is printed
- **AND** PPN visibility is enabled for the receipt business
- **THEN** the tax notice MUST be included in the printed output
- **AND** it MUST NOT appear above the dashed tail line when that line is present

#### Scenario: PPN notice is hidden independently
- **WHEN** the receipt business has PPN visibility disabled
- **THEN** the PPN notice and its own reserved spacing are omitted
- **AND** the thank-you message and receipt number remain visible

## ADDED Requirements

### Requirement: Stamp space and closing footer have independent visibility
The existing 28 mm stamp/signature blank space SHALL render only when its business visibility setting is enabled, which is the default. Disabling it SHALL remove the blank area and associated divider/margins without suppressing independently enabled PPN content. The thank-you message and receipt number SHALL remain visible regardless of all five optional section switches. Existing content order and thermal width SHALL remain unchanged, and hidden sections MUST NOT leave their own empty headings, dividers or reserved gaps.

#### Scenario: Signature space disabled while PPN enabled
- **WHEN** stamp/signature space is disabled and PPN information is enabled
- **THEN** the 28 mm spacer and its associated separator gap are absent
- **AND** the PPN notice, thank-you message and receipt number remain visible in their existing order

#### Scenario: Default receipt closing
- **WHEN** a receipt uses the agreed default business visibility settings
- **THEN** the stamp/signature blank space remains visible
- **AND** the PPN notice is hidden while thank-you text and receipt number remain visible
