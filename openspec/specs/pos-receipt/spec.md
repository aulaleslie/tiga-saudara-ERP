## Purpose

POS receipts SHALL display transaction details and product serial numbers for customer and accounting records.

## Requirements

### Requirement: Print Assigned Serial Numbers on POS Receipt
The system SHALL display assigned serial numbers directly on the POS receipt for all products that require serial number tracking to ensure accurate warranty tracking and customer transparency.

#### Scenario: Transaction includes serialized product
- **WHEN** a transaction is completed and a receipt is generated
- **AND** the transaction includes a product line with assigned serial numbers
- **THEN** the receipt line item should display the serial numbers (e.g., "SN: XXXXXX")
- **AND** the serial numbers should be formatted neatly below the product name

### Requirement: Robust Customer Identity Display
The POS receipt SHALL display the customer's identity by intelligently combining `contact_name` and `company_name` (or `customer_name`) to provide maximum context. It MUST safely ignore empty strings (`""`) to ensure a blank name is not printed when one of the fields is empty but the other is present.

#### Scenario: Customer has both contact and company name
- **WHEN** a POS receipt is rendered
- **AND** the customer has both `contact_name` and `company_name` (or `customer_name`) defined as non-empty strings
- **THEN** the receipt SHALL display the customer as "Contact Name - Company Name"

#### Scenario: Customer has only company name
- **WHEN** a POS receipt is rendered
- **AND** the customer has an empty `contact_name` but a defined `company_name` (or `customer_name`)
- **THEN** the receipt SHALL display the customer as just "Company Name"

#### Scenario: Customer has only contact name
- **WHEN** a POS receipt is rendered
- **AND** the customer has a defined `contact_name` but empty `company_name` and `customer_name`
- **THEN** the receipt SHALL display the customer as just "Contact Name"

#### Scenario: Customer has no defined names
- **WHEN** a POS receipt is rendered
- **AND** the customer has empty strings for `contact_name`, `company_name`, and `customer_name`
- **THEN** the receipt SHALL display the customer as "-"

### Requirement: Receipt expresses packing split for conversion lines
The POS receipt SHALL express a packed line's unit breakdown as its packing split (number of boxes plus loose base units) rather than a single conversion-unit price, so the printed line reflects how the quantity was decomposed.

#### Scenario: Receipt shows box plus loose base unit split
- **WHEN** a packed line has quantity 6 decomposed into 1 box (factor 5) plus 1 loose base unit
- **THEN** the receipt line's unit breakdown reads as "1 box + 1 ream" (or the product's box and base unit names)

#### Scenario: Pure loose line shows base units only
- **WHEN** a packed line has quantity 3 with no full box group
- **THEN** the receipt line's unit breakdown shows only loose base units and no box

### Requirement: Packed receipt prices use correct Rupiah values and snapshotted unit labels
The POS receipt SHALL express packed line prices in Rupiah using snapshotted conversion and base unit labels without placeholder initials like `[K]` or `[P]`.

#### Scenario: Receipt shows box plus loose base-unit split with correct prices
- **WHEN** a packed line has quantity 6 decomposed into 1 DUS at Rp210,000 plus 1 RIM at Rp45,000
- **THEN** the receipt displays `1 DUS @ Rp210.000` and `1 RIM @ Rp45.000` using the snapshotted unit labels

#### Scenario: Pure packed line displays the configured conversion unit
- **WHEN** a packed line consists only of one or more full conversion groups
- **THEN** the receipt displays the configured conversion-unit label and does not display `[K]` or a hardcoded generic box label

#### Scenario: Pure loose line shows the configured base unit
- **WHEN** a packed line contains only loose base units
- **THEN** the receipt displays the full configured base-unit label and does not display a first-letter placeholder

#### Scenario: Packed price remains in Rupiah on completed receipt
- **WHEN** a completed transaction snapshot stores `box_price_applied = 21000000` minor units
- **THEN** the completed receipt displays Rp210,000 and MUST NOT display Rp21,000,000 for that breakdown price

#### Scenario: Packed price remains in Rupiah on draft receipt
- **WHEN** a draft or loaded transaction snapshot stores packed breakdown prices in minor units
- **THEN** its receipt preview applies the same unit labels and minor-to-Rupiah conversion as a completed receipt

