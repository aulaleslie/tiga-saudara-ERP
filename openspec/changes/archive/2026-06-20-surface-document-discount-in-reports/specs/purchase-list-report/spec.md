## ADDED Requirements

### Requirement: Document discount column

The purchase report SHALL present the document-level discount (`Purchase.discount_amount`) in a clearly labeled `Diskon` column and SHALL retain the derived `Diskon %` column (the discount as a percentage of the document total). The report SHALL NOT display the per-line discount columns backed by `PurchaseDetail.product_discount_amount` (`Diskon` per-line and `Diskon Per Baris %`), because the importer never populates them. This applies to detail mode, header mode, and the global variant, and the on-screen columns SHALL match the exported columns.

#### Scenario: Document discount shown for a discounted purchase

- **WHEN** a purchase has a positive `discount_amount`
- **THEN** the report's `Diskon` column shows that document discount amount
- **AND** the `Diskon %` column shows that discount as a percentage of the document total

#### Scenario: Per-line discount columns are not displayed

- **WHEN** the purchase report is rendered in any mode
- **THEN** no column sourced from `PurchaseDetail.product_discount_amount` (per-line `Diskon` or `Diskon Per Baris %`) is displayed

#### Scenario: Zero discount renders cleanly

- **WHEN** a purchase has `discount_amount` of 0
- **THEN** the `Diskon` column shows 0 and the `Diskon %` column shows 0

#### Scenario: Export columns match the report

- **WHEN** the filtered purchase report is exported
- **THEN** the exported discount columns are the document `Diskon` and `Diskon %`, matching the on-screen report
