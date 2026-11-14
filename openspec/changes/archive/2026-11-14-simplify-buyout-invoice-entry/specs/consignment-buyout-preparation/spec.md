# Spec Delta

## MODIFIED Requirements

### Requirement: Review shows payable and ownership before saving a draft
Step 3 SHALL provide financial entry on one row per product, showing combined selected quantity, editable invoice terms, document discount and exact supplier payable. Original receipt prices, sold/unsold subtotals, billing business and acquired-stock owners SHALL remain available in supporting details. It SHALL collect invoice information before the existing approval and finalization flow. **Simpan Draft** SHALL create a draft only, subject to existing permissions and the mutation gate.

#### Scenario: Draft only
- **WHEN** the operator saves from step 3
- **THEN** a DRAFT buyout is created and no reservation, claim, stock movement or Purchase is created

## ADDED Requirements

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
