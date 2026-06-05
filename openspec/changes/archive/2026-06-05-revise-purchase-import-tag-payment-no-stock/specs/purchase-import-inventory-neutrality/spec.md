## ADDED Requirements

### Requirement: Purchase imports do not mutate inventory quantities
The purchase importer SHALL create imported purchase documents and details without mutating stock quantity records or global product quantity totals.

#### Scenario: Imported purchase creates no stock increment
- **WHEN** a future purchase CSV row imports successfully with a positive quantity
- **THEN** the importer MUST create the purchase and purchase detail
- **AND** the importer MUST NOT increment any `product_stocks.quantity`, `product_stocks.quantity_tax`, or `product_stocks.quantity_non_tax` value for the imported row
- **AND** the importer MUST NOT increment the imported product's global `product_quantity`

#### Scenario: Imported purchase creates no inventory transaction
- **WHEN** a future purchase CSV invoice imports successfully
- **THEN** the importer MUST NOT create an inventory `transactions` row of type `BUY` for any imported purchase detail
- **AND** existing inventory transactions for the product MUST remain unchanged except for unrelated workflows outside the import

### Requirement: Purchase imports still create catalog and document records
The purchase importer SHALL continue creating the non-inventory records needed to represent imported purchases.

#### Scenario: New product is normalized and created without stock
- **WHEN** a future purchase CSV row references a product name with a `*` prefix or `TP` suffix
- **THEN** the importer MUST create or find the product using the normalized product name
- **AND** the importer MUST create the purchase detail for that product
- **AND** the product's imported quantity MUST remain absent from stock quantity totals

#### Scenario: Imported purchase keeps received document status
- **WHEN** a future purchase CSV invoice imports successfully
- **THEN** the created purchase MUST keep the existing received purchase status convention
- **AND** that received status MUST NOT imply that the import mutated inventory quantities

### Requirement: Historical inventory records are not rewritten
The system SHALL NOT rewrite historical stock, product quantity, or inventory transaction records when this import behavior changes.

#### Scenario: Deployment does not reverse old import stock
- **WHEN** this change is deployed
- **THEN** existing `product_stocks`, product `product_quantity`, and inventory `transactions` records MUST remain unchanged
- **AND** no migration or background job MUST reverse stock effects from earlier purchase imports
