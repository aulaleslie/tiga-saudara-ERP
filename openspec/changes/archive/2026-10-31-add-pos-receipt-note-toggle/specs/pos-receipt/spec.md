# Spec Delta

## MODIFIED Requirements

### Requirement: Receipt displays the existing POS transaction note
The following display behavior SHALL apply when Catatan visibility is enabled by the receipt-page checkbox, whose initial value follows the current receipt business setting. When disabled, the associated explanatory content SHALL be omitted without changing underlying transaction calculations or moving content elsewhere. Existing historical and preview content eligibility SHALL still apply.
POS receipts SHALL display the existing POS transaction note as `Catatan` after payments and before return history, or before the tail space when no return history exists. The note SHALL be rendered as escaped plain text with preserved line breaks and wrapping within the thermal width. Null, empty and whitespace-only notes SHALL omit the section. Linked Sales notes and checkout notes MUST NOT be substituted. Current, explicit historical, draft and loaded receipts SHALL use the note of their resolved POS transaction; receipts without a provable source transaction SHALL omit it.

#### Scenario: Multiline note is printed safely
- **WHEN** the source POS transaction has a multiline note containing HTML characters
- **AND** the effective receipt-page note visibility is enabled
- **THEN** the receipt prints the literal text with its line breaks under `Catatan` after payments and before any return history
- **AND** the text does not execute as markup or overflow the receipt width

#### Scenario: Empty note or missing source transaction
- **WHEN** the transaction note is whitespace-only or no source POS transaction can be resolved
- **AND** the effective receipt-page note visibility is enabled
- **THEN** no `Catatan` heading or placeholder is printed
- **AND** an internal linked Sales note is not printed as a substitute

#### Scenario: Historical and preview notes
- **WHEN** an explicit historical receipt or draft or loaded receipt is rendered
- **AND** the effective receipt-page note visibility is enabled
- **THEN** it includes the existing source POS transaction note using the same placement and escaping rules
- **AND** this does not claim that the note is an immutable checkout-time snapshot

### Requirement: Receipt visibility controls presentation only
All existing requirements to display notes, return-history details and price-adjustment explanations SHALL be conditional on their business visibility settings, with the receipt-page checkbox overriding only note visibility for the open page. These switches MUST NOT change receipt calculations, financial validation, return effects, effective serials, remaining quantities, updated transaction prices, totals, payments or outstanding debt. Price-adjustment visibility SHALL control standard and packed notices and return-time price comparisons in their existing locations; return-time comparisons SHALL additionally require their return-history container to be visible. Hidden content MUST NOT be moved into another section. Enabling a section SHALL preserve existing content eligibility, formatting, placement and escaping. It MUST NOT add current adjustments to explicit historical receipts or completed-price comparisons to draft/loaded previews.

#### Scenario: Hidden explanations preserve current purchase
- **WHEN** a current receipt contains updated Sales prices and completed return effects with all explanatory visibility switches disabled
- **THEN** its purchase lines, effective serials, total, paid amount and debt match the same receipt with those switches enabled
- **AND** note, return history and price-adjustment text are absent without changing financial validation or print audit behavior

#### Scenario: Price information and return history are independently hidden
- **WHEN** return history is enabled but price-adjustment information is disabled
- **THEN** eligible return history appears without return-time price comparisons
- **AND** disabling return history while enabling price-adjustment information does not relocate return-time comparisons into purchase items

#### Scenario: Hidden section leaves no residue
- **WHEN** an optional explanatory section is disabled or lacks eligible content
- **THEN** its heading, section-specific divider and reserved gap are omitted
- **AND** the remaining receipt content retains its existing order

## ADDED Requirements

### Requirement: Receipt pages offer a local transaction note checkbox
Every POS receipt page with Cetak Struk SHALL offer a labeled Tampilkan Catatan Transaksi checkbox alongside that button, including checkout, transaction print/reprint, global-payment, historical, draft/loaded and Kas Bon collection receipts. Its state SHALL immediately control note visibility on screen and paper. The checkbox and its label MUST NOT print.

#### Scenario: Show and hide a nonempty note
- **WHEN** a cashier checks or unchecks Tampilkan Catatan Transaksi on an open receipt containing a note
- **THEN** the note section immediately appears or disappears in the preview
- **AND** printing through Cetak Struk or the browser uses that same visibility
- **AND** hidden notes leave no heading, divider or reserved gap
- **AND** the control and label are absent from print output

#### Scenario: No eligible transaction note
- **WHEN** a receipt has no resolved source POS transaction or its note is null, empty or whitespace-only
- **THEN** the checkbox remains available with the business default
- **AND** checking it does not display a note heading or placeholder

#### Scenario: Local choice does not change unrelated receipt content
- **WHEN** a cashier toggles the note checkbox
- **THEN** all other receipt visibility choices, purchase facts, totals, payments, debt and print audit behavior remain unchanged
- **AND** no print or reprint audit event is created by toggling

### Requirement: Kas Bon collection receipts use the linked transaction note
Kas Bon collection print and reprint receipts SHALL use the linked POS transaction's note for Catatan Transaksi and the collection business's current note visibility default. They MUST NOT substitute collection notes, Sales notes or checkout notes. Notes SHALL use escaped plain text with preserved line breaks and thermal-width wrapping, after payment/debt details and before the footer.

#### Scenario: Print a collection receipt with transaction notes
- **WHEN** a Kas Bon collection receipt is printed or reprinted with a nonempty linked POS transaction note and note visibility enabled
- **THEN** it shows that transaction note using the same safe formatting as other POS receipts
- **AND** the collection payment snapshot and balances remain unchanged

#### Scenario: Collection has no eligible transaction note
- **WHEN** a collection has a missing linked transaction or a blank transaction note, even if collection notes exist
- **THEN** its receipt omits the entire Catatan section regardless of checkbox state
