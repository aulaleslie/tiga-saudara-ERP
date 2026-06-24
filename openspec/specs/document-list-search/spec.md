# document-list-search Specification

## Purpose
TBD - created by archiving change expand-document-list-search. Update Purpose after archive.
## Requirements
### Requirement: Purchase list expanded search
The system SHALL allow users to search the operational purchase list by purchase header identifiers, supplier, tags, and purchased product item snapshots.

#### Scenario: Search purchase by external purchase number
- **WHEN** a user searches the purchase list using a value contained in `purchases.supplier_purchase_number`
- **THEN** the list includes matching purchases for the active setting

#### Scenario: Search purchase by tax reference
- **WHEN** a user searches the purchase list using a value contained in `purchases.tax_ref_no`
- **THEN** the list includes matching purchases for the active setting

#### Scenario: Search purchase by supplier reference number
- **WHEN** a user searches the purchase list using a value contained in `purchases.supplier_reference_no`
- **THEN** the list includes matching purchases for the active setting

#### Scenario: Search purchase by product item
- **WHEN** a user searches the purchase list using a value contained in a purchase detail `product_name` or `product_code`
- **THEN** the list includes the purchase that owns the matching detail row

### Requirement: Sales list expanded search
The system SHALL allow users to search the operational sales list by sale header identifiers, customer, tags, and sold product item snapshots.

#### Scenario: Search sale by imported sales reference
- **WHEN** a user searches the sales list using a value contained in `sales.imported_sales_reference_number`
- **THEN** the list includes matching sales for the active setting

#### Scenario: Search sale by tax reference
- **WHEN** a user searches the sales list using a value contained in `sales.tax_ref_no`
- **THEN** the list includes matching sales for the active setting

#### Scenario: Search sale by product item
- **WHEN** a user searches the sales list using a value contained in a sale detail `product_name` or `product_code`
- **THEN** the list includes the sale that owns the matching detail row

### Requirement: Purchase return list expanded search
The system SHALL allow users to search the operational purchase return list by return header identifiers, supplier, returned product item snapshots, and source purchase identifiers.

#### Scenario: Search purchase return by return reference
- **WHEN** a user searches the purchase return list using a value contained in `purchase_returns.reference`
- **THEN** the list includes matching purchase returns

#### Scenario: Search purchase return by supplier
- **WHEN** a user searches the purchase return list using a value contained in the linked supplier name or return supplier name
- **THEN** the list includes matching purchase returns

#### Scenario: Search purchase return by returned product item
- **WHEN** a user searches the purchase return list using a value contained in a purchase return detail `product_name` or `product_code`
- **THEN** the list includes the purchase return that owns the matching detail row

#### Scenario: Search purchase return by source purchase number
- **WHEN** a user searches the purchase return list using a value contained in a linked source purchase `reference`, `supplier_purchase_number`, `supplier_reference_no`, or `tax_ref_no`
- **THEN** the list includes the purchase return that has at least one matching source purchase through its return details

### Requirement: Sale return list expanded search
The system SHALL allow users to search the operational sale return list by return header identifiers, customer, returned product item snapshots, and source sale identifiers.

#### Scenario: Search sale return by return reference
- **WHEN** a user searches the sale return list using a value contained in `sale_returns.reference`
- **THEN** the list includes matching sale returns

#### Scenario: Search sale return by customer
- **WHEN** a user searches the sale return list using a value contained in the sale return customer name or linked customer name/contact name
- **THEN** the list includes matching sale returns

#### Scenario: Search sale return by returned product item
- **WHEN** a user searches the sale return list using a value contained in a sale return detail `product_name` or `product_code`
- **THEN** the list includes the sale return that owns the matching detail row

#### Scenario: Search sale return by source sale number
- **WHEN** a user searches the sale return list using a value contained in `sale_returns.sale_reference`, linked source sale `reference`, `imported_sales_reference_number`, or `tax_ref_no`
- **THEN** the list includes the sale return that matches the source sale identifier

### Requirement: Search composition preserves list behavior
The system SHALL preserve existing list behavior when expanded search is used.

#### Scenario: Search preserves active filters
- **WHEN** a user searches a supported document list while status filters, payment filters, supplier filters, archive visibility, or summary-card filters are active on that list
- **THEN** the search results are constrained by the active filters

#### Scenario: Search preserves sorting and pagination
- **WHEN** a user searches a supported document list and changes sorting or pagination
- **THEN** the list applies the expanded search together with the selected sort and page state

#### Scenario: Matching multiple detail rows does not duplicate documents
- **WHEN** a search term matches more than one detail row for the same document
- **THEN** the list displays that document once

#### Scenario: Missing snapshot fields are safe
- **WHEN** a legacy document detail has an empty product snapshot field
- **THEN** expanded search remains renderable and does not error

