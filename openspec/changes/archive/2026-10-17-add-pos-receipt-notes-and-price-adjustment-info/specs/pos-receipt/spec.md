## ADDED Requirements

### Requirement: Receipt displays the existing POS transaction note
POS receipts SHALL display the existing POS transaction note as `Catatan` after payments and before return history, or before the tail space when no return history exists. The note SHALL be rendered as escaped plain text with preserved line breaks and wrapping within the thermal width. Null, empty and whitespace-only notes SHALL omit the section. Linked Sales notes and checkout notes MUST NOT be substituted. Current, explicit historical, draft and loaded receipts SHALL use the note of their resolved POS transaction; receipts without a provable source transaction SHALL omit it.

#### Scenario: Multiline note is printed safely
- **WHEN** the source POS transaction has a multiline note containing HTML characters
- **THEN** the receipt prints the literal text with its line breaks under `Catatan` after payments and before any return history
- **AND** the text does not execute as markup or overflow the receipt width

#### Scenario: Empty note or missing source transaction
- **WHEN** the transaction note is whitespace-only or no source POS transaction can be resolved
- **THEN** no `Catatan` heading or placeholder is printed
- **AND** an internal linked Sales note is not printed as a substitute

#### Scenario: Historical and preview notes
- **WHEN** an explicit historical receipt or draft or loaded receipt is rendered
- **THEN** it includes the existing source POS transaction note using the same placement and escaping rules
- **AND** this does not claim that the note is an immutable checkout-time snapshot

### Requirement: Current receipts explain proven transaction unit price adjustments
Ordinary completed receipts SHALL compare the original completed-checkout unit selling price with the current persisted unit selling price of proven linked Sales details. Changes to catalog prices MUST NOT affect transaction prices or adjustment information. Comparisons SHALL use stored currency precision, preserve the original selling-unit basis where provable, and appear below each affected standard item as original to current prices. Split Sales SHALL show each distinct current price with its corresponding remaining customer quantity without exposing owner or Sales document identifiers. Discounts, taxes, shipping, quantity changes and return effects alone MUST NOT trigger a unit-price notice. Adjustment information SHALL be explanatory and MUST NOT change financial calculations. Missing explanatory provenance SHALL omit the numeric comparison, while existing refusal and historical fallback rules SHALL continue to apply to ambiguous underlying financial projections.

#### Scenario: Standard item price changes on linked Sales
- **WHEN** a standard item's checkout unit price was Rp100,000 and its proven linked Sales unit price becomes Rp90,000
- **THEN** the current receipt displays `Harga satuan: 100.000 → 90.000` beneath the affected item in its original selling unit

#### Scenario: Split Sales have distinct current prices
- **WHEN** a standard item has two remaining units at Rp90,000 and one at Rp95,000 across proven linked Sales, against an original unit price of Rp100,000
- **THEN** the receipt shows both price comparisons with quantities two and one respectively
- **AND** it does not replace them with a weighted average or count return execution rows as additional purchased units

#### Scenario: Catalog or non-price values change
- **WHEN** catalog prices, discounts, tax, shipping or returned quantities change without changing the persisted transaction unit selling price
- **THEN** no unit-price adjustment notice appears
- **AND** authoritative transaction charges and return details continue to render according to their existing requirements

#### Scenario: Repeated edits and currency precision
- **WHEN** a transaction is edited repeatedly or compared values differ only below stored currency precision
- **THEN** the comparison remains against completed checkout rather than the previous print
- **AND** no notice appears if the current price equals the checkout price at stored currency precision

### Requirement: Packed price notices do not invent selling unit prices
For a packed item whose separate current box and loose prices cannot be proven, a proven change in its persisted blended unit selling price SHALL display `Harga penjualan diperbarui` beneath the item. The receipt SHALL retain existing packing presentation without claiming reconstructed box or loose prices are an exact original-to-current comparison. Allocated totals or return quantity changes alone MUST NOT establish a blended unit-price change.

#### Scenario: Packed blended price is adjusted
- **WHEN** a packed item's current linked Sales blended unit price differs from its completed-checkout blended unit price and no separate current selling-unit prices are persisted
- **THEN** the receipt displays `Harga penjualan diperbarui`
- **AND** no numeric box or loose before-and-after comparison is fabricated

### Requirement: Fully returned item comparisons use the price at return
For fully returned standard items omitted from the main purchase list, return history SHALL show checkout price to saved price at return when both prices and their matching unit basis are proven and differ. The endpoint SHALL be labeled as the price at return, not the latest Sales price. Later Sales edits MUST NOT rewrite the meaning of settled return values. Missing lineage, zeroed bundle allocations and unsupported packed price decomposition MUST NOT produce fabricated numeric comparisons.

#### Scenario: Fully returned standard item retains its comparison
- **WHEN** an item bought at Rp100,000 was fully returned with a saved return unit price of Rp90,000
- **THEN** its return-history entry displays `Harga satuan saat retur: 100.000 → 90.000`
- **AND** a later Sales edit does not change that return-price endpoint

#### Scenario: Return price basis is not provable
- **WHEN** a return row lacks a provable matching original selling unit or represents a zeroed internal bundle allocation
- **THEN** no numeric original-to-return unit-price comparison is printed
- **AND** existing return details remain available without billing internal allocations separately

### Requirement: Adjusted debt receipts reconcile with authoritative settlement
After an authorized linked Sales price adjustment, ordinary completed receipts SHALL show the current transaction total, effective retained paid amount, payment-method amounts and outstanding debt reconciled with persisted settlement evidence, including later payments and completed returns. Receipt generation MUST NOT mutate payments, create refunds or rewrite checkout snapshots. Unsupported financial evidence SHALL retain existing actionable refusal and historical access behavior. All ordinary completed print and reprint entry points SHALL produce equivalent results.

#### Scenario: Price adjustment reduces unpaid debt
- **WHEN** a Rp300,000 POS transaction has Rp100,000 paid and an authorized linked Sales price adjustment reduces its total to Rp280,000
- **THEN** the current receipt shows total Rp280,000, paid Rp100,000 and outstanding debt Rp180,000 with corresponding price-adjustment information
- **AND** original checkout snapshots and payment records remain unchanged by printing

#### Scenario: Later payment after price adjustment
- **WHEN** a further Rp50,000 payment is posted to that adjusted transaction
- **THEN** each ordinary completed receipt path shows total Rp280,000, paid Rp150,000 and outstanding debt Rp130,000
- **AND** displayed payment-method amounts reconcile to the effective paid amount

#### Scenario: Historical receipt excludes adjustment information
- **WHEN** the operator explicitly opens a historical receipt after Sales prices or balances change
- **THEN** it retains original checkout prices, quantities, totals, tender and change with its historical indication
- **AND** it displays the existing POS transaction note but no current price-adjustment comparisons or packed-price notices

#### Scenario: Preview does not compare against current Sales
- **WHEN** a draft or loaded receipt is printed
- **THEN** it retains existing preview pricing behavior and does not display completed transaction price-adjustment notices
