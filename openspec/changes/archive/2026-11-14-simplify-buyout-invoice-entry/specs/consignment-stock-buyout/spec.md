# Spec Delta

## MODIFIED Requirements

### Requirement: Approved invoice terms preserve receipt evidence
Draft pricing SHALL retain receipt commercial snapshots as evidence and accept one product-level set of invoice price, row/global discount, tax and final-row-total controls from tab 3. Original-price disagreements SHALL require explicit invoice price or total entry. All contributing acquisition slices SHALL use the same product financial terms with exact allocated totals. Invoice number, date, due date or payment term, notes, tax reference, and supported attachments SHALL follow existing Purchase validation; the reporting date SHALL start equal to the invoice date and is not a preparation input. Approval SHALL freeze accepted terms separately from original evidence.

#### Scenario: Supplier invoice changes prices
- **WHEN** an authorized preparer adjusts receipt-derived prices and discounts
- **THEN** the preview SHALL calculate final payable and inventory cost server-side and retain original receipt amounts for audit

#### Scenario: Tax policy differs by business
- **WHEN** the business is non-PKP
- **THEN** invoice tax SHALL be zero with tax-included disabled regardless of forged inputs
- **AND** a PKP business SHALL receive validated tax choices and the existing tax-included default

#### Scenario: Approved price is changed at finalization
- **WHEN** submitted terms or row identities differ from the approved revision
- **THEN** finalization SHALL reject the change and require a newly approved document rather than modify accepted terms

## ADDED Requirements

### Requirement: Newly prepared buyouts create one Purchase detail per product
Buyouts prepared with product-level terms SHALL create exactly one Purchase detail for each distinct product, combining its sold and unsold quantities across all selected receipts and destinations. Detail financial amounts SHALL match the approved product row. Each source contribution SHALL retain original evidence, accepted amounts and its precise link to that detail; source allocation rows SHALL remain distinct.

#### Scenario: Several receipt lots share a product
- **WHEN** an approved product row contains five units spanning different original receipt prices, source tax evidence and sold/unsold allocations
- **THEN** finalization SHALL create one Purchase detail with quantity five and the single accepted invoice price, discount and tax
- **AND** lineage SHALL preserve each contribution and reconcile exactly to the detail totals

#### Scenario: Mixed product row affects unsold cost only
- **WHEN** two sold units and three unsold units share a product invoice row and a global discount
- **THEN** financial allocation SHALL reconcile across all five units and only the accepted unsold inventory amounts SHALL enter the existing acquisition-cost calculation
- **AND** physical stock buckets, serial identities and historical sales costs SHALL follow existing buyout rules

#### Scenario: Fractional quantities and rounded totals
- **WHEN** source slices have fractional quantities or a product line-total override cannot divide evenly to currency precision
- **THEN** deterministic allocation SHALL preserve the exact approved product and document totals without creating a second product detail or a different invoice price on another source slice

### Requirement: Product grouping preserves existing buyout documents
Historical finalized Purchases SHALL retain their existing detail identities, amounts and lineage. Existing approved buyouts SHALL retain their approved financial snapshot and hash contract and SHALL finalize through their existing compatible terms. Reopening an eligible draft SHALL use the product editor without inventing a shared invoice price where saved source prices disagree.

#### Scenario: Historical Purchase is opened
- **WHEN** a generated Purchase predates product-level preparation
- **THEN** it SHALL display its existing rows without automatic regrouping or repricing

#### Scenario: Existing approved document is finalized
- **WHEN** an approved legacy buyout is finalized without financial edits
- **THEN** its original approval verification, accepted totals and source mapping SHALL remain compatible and SHALL not require rewriting approval hashes

#### Scenario: Existing draft has conflicting saved prices
- **WHEN** a draft with different prices for the same product is reopened in the wizard
- **THEN** the editor SHALL show its source evidence and require one explicit product price or total before the revised draft is saved
