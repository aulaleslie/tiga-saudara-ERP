# product-barcode-initialization Specification

## Purpose
TBD - created by archiving change add-product-barcode-initialization. Update Purpose after archive.
## Requirements
### Requirement: Authorized users can access a focused barcode workspace
The system SHALL provide a dedicated product barcode initialization workspace protected by a barcode-management permission that is independent from general product-edit permission.

#### Scenario: Authorized barcode operator opens the workspace
- **WHEN** a user with the barcode-management permission opens the barcode initialization route
- **THEN** the system SHALL display the barcode initialization workspace
- **AND** the navigation entry SHALL be visible to that user

#### Scenario: Unauthorized user attempts access
- **WHEN** a user without the barcode-management permission requests the barcode initialization route or invokes its save action
- **THEN** the system SHALL reject the request with an authorization failure
- **AND** the navigation entry SHALL not be visible to that user

### Requirement: Product search supports catalog-wide barcode initialization
The workspace SHALL search products visible under the existing product-access scope by product name or product code, regardless of whether stock management is enabled, and SHALL display each result's current base-unit barcode status.

#### Scenario: Search finds stock-managed and stockless products
- **WHEN** an authorized user enters a term matching a stock-managed product and a stockless product
- **THEN** both matching products SHALL be eligible to appear in the results

#### Scenario: Search result communicates current barcode status
- **WHEN** matching results include one product without a base-unit barcode and one product with a base-unit barcode
- **THEN** the system SHALL identify the first result as uninitialized
- **AND** the system SHALL display the current barcode for the second result

#### Scenario: Uninitialized filter is active
- **WHEN** the user enables the uninitialized-products filter
- **THEN** the results SHALL exclude products that already have a base-unit barcode

### Requirement: Product selection prepares the scanner without extra interaction
After the user selects a product, the system SHALL display the selected product's identifying information and base unit, visually highlight the barcode input, and move keyboard focus to that input.

#### Scenario: Product without barcode is selected
- **WHEN** the user selects an uninitialized product from search results
- **THEN** the barcode input SHALL become visibly ready for scanning
- **AND** keyboard focus SHALL move to the empty barcode input

#### Scenario: Product with barcode is selected for replacement
- **WHEN** the user selects a product that already has a base-unit barcode
- **THEN** the system SHALL display its current barcode
- **AND** the barcode input SHALL be prepared so a new scan replaces the candidate input without silently overwriting the stored value

### Requirement: Scanning captures a candidate without saving
The system SHALL treat a scanner terminator or barcode-input submission as candidate capture only, SHALL preserve the barcode as a string including leading zeroes, and MUST NOT persist it until a separate confirmation action occurs.

#### Scenario: Hardware scanner submits a barcode
- **WHEN** a focused barcode input receives `0012345678905` followed by the scanner's Enter terminator
- **THEN** the candidate SHALL remain `0012345678905`
- **AND** the product's stored barcode SHALL remain unchanged
- **AND** the workspace SHALL transition to confirmation state

#### Scenario: User cancels a captured candidate
- **WHEN** the user cancels from confirmation state
- **THEN** the candidate SHALL be discarded
- **AND** the selected product's stored barcode SHALL remain unchanged
- **AND** focus SHALL return to the barcode input

### Requirement: Candidate preview and value are immediately reviewable
After candidate capture, the system SHALL show the complete candidate value and a machine-readable visual barcode preview representing that value before confirmation.

#### Scenario: Valid candidate is captured
- **WHEN** a candidate passes input-format validation
- **THEN** the complete candidate value SHALL be displayed without numeric coercion
- **AND** a visual preview SHALL be rendered from the same value
- **AND** the confirmation action SHALL become available

#### Scenario: Preview cannot be generated
- **WHEN** the candidate cannot be represented by the supported preview symbology
- **THEN** the system SHALL explain that the candidate cannot be previewed
- **AND** confirmation SHALL remain unavailable until the value is corrected

### Requirement: Assignment and replacement require explicit confirmation
The system SHALL require explicit confirmation for every barcode save and SHALL distinguish first-time initialization from replacement of an existing barcode.

#### Scenario: User confirms first-time assignment
- **WHEN** an uninitialized product has a valid candidate and the user activates confirmation
- **THEN** the system SHALL save the candidate as the product's base-unit barcode

#### Scenario: User reviews replacement
- **WHEN** a product with an existing barcode has a different valid candidate
- **THEN** the confirmation state SHALL display the old and new values together
- **AND** the action SHALL be labeled as a replacement rather than an initialization

#### Scenario: Candidate equals existing barcode
- **WHEN** the candidate is identical to the selected product's stored barcode
- **THEN** the system SHALL report that no change is required
- **AND** it SHALL not create a new assignment-history record

### Requirement: Barcode identity is validated across base and conversion units
Before persistence, the system SHALL validate a non-empty candidate against base-unit barcodes on other products and barcodes on all product unit conversions. A barcode assigned anywhere in that namespace MUST NOT be assigned to another product or unit.

#### Scenario: Candidate belongs to another product
- **WHEN** the candidate matches another product's base-unit barcode
- **THEN** the save SHALL be rejected
- **AND** the error SHALL identify the conflicting product sufficiently for the user to correct the selection

#### Scenario: Candidate belongs to a conversion unit
- **WHEN** the candidate matches a product unit-conversion barcode
- **THEN** the save SHALL be rejected
- **AND** the error SHALL identify the conflicting product and conversion unit sufficiently for correction

#### Scenario: Concurrent assignment attempts use the same barcode
- **WHEN** two users attempt to confirm the same previously unused candidate for different products concurrently
- **THEN** at most one assignment SHALL succeed
- **AND** the other attempt SHALL receive a duplicate-barcode result without overwriting either product

### Requirement: Save protects against stale product state
The system SHALL re-read and lock the selected product during confirmation and MUST reject a save when its barcode changed after the user selected it.

#### Scenario: Another user changes the selected product before confirmation
- **WHEN** the stored barcode differs from the original value shown when the current user selected the product
- **THEN** the current user's confirmation SHALL not overwrite the newer value
- **AND** the workspace SHALL show the latest stored value and require a fresh review

### Requirement: Every successful barcode mutation is auditable
The system SHALL record each successful initialization or replacement with the product, previous value, new value, acting user, action type, and timestamp in durable barcode assignment history.

#### Scenario: First barcode is initialized
- **WHEN** a user successfully assigns a product's first barcode
- **THEN** an initialization history record SHALL be created with a null previous value and the new value

#### Scenario: Existing barcode is replaced
- **WHEN** a user successfully replaces a stored barcode
- **THEN** a replacement history record SHALL be created with both old and new values

#### Scenario: Save fails validation or authorization
- **WHEN** an assignment attempt fails before persistence
- **THEN** no successful assignment-history record SHALL be created

### Requirement: Successful saves support a rapid repeat workflow
After a successful save, the workspace SHALL provide unambiguous success feedback, update session progress, clear the selected product and candidate, and return focus to product search.

#### Scenario: User saves and continues to the next product
- **WHEN** a barcode assignment succeeds
- **THEN** the saved product and barcode SHALL be shown in recent session activity
- **AND** the session's saved count SHALL increase
- **AND** the product search SHALL be cleared and focused for the next lookup

#### Scenario: Save fails
- **WHEN** confirmation fails because of validation, duplication, or stale state
- **THEN** the selected product and candidate SHALL remain visible
- **AND** the workspace SHALL guide focus back to the field or action needed to correct the failure