#### Scenario: Historical packed snapshot lacks unit labels
- **WHEN** an older packed transaction snapshot does not contain the new unit-label fields
- **THEN** the receipt SHALL resolve the best available configured conversion and base-unit names without emitting placeholder initials or changing persisted historical data

### Requirement: Receipt line total column reflects Rupiah line total

The POS receipt SHALL display each line item's total (subtotal) column in the same Rupiah unit used by the receipt grand total, payment values, and checkout totals. When a transaction snapshot stores a line total in minor units, the receipt data mapping MUST convert it to Rupiah exactly once before rendering. This applies uniformly to completed-checkout receipts, draft receipts, and loaded-transaction receipts.

#### Scenario: Single-unit line prints the checkout's Rupiah total

- **WHEN** a completed transaction line has quantity 1, unit price Rp45.000, and a snapshot line total of `4500000` minor units
- **THEN** the receipt line's total column displays Rp45.000
- **AND** it MUST NOT display Rp4.500.000

#### Scenario: Line total matches per-unit breakdown and grand total

- **WHEN** a receipt renders a line whose per-unit breakdown reads `1 PCS(S) @ Rp45.000` and whose only line is that item
- **THEN** the line total column equals the receipt grand total (Rp45.000)

#### Scenario: Draft and loaded-transaction receipts use the same Rupiah line total

- **WHEN** a draft or loaded transaction line snapshot stores its line total in minor units
- **THEN** its receipt preview displays the equivalent Rupiah value in the line total column using the same conversion as a completed receipt

#### Scenario: Packed breakdown per-unit price remains correctly scaled

- **WHEN** a packed line stores breakdown prices (`box_price_applied` / `loose_price_applied`) and its line total in minor units
- **THEN** the packed unit-breakdown prices and line total column are each converted to Rupiah exactly once
- **AND** the line total remains consistent with the receipt grand total

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

### Requirement: Completed receipts SHALL identify bundle-component serials
A completed POS receipt and reprint SHALL render persisted component serial assignments beneath the bundle component to which they belong, without consulting the live bundle definition or current serial location/status.

#### Scenario: Receipt contains serialized parent and component
- **WHEN** a completed bundled transaction contains parent serials and component serials
- **THEN** the receipt SHALL distinguish parent serials from component serials
- **AND** each component serial SHALL appear with its persisted component name or code

#### Scenario: Historical bundle definition changes
- **WHEN** a completed receipt is reprinted after the live bundle or component changes or is deleted
- **THEN** the receipt SHALL retain the originally posted component-to-serial association

### Requirement: Completed receipts SHALL display total bundle component quantities
The POS receipt and receipt reprint SHALL display each bundle component's exact total quantity for the parent quantity sold. When composition is reconstructed from a per-bundle transaction snapshot, the displayed component quantity MUST equal the parent line quantity multiplied by the component quantity per bundle. A persisted Sales or dispatch quantity that already represents the total MUST NOT be multiplied again.

#### Scenario: Snapshot fallback scales a one-per-bundle component
- **WHEN** a completed receipt falls back to a transaction snapshot for a parent bundle line with quantity 2 and a component quantity per bundle of 1
- **THEN** the receipt SHALL display the component quantity as `x2`

#### Scenario: Snapshot fallback scales a multi-per-bundle component
- **WHEN** a completed receipt falls back to a transaction snapshot for a parent bundle line with quantity 2 and a component quantity per bundle of 2
- **THEN** the receipt SHALL display the component quantity as `x4`

#### Scenario: Persisted total is not multiplied twice
- **WHEN** persisted Sales or dispatch composition reports a total component quantity of 4 for a parent bundle line with quantity 2
- **THEN** the receipt SHALL display the component quantity as `x4`
- **AND** the receipt MUST NOT multiply that persisted total by the parent quantity again

