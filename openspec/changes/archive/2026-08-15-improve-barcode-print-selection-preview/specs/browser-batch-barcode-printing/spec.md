## ADDED Requirements

### Requirement: Product suggestions expose label-identifying information
The Print Barcode product-search suggestions SHALL display each matching product's name, SKU (`products.product_code`), primary barcode (`products.barcode`), unit, and formatted non-tier `product_prices.sale_price` for the authorized selected business. The search input SHALL communicate that name, SKU, and primary-barcode input are supported. Suggestion pricing SHALL follow the existing document-business authorization rules and SHALL NOT fall back to a tier price, another business's price, `products.product_price`, or zero.

#### Scenario: Matching suggestion shows barcode and selected-business price
- **WHEN** an authorized operator searches by product name, SKU, or all or part of a primary barcode
- **THEN** each matching suggestion SHALL show its primary barcode and formatted `sale_price` for the authorized selected business
- **AND** the suggestion SHALL retain the existing product name, SKU, and unit information

#### Scenario: Suggestion price follows an authorized business change
- **WHEN** an operator authorized for document-business override changes the selected business
- **THEN** subsequent suggestions SHALL show prices from that business's matching `product_prices` rows
- **AND** prices previously resolved for another business SHALL NOT remain displayed as current values

#### Scenario: Product lacks a selected-business sale price
- **WHEN** a matching product has no price row or has a null `sale_price` for the authorized selected business
- **THEN** the suggestion SHALL identify that the selected-business sale price is unavailable
- **AND** it SHALL NOT display a substitute price

#### Scenario: Search does not expose an unauthorized business price
- **WHEN** the requested selected business cannot be resolved under the current user's document-business authorization
- **THEN** the search component SHALL NOT expose prices for that business
- **AND** the workspace SHALL provide actionable authorization feedback

### Requirement: Selected product rows provide immediate label previews
Each selected-product row in the Print Barcode batch workspace SHALL include a rightmost preview column showing one compact label representation for that product. The preview SHALL use the same product name, deterministic displayed SKU, primary barcode value, barcode SVG rendering rules, and formatted authorized selected-business non-tier sale price as the printable label. A row's requested quantity SHALL NOT duplicate its compact preview, and existing quantity, removal, merging, expanded batch preview, and print behavior SHALL remain available.

#### Scenario: Selecting a printable product shows its label immediately
- **WHEN** an authorized operator adds a product with a printable primary barcode and valid selected-business sale price
- **THEN** its selected-product row SHALL show one compact label preview without requiring the operator to request the expanded batch preview
- **AND** the preview SHALL contain the product name, displayed SKU, barcode SVG, barcode value, and formatted selected-business sale price

#### Scenario: Quantity changes do not duplicate the row preview
- **WHEN** an operator increases a selected product's requested label quantity
- **THEN** the workspace SHALL retain one selected-product row and one compact preview for that product
- **AND** the aggregate label count and eventual expanded batch SHALL reflect the requested quantity

#### Scenario: Authorized business change refreshes row previews
- **WHEN** an authorized operator changes the selected business while products are selected
- **THEN** every selected-product preview SHALL be rebuilt using the newly authorized business's `sale_price`
- **AND** no preview SHALL continue presenting a prior business's price as current

#### Scenario: Selected product cannot currently produce a valid label
- **WHEN** a selected product has a blank barcode, an invalid explicitly EAN-13 barcode, or no valid selected-business sale price
- **THEN** its preview column SHALL show an actionable product-specific state instead of a misleading label preview
- **AND** the existing server-side expanded-preview and print validation SHALL continue to reject the invalid batch

#### Scenario: Preview and printable label share rendering semantics
- **WHEN** a product's stored symbology requires normalization, EAN-13 validation, or Code 128 fallback
- **THEN** the compact row preview SHALL apply the same renderer selection and SKU display rule as the final printable label
- **AND** the final print endpoint SHALL remain authoritative by reloading and revalidating current product and price data

