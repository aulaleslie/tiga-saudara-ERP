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
Step 3 SHALL provide financial entry on one row per product, showing combined selected quantity, editable invoice terms, document discount and exact supplier payable. Original receipt prices, sold/unsold subtotals, billing business and acquired-stock owners SHALL remain available in supporting details. It SHALL collect invoice information before the existing approval and finalization flow. **Simpan Draft** SHALL create a draft only, subject to existing permissions and the mutation gate.

#### Scenario: Draft only
- **WHEN** the operator saves from step 3
- **THEN** a DRAFT buyout is created and no reservation, claim, stock movement or Purchase is created

### Requirement: Preparation reuses read-only evidence
The preparation request SHALL reuse or batch read-only capacity evidence within the request. Mutation paths SHALL NOT use cached evidence.

#### Scenario: Representative query budget
- **WHEN** the preparation screen loads for a supplier with 8 receipts sharing a pool and 12 attributed sales
- **THEN** the request stays within the recorded query budget

### Requirement: Tab three combines product quantities and financial terms
Step 3 SHALL combine every selected occurrence of a product into one financial row across receipts, sold/unsold kinds, serials, locations and acquiring businesses. Each row SHALL have one unit price, fixed-per-unit or percentage discount, invoice tax and final line total. Expandable details SHALL retain the contributing receipt, quantity, serial, location and ownership evidence without independent financial inputs.

#### Scenario: Same product spans different sources
- **WHEN** a product contributes two sold units from one receipt and three unsold units from another
- **THEN** step 3 SHALL show one financial row with quantity five and one set of commercial controls
- **AND** expanding that row SHALL show both receipt contributions and their sold/unsold and ownership details

#### Scenario: Original receipt prices disagree
- **WHEN** contributing receipts have different original unit prices and no explicit saved invoice price exists
- **THEN** the combined row SHALL require explicit unit-price or final-line-total entry without averaging or silently choosing a receipt
- **AND** agreeing receipt prices SHALL prefill a consistent invoice price under the current tax-inclusion mode

#### Scenario: Goods selection changes
- **WHEN** an operator returns to step 2 and changes selected quantities or products
- **THEN** step 3 SHALL rebuild the product quantities and calculated amounts while retaining explicit terms for products still selected
- **AND** a removed product SHALL not leave a hidden charge in the document

### Requirement: Product line totals and prices express one financial amount
Editing a product's final line total SHALL make it authoritative and derive the displayed unit price consistently with quantity, discount and tax. Subsequent unit-price, discount or tax edits SHALL clear the override and recalculate the row. The payable SHALL update from the current inputs using an authoritative server calculation shared by draft saving and approval validation.

#### Scenario: Final total is overridden
- **WHEN** the operator enters a valid final line total
- **THEN** the preview SHALL honor that amount, reconcile the displayed unit price and document totals, and retain the input through validation failure

#### Scenario: Price changes after a total override
- **WHEN** the operator edits unit price, discount or tax on an overridden row
- **THEN** the old override SHALL clear and the row total SHALL reflect the new controls

#### Scenario: Zero or invalid monetary input
- **WHEN** the operator enters an explicit zero price or total
- **THEN** it SHALL count as a deliberate zero rather than omitted input
- **AND** negative amounts, excessive discounts and unreconciled amounts SHALL be rejected without saving a partial draft

### Requirement: Tab three captures complete supplier invoice information
Step 3 SHALL provide supplier invoice number, invoice date, due date/payment term, tax reference, notes, supported attachments and fixed/percentage global discount, with no reporting-date input. Monetary inputs SHALL use normal consignment nominal formatting. It SHALL follow normal consignment financial meanings and active-business PKP policy. A selected payment term SHALL derive due date unless manually overridden; a manual date SHALL survive invoice-date changes.

#### Scenario: Invoice is prepared before approval
- **WHEN** the operator saves valid tab-3 inputs
- **THEN** invoice data and financial terms SHALL persist on the draft for existing approval and finalization
- **AND** finalization SHALL use them without opening another financial-entry page or accepting changed approved terms

#### Scenario: Tax policy is applied
- **WHEN** the active business is PKP
- **THEN** valid invoice tax choices and tax inclusion SHALL be available with tax-included enabled by default
- **AND** a non-PKP business SHALL expose no financial tax controls and SHALL save zero tax regardless of forged inputs

#### Scenario: Payment term and manual due date
- **WHEN** the operator selects a payment term, then manually overrides its calculated due date and changes invoice date
- **THEN** the manual due date SHALL remain and server validation SHALL check the final date and term inputs

#### Scenario: Reporting date is not prepared in the wizard
- **WHEN** a draft is created or edited, including a forged request containing a reporting date
- **THEN** no reporting date SHALL be accepted, and the finalized Purchase's reporting date SHALL equal its invoice date
- **AND** previously approved documents and existing Purchases SHALL keep their recorded reporting dates

#### Scenario: Indonesian nominal input
- **WHEN** the operator enters or pastes `1.250.000,50` into a price, fixed discount or total field
- **THEN** it SHALL display with thousand separators and submit the canonical value 1250000.50; unparsable text SHALL remain visible, be flagged and block saving

#### Scenario: Save fails validation
- **WHEN** a draft save is rejected
- **THEN** goods selection, product terms and invoice fields SHALL be restored and any required attachment reselection SHALL be explained

### Requirement: Editable drafts reopen the existing wizard
Authorized users SHALL reopen DRAFT and REJECTED buyouts in the same three-tab wizard with saved supplier, destinations, selected goods, product financial terms and invoice data restored. Saving SHALL revise the existing document under its revision and permission checks. Approved and finalized buyouts SHALL remain unavailable for draft editing.

#### Scenario: Rejected invoice terms are corrected
- **WHEN** an authorized operator opens Edit on a rejected buyout, changes tab-3 terms and saves
- **THEN** the same buyout SHALL return to DRAFT with its revision incremented, preserving unchanged selections

#### Scenario: Stale or unauthorized edit
- **WHEN** an edit targets a stale revision, another business, an approved document or a user lacking edit authority
- **THEN** it SHALL be rejected without replacing goods, financial inputs or attachments
