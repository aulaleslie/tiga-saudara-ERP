## MODIFIED Requirements

### Requirement: Receipt return history includes completed effective returns
The following display behavior SHALL apply only when the current receipt business has Riwayat Retur visibility enabled. When disabled, the associated explanatory content SHALL be omitted without changing underlying transaction calculations or moving content elsewhere. Existing historical and preview content eligibility SHALL still apply.
The receipt SHALL list every completed, non-reversed return in execution order with a stable tie-break, including reference, completion date, completed status, product, returned quantity, resolution, and actual cash-refund amount or replacement details. Rows with no return action (`resolution = none`) SHALL NOT appear as returned items, even when they retain a positive quantity or serial identity. This exclusion SHALL apply to existing persisted records as well as future records and SHALL NOT change stored return evidence, purchase quantities, serial replacement lineage, or settlement amounts. Serial replacements SHALL identify both returned and replacement serials. Cash-return details SHALL distinguish the purchase-value reduction, debt reduction, and actual refund so a debt offset is not described as cash paid. Draft, pending, approval-only, incomplete, rejected, cancelled, archived, reversed, and deleted returns SHALL NOT contribute return adjustments or appear as completed history.

#### Scenario: Multiple mixed returns
- **WHEN** a receipt has multiple completed cash returns and replacements alongside a pending return
- **AND** the corresponding return-history visibility setting is enabled for the receipt business
- **THEN** all completed effective returns appear once with their individual details and the main purchase reflects their combined effect
- **AND** the pending return has no receipt adjustment or completed-history entry

#### Scenario: Approval alone is insufficient
- **WHEN** a return is approved but its execution is not completed
- **AND** the corresponding return-history visibility setting is enabled for the receipt business
- **THEN** no completed return history or corresponding quantity or serial adjustment is shown

#### Scenario: Replacement followed by an unrelated partial cash return
- **WHEN** a completed laptop replacement is followed by a completed one-unit photo-paper cash return whose saved lines also contain the laptop with no return action
- **AND** the corresponding return-history visibility setting is enabled for the receipt business
- **THEN** the first history entry shows the laptop replacement with its returned and replacement serials
- **AND** the second entry shows only the one-unit photo-paper cash return with its saved settlement values
- **AND** five originally purchased photo-paper units leave four in the main purchase list
- **AND** the laptop remains in the main purchase list with its effective replacement serial

#### Scenario: Positive quantity and serial do not establish a return action
- **WHEN** an existing completed return contains a no-action row with positive quantity and a serial alongside actual cash-return or replacement rows
- **AND** the corresponding return-history visibility setting is enabled for the receipt business
- **THEN** that no-action row is omitted from receipt return-history items
- **AND** the actual return rows retain their quantities, values, and serial details
- **AND** receipt generation leaves persisted return records unchanged

### Requirement: Receipt displays the existing POS transaction note
The following display behavior SHALL apply only when the current receipt business has Catatan visibility enabled. When disabled, the associated explanatory content SHALL be omitted without changing underlying transaction calculations or moving content elsewhere. Existing historical and preview content eligibility SHALL still apply.
POS receipts SHALL display the existing POS transaction note as `Catatan` after payments and before return history, or before the tail space when no return history exists. The note SHALL be rendered as escaped plain text with preserved line breaks and wrapping within the thermal width. Null, empty and whitespace-only notes SHALL omit the section. Linked Sales notes and checkout notes MUST NOT be substituted. Current, explicit historical, draft and loaded receipts SHALL use the note of their resolved POS transaction; receipts without a provable source transaction SHALL omit it.

#### Scenario: Multiline note is printed safely
- **WHEN** the source POS transaction has a multiline note containing HTML characters
- **AND** the corresponding note visibility setting is enabled for the receipt business
- **THEN** the receipt prints the literal text with its line breaks under `Catatan` after payments and before any return history
- **AND** the text does not execute as markup or overflow the receipt width

#### Scenario: Empty note or missing source transaction
- **WHEN** the transaction note is whitespace-only or no source POS transaction can be resolved
- **AND** the corresponding note visibility setting is enabled for the receipt business
- **THEN** no `Catatan` heading or placeholder is printed
- **AND** an internal linked Sales note is not printed as a substitute

#### Scenario: Historical and preview notes
- **WHEN** an explicit historical receipt or draft or loaded receipt is rendered
- **AND** the corresponding note visibility setting is enabled for the receipt business
- **THEN** it includes the existing source POS transaction note using the same placement and escaping rules
- **AND** this does not claim that the note is an immutable checkout-time snapshot

### Requirement: Current receipts explain proven transaction unit price adjustments
The following display behavior SHALL apply only when the current receipt business has price-adjustment information visibility enabled. When disabled, the associated explanatory content SHALL be omitted without changing underlying transaction calculations or moving content elsewhere. Existing historical and preview content eligibility SHALL still apply.
Ordinary completed receipts SHALL compare the original completed-checkout unit selling price with the current persisted unit selling price of proven linked Sales details. Changes to catalog prices MUST NOT affect transaction prices or adjustment information. Comparisons SHALL use stored currency precision, preserve the original selling-unit basis where provable, and appear below each affected standard item as original to current prices. Split Sales SHALL show each distinct current price with its corresponding remaining customer quantity without exposing owner or Sales document identifiers. Discounts, taxes, shipping, quantity changes and return effects alone MUST NOT trigger a unit-price notice. Adjustment information SHALL be explanatory and MUST NOT change financial calculations. Missing explanatory provenance SHALL omit the numeric comparison, while existing refusal and historical fallback rules SHALL continue to apply to ambiguous underlying financial projections.

