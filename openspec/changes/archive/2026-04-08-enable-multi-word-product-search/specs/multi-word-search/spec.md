## ADDED Requirements

### Requirement: Tokenized Multi-word Execution
The system should tokenize product search strings by spaces and apply a combined `AND` clause linking the tokens. Each token represents an `OR` group encompassing multiple column checks.

#### Scenario: Searching Partial Terms Out of Order
- **WHEN** user searches for "SAMS GAL FO"
- **THEN** the product search should correctly return "SAMSUNG GALAXY FOLD" since all tokens exist within the queryable data for that product.

### Requirement: Global Index Parity Match
The sales and purchase product searches should match against `product_name`, `product_code`, `category.category_name`, and `brand.name`.

#### Scenario: Searching by category name
- **WHEN** user searched for "Laptop"
- **THEN** it returns products containing "Laptop" in their category name, even if the word is not explicitly in the product name.