### Requirement: Completed POS reprints show current Sale-derived prices by receipt line
When a completed POS receipt is printed or reprinted after generated Sale monetary values change, the system SHALL show each customer-facing line's current amount from its associated authoritative Sale details and SHALL show the current transaction total equal to the sum of reachable generated Sale totals. Bundle components SHALL remain included beneath one priced parent bundle line and their nested allocation snapshots MUST NOT be billed again. For completed, non-reversed returns, quantities, composition quantities, serial identities, payments, and return details SHALL reflect the current purchase as specified below. Without such returns, existing product, quantity, composition, and serial presentation SHALL remain intact. The current monetary projection SHALL NOT overwrite persisted checkout snapshots.

#### Scenario: Parent remainder is edited on a three-owner bundle
- **WHEN** a quantity-two bundle originally totals 2000 across parent-owner and two component-owner Sales, and the parent-owner Sale detail is increased by 50
- **THEN** the reprinted bundle line and current receipt total show 2050
- **AND** the bundle quantity stays two and nested components are not priced as extra customer lines.

#### Scenario: Another owner allocation changes
- **WHEN** a generated component-owner Sale detail changes while its bundle-item allocation snapshot remains unchanged
- **THEN** the associated customer-facing bundle line reflects the current Sale detail amount
- **AND** the bundle-item snapshot remains excluded from the arithmetic.

#### Scenario: Multiple receipt lines share a Sale
- **WHEN** one owner Sale contains details for two different POS receipt lines
- **THEN** a monetary edit to one detail changes only its associated receipt line
- **AND** the sum of displayed line charges, discounts, and header charges reconciles to the current generated-Sale total without duplicating any detail.

#### Scenario: Sale header discount or shipping changes
- **WHEN** an authorized monetary edit changes a generated Sale's header discount or shipping amount
- **THEN** the reprinted line and receipt monetary presentation reconciles to the current generated-Sale total without adding a new receipt layout section
- **AND** unchanged product, quantity, and composition facts remain intact and checkout tender facts remain available in explicit historical receipts.

### Requirement: Historical reprints require proven monetary line mapping
For a completed checkout posted before direct POS-line lineage existed, the system SHALL use current Sale amounts for a reprint only when every Sale detail can be assigned to the correct customer-facing POS line without ambiguity. A single-line checkout with complete, valid generated-Sale mapping SHALL be treated as unambiguous. For an ambiguous multi-line checkout, the system MUST NOT guess using product identity or ordering, MUST NOT print an updated receipt with an incorrect line amount, and SHALL explain why an updated-price reprint is unavailable while allowing the original checkout receipt to remain accessible.

#### Scenario: Historical single-line split bundle
- **WHEN** an older completed checkout contains one POS line and all generated Sales resolve consistently
- **THEN** its current generated-Sale total can be shown on that one reprinted line.

#### Scenario: Historical repeated bundle is ambiguous
- **WHEN** an older checkout contains repeated or shared bundle products and persisted records do not prove each Sale detail's POS line
- **THEN** the updated-price reprint is refused with an actionable explanation
- **AND** the operator can still access the original checkout receipt without fabricated current line prices.

### Requirement: Completed receipt print paths show the same current purchase
All ordinary completed POS receipt print and reprint entry points SHALL show the same current purchase state, including checkout, transaction, and global-payment receipt paths. Existing access checks and PRINT versus REPRINT audit distinctions SHALL remain intact. Explicit historical receipts SHALL preserve original checkout quantities, serials, totals, tender, and change and SHALL be clearly identified as historical. Draft and loaded previews SHALL retain existing behavior. Reading or printing a receipt MUST NOT mutate financial or return records.

#### Scenario: Print and Reprint after a completed return
- **WHEN** a completed return exists and an authorized operator opens ordinary checkout Print, transaction Print/Reprint, checkout Reprint, or global-payment Reprint
- **THEN** each receipt shows equivalent current items, return history, total, paid amount, and debt
- **AND** successful access records the existing action-specific audit type

#### Scenario: Historical original remains available
- **WHEN** an authorized operator explicitly requests the historical receipt after a completed return
- **THEN** the receipt shows the original checkout facts with a historical indication and no current-state adjustments

