## ADDED Requirements

### Requirement: Document discount column

The sales report SHALL present the document-level discount (`Sale.discount_amount`) in a clearly labeled `Diskon` column and SHALL retain the derived `Diskon %` column (the discount as a percentage of the document total). The report SHALL NOT display the per-line discount columns backed by `SaleDetails.product_discount_amount` (`Diskon` per-line and `Diskon Per Baris %`), because the importer never populates them. This applies to detail mode, header mode, and the global variant, and the on-screen columns SHALL match the exported columns.

#### Scenario: Document discount shown for a discounted sale

- **WHEN** a sale has `discount_amount` of 45045.05
- **THEN** the report's `Diskon` column shows 45045.05
- **AND** the `Diskon %` column shows that discount as a percentage of the document total

#### Scenario: Per-line discount columns are not displayed

- **WHEN** the sales report is rendered in any mode
- **THEN** no column sourced from `SaleDetails.product_discount_amount` (per-line `Diskon` or `Diskon Per Baris %`) is displayed

#### Scenario: Zero discount renders cleanly

- **WHEN** a sale has `discount_amount` of 0
- **THEN** the `Diskon` column shows 0 and the `Diskon %` column shows 0

#### Scenario: Export columns match the report

- **WHEN** the filtered sales report is exported
- **THEN** the exported discount columns are the document `Diskon` and `Diskon %`, matching the on-screen report