#### Scenario: Standard item price changes on linked Sales
- **WHEN** a standard item's checkout unit price was Rp100,000 and its proven linked Sales unit price becomes Rp90,000
- **AND** the corresponding price-adjustment visibility setting is enabled for the receipt business
- **THEN** the current receipt displays `Harga satuan: 100.000 → 90.000` beneath the affected item in its original selling unit

#### Scenario: Split Sales have distinct current prices
- **WHEN** a standard item has two remaining units at Rp90,000 and one at Rp95,000 across proven linked Sales, against an original unit price of Rp100,000
- **AND** the corresponding price-adjustment visibility setting is enabled for the receipt business
- **THEN** the receipt shows both price comparisons with quantities two and one respectively
- **AND** it does not replace them with a weighted average or count return execution rows as additional purchased units

#### Scenario: Catalog or non-price values change
- **WHEN** catalog prices, discounts, tax, shipping or returned quantities change without changing the persisted transaction unit selling price
- **AND** the corresponding price-adjustment visibility setting is enabled for the receipt business
- **THEN** no unit-price adjustment notice appears
- **AND** authoritative transaction charges and return details continue to render according to their existing requirements

#### Scenario: Repeated edits and currency precision
- **WHEN** a transaction is edited repeatedly or compared values differ only below stored currency precision
- **AND** the corresponding price-adjustment visibility setting is enabled for the receipt business
- **THEN** the comparison remains against completed checkout rather than the previous print
- **AND** no notice appears if the current price equals the checkout price at stored currency precision

### Requirement: Packed price notices do not invent selling unit prices
The following display behavior SHALL apply only when the current receipt business has price-adjustment information visibility enabled. When disabled, the associated explanatory content SHALL be omitted without changing underlying transaction calculations or moving content elsewhere. Existing historical and preview content eligibility SHALL still apply.
For a packed item whose separate current box and loose prices cannot be proven, a proven change in its persisted blended unit selling price SHALL display `Harga penjualan diperbarui` beneath the item. The receipt SHALL retain existing packing presentation without claiming reconstructed box or loose prices are an exact original-to-current comparison. Allocated totals or return quantity changes alone MUST NOT establish a blended unit-price change.

#### Scenario: Packed blended price is adjusted
- **WHEN** a packed item's current linked Sales blended unit price differs from its completed-checkout blended unit price and no separate current selling-unit prices are persisted
- **AND** the corresponding price-adjustment visibility setting is enabled for the receipt business
- **THEN** the receipt displays `Harga penjualan diperbarui`
- **AND** no numeric box or loose before-and-after comparison is fabricated

### Requirement: Fully returned item comparisons use the price at return
The following display behavior SHALL apply only when the current receipt business has price-adjustment information and Riwayat Retur visibility enabled. When disabled, the associated explanatory content SHALL be omitted without changing underlying transaction calculations or moving content elsewhere. Existing historical and preview content eligibility SHALL still apply.
For fully returned standard items omitted from the main purchase list, return history SHALL show checkout price to saved price at return when both prices and their matching unit basis are proven and differ. The endpoint SHALL be labeled as the price at return, not the latest Sales price. Later Sales edits MUST NOT rewrite the meaning of settled return values. Missing lineage, zeroed bundle allocations and unsupported packed price decomposition MUST NOT produce fabricated numeric comparisons.

#### Scenario: Fully returned standard item retains its comparison
- **WHEN** an item bought at Rp100,000 was fully returned with a saved return unit price of Rp90,000
- **AND** the corresponding price-adjustment and return-history visibility setting is enabled for the receipt business
- **THEN** its return-history entry displays `Harga satuan saat retur: 100.000 → 90.000`
- **AND** a later Sales edit does not change that return-price endpoint

#### Scenario: Return price basis is not provable
- **WHEN** a return row lacks a provable matching original selling unit or represents a zeroed internal bundle allocation
- **AND** the corresponding price-adjustment and return-history visibility setting is enabled for the receipt business
- **THEN** no numeric original-to-return unit-price comparison is printed
- **AND** existing return details remain available without billing internal allocations separately

## ADDED Requirements

### Requirement: Receipt visibility controls presentation only
All existing requirements to display notes, return-history details and price-adjustment explanations SHALL be conditional on their business visibility settings. These switches MUST NOT change receipt calculations, financial validation, return effects, effective serials, remaining quantities, updated transaction prices, totals, payments or outstanding debt. Price-adjustment visibility SHALL control standard and packed notices and return-time price comparisons in their existing locations; return-time comparisons SHALL additionally require their return-history container to be visible. Hidden content MUST NOT be moved into another section. Enabling a section SHALL preserve existing content eligibility, formatting, placement and escaping. It MUST NOT add current adjustments to explicit historical receipts or completed-price comparisons to draft/loaded previews.

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
