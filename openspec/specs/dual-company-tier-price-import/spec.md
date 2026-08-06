# dual-company-tier-price-import Specification

## Purpose
Dual-company tier-price import workflow for bulk price updates to CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA via native Excel workbook upload with independent tier validation and company-scoped mutation.

## Requirements

### Requirement: Dedicated dual-company price workbook upload
The system SHALL provide a Product-module upload workflow, distinct from the Accurate price-and-stock snapshot workflow, for a native `.xlsx` workbook containing price sheets for CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA. The workflow SHALL require product-edit authorization, create a `dual_company_tier_price` import batch, queue processing, and direct the user to its batch detail page.

#### Scenario: Authorized user submits a readable workbook
- **WHEN** a user authorized to edit products submits a readable `.xlsx` workbook
- **THEN** the system SHALL create and queue a dual-company tier-price import batch
- **AND** the system SHALL direct the user to that batch's detail page

#### Scenario: Unauthorized user attempts upload
- **WHEN** a user without product-edit authorization attempts to open or submit the workflow
- **THEN** the system SHALL deny access

#### Scenario: Invalid XLSX is submitted
- **WHEN** an authorized user submits a non-XLSX file or a file that cannot be read as an XLSX workbook
- **THEN** the system SHALL reject the upload before creating a batch
- **AND** the system SHALL not queue a price mutation

### Requirement: Workbook structure establishes fixed company targets
The system SHALL require exactly one worksheet named `CV TIGA NUSA COMPUTER` and exactly one worksheet named `CV TOP IT INTERNUSA`, each with row 4 headers `Nama Produk`, `Harga Jual`, `Harga Tier 1`, and `Harga Tier 2`. The import SHALL use the worksheet name to determine the target company and SHALL ignore `Harga Beli Terakhir` and `Harga Beli Rata-rata`.

#### Scenario: Export-compatible workbook is accepted
- **WHEN** both required worksheets and all required row-4 headers are present exactly once
- **THEN** the system SHALL stage data rows from both worksheets
- **AND** each staged row SHALL retain its worksheet-derived company target

#### Scenario: Required worksheet or header is absent or duplicated
- **WHEN** either required worksheet is absent, duplicated, or lacks a required header
- **THEN** the system SHALL fail the batch with an actionable structure error
- **AND** the system SHALL not update any price

#### Scenario: Workbook contains an unexpected worksheet
- **WHEN** the workbook contains a worksheet other than the two required company worksheets
- **THEN** the system SHALL fail the batch before applying prices

### Requirement: Rows update independent company-scoped selling tiers
For every valid row, the system SHALL resolve exactly one existing product by normalized `Nama Produk` and update only that product's existing `product_prices` row for the row's worksheet company. `Harga Jual`, `Harga Tier 1`, and `Harga Tier 2` SHALL be parsed and applied independently; owner markers, session setting, product stock, purchase costs, taxes, legacy product prices, bundle prices, and conversion prices SHALL not affect or be affected by this workflow.

#### Scenario: A row updates all three tiers
- **WHEN** a row has a uniquely matched product and numeric values in all three selling-tier columns
- **THEN** the system SHALL set that worksheet company's sale, Tier 1, and Tier 2 prices to their respective imported values in one transaction
- **AND** the system SHALL not alter another company's price row for the product

#### Scenario: A row updates selected tiers only
- **WHEN** a uniquely matched row has one or two blank selling-tier cells and at least one numeric selling-tier value
- **THEN** the system SHALL preserve every tier represented by a blank cell
- **AND** the system SHALL update only the tiers represented by numeric cells

#### Scenario: Zero is an explicit selling price
- **WHEN** a selling-tier cell contains numeric zero
- **THEN** the system SHALL store zero for that specific tier

#### Scenario: No selling tier is supplied
- **WHEN** all three selling-tier cells are blank
- **THEN** the system SHALL skip the row with an explanatory result
- **AND** the system SHALL not change its price row

#### Scenario: Product cannot be resolved uniquely
- **WHEN** a row's normalized product name matches zero or more than one catalog product
- **THEN** the system SHALL mark the row as skipped or error with an actionable match reason
- **AND** the system SHALL not create a product or price row

#### Scenario: Target company has no existing price row
- **WHEN** the matched product has no `product_prices` row for the worksheet company
- **THEN** the system SHALL mark the row as skipped or error with an actionable reason
- **AND** the system SHALL not create a price row

### Requirement: Duplicate-target protection and batch audit
The system SHALL detect all rows targeting the same product and company before mutation. Conflicting supplied values for the same tier SHALL prevent any update for that target; equivalent duplicates SHALL update at most once. The batch detail SHALL expose worksheet/company, matching outcome, supplied tiers, previous tiers, resulting tiers, and row errors, and SHALL not offer generic undo for this import type.

#### Scenario: Duplicate target conflicts
- **WHEN** two or more rows for the same product and worksheet company supply different values for the same selling tier
- **THEN** the system SHALL mark every row for that target as an error
- **AND** the system SHALL not change any tier for that target

#### Scenario: Equivalent duplicate target
- **WHEN** duplicate rows for the same product and worksheet company have equivalent supplied tier values
- **THEN** the system SHALL apply the target at most once
- **AND** later equivalent rows SHALL be identified as duplicates in the batch result

#### Scenario: Successful result is inspected
- **WHEN** an authorized user views a processed batch row
- **THEN** the system SHALL display the worksheet company, resolved product, supplied tiers, previous tiers, and resulting tiers
- **AND** the system SHALL not display an undo action for the batch
