## ADDED Requirements

### Requirement: Operational purchase lines display the current product name
The system SHALL display the current non-empty name of the product linked to a purchase line on interactive purchase detail and receiving surfaces.

#### Scenario: Linked purchase product has been renamed
- **WHEN** a user opens an operational purchase detail or receiving surface after the linked product name has changed
- **THEN** the purchase line displays the product's current name
- **AND** it does not display the obsolete persisted name as the primary product label

#### Scenario: Linked purchase product is inactive
- **WHEN** a purchase line references an inactive product that remains resolvable
- **THEN** the purchase line displays that product's current name

### Requirement: Operational sales lines display the current product name
The system SHALL display the current non-empty name of the product linked to a sales line on interactive sales detail, dispatch-related, and list-preview surfaces.

#### Scenario: Linked sales product has been renamed
- **WHEN** a user opens an operational sales surface after the linked product name has changed
- **THEN** the sales line displays the product's current name
- **AND** it does not display the obsolete persisted name as the primary product label

#### Scenario: Sales bundle component has been renamed
- **WHEN** an operational sales surface displays a bundle or standalone component linked to a renamed product
- **THEN** the component displays the linked product's current name

### Requirement: Persisted names provide a safe display fallback
The system SHALL retain and display the persisted transaction-line name when the linked product or its current non-empty name cannot be resolved.

#### Scenario: Purchase product relationship is unavailable
- **WHEN** a purchase line's linked product cannot be resolved
- **THEN** the operational surface displays the purchase line's persisted product name

#### Scenario: Sales product relationship is unavailable
- **WHEN** a sales or bundle line's linked product cannot be resolved
- **THEN** the operational surface displays that line's persisted product name

#### Scenario: Current product name is blank
- **WHEN** a linked product resolves but its current product name is empty or whitespace
- **THEN** the operational surface displays the line's persisted product name

### Requirement: Product renaming does not rewrite transaction snapshots
The system MUST NOT update persisted purchase, sales, or bundle line names solely because a linked product is renamed.

#### Scenario: Product name is updated
- **WHEN** an authorized user changes a product's name
- **THEN** existing purchase, sales, and bundle snapshot-name columns remain unchanged
- **AND** operational surfaces resolve the updated name through the linked product

### Requirement: Commercial documents display current product names
The system SHALL use the current non-empty linked product name as the primary product label in generated and regenerated purchase and sales invoices and other printable commercial documents.

#### Scenario: Invoice is regenerated after product rename
- **WHEN** a user regenerates an existing sales invoice after a linked product has been renamed
- **THEN** the invoice displays the linked product's current name

#### Scenario: Printable document product is unavailable
- **WHEN** a printable purchase or sales document contains a line whose linked product or current non-empty name cannot be resolved
- **THEN** the document displays the persisted transaction-line product name

### Requirement: Purchase and sales exports display current product names
The system SHALL use the current non-empty linked product name for product-name labels in purchase and sales exports while retaining persisted names as fallback.

#### Scenario: Export is generated after product rename
- **WHEN** a user generates a purchase or sales export after a linked product has been renamed
- **THEN** each affected exported product-name label contains the linked product's current name

#### Scenario: Exported product is unavailable
- **WHEN** an exported purchase or sales line has no resolvable linked product or non-empty current name
- **THEN** its product-name label contains the persisted transaction-line product name

### Requirement: Current-name display avoids per-line product queries
The system SHALL load linked products in a bounded manner when rendering collections of transaction lines that use the current-name display rule.

#### Scenario: Output contains multiple transaction lines
- **WHEN** a purchase or sales screen, printable document, or export renders multiple lines with current product names
- **THEN** resolving those names does not execute one additional product query per line