### Requirement: Main receipt items reflect completed cash returns
Main items SHALL show remaining quantities and authoritative current line totals after all completed, non-reversed cash returns. A zero-remaining item SHALL be omitted from main items and SHALL remain visible in return details. A partial return MUST NOT be represented by dividing the reduced amount by the original quantity. Packed-unit and bundle component quantities SHALL reflect the remaining customer-facing quantity, without counting split-owner execution rows as additional customer returns. All-item returns SHALL produce a valid receipt with an empty main item list and zero purchase total.

#### Scenario: Partial return keeps correct quantity and amount
- **WHEN** three items were bought for Rp100,000 each and one is cash-returned with a completed return
- **THEN** the main line shows quantity two and total Rp200,000, with unit price Rp100,000
- **AND** the returned quantity one appears below in return details

#### Scenario: All items are returned
- **WHEN** completed cash returns consume every purchased item
- **THEN** main items are empty and current total, paid amount, and debt are zero
- **AND** all returned items remain visible in return details

#### Scenario: Packed and split bundle returns
- **WHEN** a completed cash return reduces a packed line or split-owner bundle parent quantity
- **THEN** the main unit breakdown and nested component quantities reflect the remaining quantity
- **AND** each customer-facing quantity reduction is counted once

### Requirement: Replacement receipts show retained items and effective serials
Completed product replacements SHALL preserve customer-facing purchased quantity and value. Non-serial replacements SHALL be explained in return details. Serialized main items SHALL show effective replacement serials in place of returned serials, while return details SHALL retain original-to-replacement lineage. Unaffected serials SHALL remain present. Replacement chains SHALL resolve chronologically, and subsequent cash returns SHALL remove the affected effective serial. Informational bundle component rows MUST NOT cause a replacement of component serials when only the parent was replaced.

#### Scenario: Non serial replacement
- **WHEN** one of three non-serial items is replaced through a completed return
- **THEN** the main quantity stays three with unchanged customer-facing value
- **AND** return details explain the quantity replaced

#### Scenario: Serial replacement chain and later refund
- **WHEN** serial A is replaced by B, B is replaced by C, and another purchased serial D is unaffected
- **THEN** the main items show C and D and return details show both A to B and B to C
- **AND** a subsequent completed cash return for C removes C from main items and records C in return details

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

### Requirement: Current receipt payment presentation offsets debt before cash refund
Current receipts SHALL display current purchase total, retained paid amount, and current outstanding debt reconciled with persisted authoritative payment and return execution evidence, including later payments. A return SHALL reduce unpaid debt first; only value exceeding the unpaid debt SHALL be a cash refund. Paid amount SHALL exclude refunded money and SHALL NOT exceed current purchase total. Original checkout tender and change MUST NOT be presented as current paid or change after returns; adjusted payment-method rows SHALL reconcile to current retained paid amount, and refunds SHALL be explained in return details. Receipt generation MUST NOT create refunds, rewrite checkout snapshots, or silently conceal contradictory execution evidence; an unreconcilable current receipt SHALL be refused with an actionable explanation and access to the historical receipt.

#### Scenario: Return reduces unpaid debt
- **WHEN** original total is Rp300,000, paid amount is Rp200,000, debt is Rp100,000, and a Rp50,000 return completes
- **THEN** the receipt shows total Rp250,000, paid Rp200,000, and debt Rp50,000
- **AND** return details show Rp50,000 debt reduction and zero cash refund

#### Scenario: Return exceeds unpaid debt
- **WHEN** original total is Rp300,000, paid amount is Rp200,000, debt is Rp100,000, and a Rp150,000 return completes
- **THEN** the receipt shows total Rp150,000, paid Rp150,000, and debt zero
- **AND** return details show Rp100,000 debt reduction and Rp50,000 cash refund

#### Scenario: Fully paid purchase partially refunded
- **WHEN** a fully paid Rp300,000 purchase has a completed Rp100,000 cash return
- **THEN** current total and paid amount are Rp200,000 and debt is zero
- **AND** return details show the actual Rp100,000 refund without displaying it as change

#### Scenario: Mapping or payment evidence cannot reconcile
- **WHEN** persisted records cannot prove current item, serial, or payment allocation
- **THEN** current receipt printing is refused with an actionable explanation and historical receipt access
- **AND** no successful print audit or financial mutation is recorded

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
