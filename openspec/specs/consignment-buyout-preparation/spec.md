# consignment-buyout-preparation Specification

## Purpose

Enable operators to prepare a consignment buyout draft by selecting eligible goods, assigning destinations, and reviewing the supplier payable and resulting ownership.

## Requirements

### Requirement: Preparation is a three-step workflow
The buyout preparation screen SHALL present three steps—supplier and destinations, select goods, review and save draft—and SHALL preserve supplier, destination and goods selections when the operator moves between steps or a save fails validation.

#### Scenario: Back-navigation keeps selections
- **WHEN** an operator selects goods in step 2, opens step 3 and returns to step 2 or step 1
- **THEN** the chosen destinations, rows, quantities and serials are unchanged

#### Scenario: Failed save rehydrates the selection
- **WHEN** a draft save is rejected by server validation
- **THEN** the screen returns with the submitted selection, quantities and serials restored

### Requirement: Supplier and destinations are explicit
Step 1 SHALL show the selected supplier, the business that receives the supplier bill (the active business), and one owned-stock destination selector per holding business that holds selectable unsold stock. The system SHALL NOT choose a destination automatically.

#### Scenario: Remote holding business
- **WHEN** unsold supplier stock is held at a consignment location of another business the actor may access
- **THEN** step 1 asks for a standard destination of that business and the goods remain billed to the active business

### Requirement: Goods are selected from one grouped list
Step 2 SHALL group selectable goods by receiving document, then product, then "Belum terjual" (unsold) and "Terjual belum ditagih" (sold but unbilled), showing each product once per receipt. It SHALL use receipt numbers, location names, business names and sales/POS document numbers instead of database IDs. Receipt, sold source, source location and ownership identities SHALL be carried as hidden line fields behind each unit or quantity row.

#### Scenario: Grouping order
- **WHEN** a receipt holds unsold and sold-but-unbilled units of one product
- **THEN** the screen shows `Penerimaan` → `Produk` → `Belum terjual` units → `Terjual belum ditagih` units, in that order, with one product heading

#### Scenario: Shared limits
- **WHEN** several receipts share one product/location pool or one sold source spans receipts
- **THEN** the screen prevents the combined selection from exceeding the pool or source availability, and the server revalidates it

### Requirement: Selection controls work at every level
The screen SHALL provide global "Pilih semua yang tersedia" and "Batalkan semua pilihan", plus receipt-level and product-level checkboxes that select or deselect their units and show a partial (indeterminate) state when only some are selected. Selecting all SHALL include eligible unsold and sold-but-unbilled units, up to the shared limits; blocked units stay excluded with their reasons.

#### Scenario: Partial indicator
- **WHEN** every unit is selected and the operator unticks one serial
- **THEN** that product's and receipt's checkboxes show the partial state, and ticking the product checkbox selects its units again

#### Scenario: Select all then partial
- **WHEN** the operator chooses "Pilih semua yang tersedia", deselects one unit and reduces a non-serialized quantity
- **THEN** only the remaining units and quantities are submitted

### Requirement: Serials are individual checkboxes shown once
Each eligible serial SHALL appear exactly once in its receipt/product group as a checkbox labelled with its actual serial number. The serialized line quantity SHALL equal the number of checked serials. No searchable selector, database ID or comma-separated input is used.

#### Scenario: Serial checkboxes
- **WHEN** the operator ticks two of three serials of a receipt
- **THEN** the draft line contains exactly those two serials with quantity 2

#### Scenario: No duplicate serials
- **WHEN** a receipt has several custody entries at the same location
- **THEN** each serial is still offered once and no phantom duplicate row is shown

### Requirement: Sold units show their sales document
Sold-but-unbilled units SHALL show their human-readable sales document, preferring the originating POS receipt, with a link only when the viewer may open it, and SHALL name the selling business when it differs. The supplier bill remains with the original receipt business. Non-serialized sold rows keep one row per sold source and receipt allocation.

#### Scenario: POS reference preferred
- **WHEN** a sold source's sale was created by a POS checkout in another business
- **THEN** the unit shows `POS <receipt number>`, the Sales reference and "dijual oleh <business>"

#### Scenario: Non-POS sale
- **WHEN** a sold source's sale did not come from POS checkout
- **THEN** the unit shows `Penjualan <reference>`

#### Scenario: Permission-aware link
- **WHEN** the viewer lacks `sales.show`
- **THEN** the Sales reference is shown without a link, and a POS receipt is linked only for the active business's own POS with POS transaction permissions

### Requirement: Unsold stock shows location and acquiring business
Unsold units SHALL be grouped by current location and show the business that will own them after the buyout whenever a product has stock at more than one location, or the stock is held by another business.

#### Scenario: Remote holding
- **WHEN** a product's unsold units are split between a local consignment location and another business's location
- **THEN** each group shows "Lokasi saat ini" and "menjadi milik <business>", and the remote group is marked as another business

### Requirement: Fully billed sales are not shown as blocked
Sold sources with nothing left to bill (already billed or reserved by other documents, without other blockers) SHALL be summarised separately from blocked goods instead of being listed as blocked units.

#### Scenario: Already billed
- **WHEN** a sold source is fully consumed by a finalized buyout
- **THEN** it appears only in the "sudah ditagih" summary and is not selectable

### Requirement: Tax quantities are derived
Unsold tax buckets SHALL be derived from the selected quantity using the existing rule (per product/location pool, non-taxed bucket first, then taxed, in receipt order). The server SHALL derive buckets for unsold lines submitted without them and SHALL keep validating explicit buckets.

#### Scenario: Derived buckets
- **WHEN** an unsold line is saved without buckets
- **THEN** the draft line stores buckets that sum to its quantity following the existing order

### Requirement: Blocked goods are shown separately
Goods that cannot be selected SHALL appear in a separate blocked list with a plain explanation and a relevant action (for example manual discovery or reconciliation). Blocked goods SHALL NOT be silently omitted or replaced with other stock, and discovery SHALL remain manual.

#### Scenario: Unattributed sale
- **WHEN** a pool contains a sale not yet attributed to a receipt
- **THEN** the affected goods appear as blocked with an explanation and a link to the sold-source / reconciliation screen, and are not selectable

### Requirement: Review shows payable and ownership before saving a draft
Step 3 SHALL show selected quantities, receipt prices, document discount, sold and unsold subtotals and the estimated supplier payable, the business that receives the supplier bill and the business that owns each acquired stock line, and SHALL collect invoice information. **Simpan Draft** SHALL create a draft only, subject to existing permissions and the mutation gate.

#### Scenario: Draft only
- **WHEN** the operator saves from step 3
- **THEN** a DRAFT buyout is created and no reservation, claim, stock movement or Purchase is created

### Requirement: Preparation reuses read-only evidence
The preparation request SHALL reuse or batch read-only capacity evidence within the request. Mutation paths SHALL NOT use cached evidence.

#### Scenario: Representative query budget
- **WHEN** the preparation screen loads for a supplier with 8 receipts sharing a pool and 12 attributed sales
- **THEN** the request stays within the recorded query budget
