## Purpose

POS sales transactions need to support products with multiple units of sale (e.g., individual units and boxes). When a product is selected by name or base barcode, the cashier should be able to choose how many units to add. Conversion barcodes and serial scans should bypass this choice and use their identified unit automatically.

## Requirements

### Requirement: POS SHALL offer business-scoped unit choice on each eligible product selection
Each name-result selection or base-unit barcode add SHALL show a unit picker when at least one conversion is sales-enabled for the acting business. The picker SHALL adapt the existing bundle-selection card presentation, display the base unit and enabled conversions with their factors, and keep the base unit selectable. Disabled conversions SHALL NOT be selectable. Missing business conversion-price rows SHALL retain existing enabled-by-default semantics.

#### Scenario: Name selection offers units
- **WHEN** a cashier selects a product by name with sales-enabled BOX factor 12
- **THEN** the picker offers the base unit adding 1 and BOX adding 12 base units
- **AND** no cart mutation occurs before the selection flow completes

#### Scenario: Base barcode prompts every time
- **WHEN** a cashier scans the base barcode again after completing an earlier add
- **THEN** the picker appears again even if the product already exists in the cart
- **AND** the previous unit choice is not automatically reused

#### Scenario: Repeated name selection prompts again
- **WHEN** a cashier selects the same eligible name result again
- **THEN** a fresh unit choice is required

#### Scenario: No sales-enabled conversions
- **WHEN** a product has no conversions or all conversions are sales-disabled for the acting business
- **THEN** the unit picker is skipped and the existing base-unit add flow continues

#### Scenario: Business eligibility differs
- **WHEN** BOX is disabled for sales in business A but enabled in business B
- **THEN** BOX is unavailable in A and available in B regardless of purchase enablement

#### Scenario: Missing business conversion-price row
- **WHEN** the acting business has no price row for a product conversion
- **THEN** unit eligibility uses the existing enabled default and pricing retains its existing fallback behavior

### Requirement: POS SHALL route resolved conversion barcodes and serial scans without unit choice
An enabled conversion-barcode match SHALL use its identified conversion without opening the unit picker. An accepted new serial scan SHALL add exactly one base unit without any inherited conversion multiplier. Both paths SHALL preserve existing bundle-intent collection, serial uniqueness, and row-targeting rules.

#### Scenario: Conversion barcode bypasses picker
- **WHEN** a cashier scans an enabled BOX barcode with factor 12
- **THEN** the flow skips unit selection and carries BOX to bundle selection if applicable
- **AND** completing the add contributes 12 base units or 12 bundle units

#### Scenario: Serial scan adds one after BOX selection
- **WHEN** a cashier scans a valid new serial after previously adding BOX factor 12 for the same product
- **THEN** the scan skips unit selection and contributes exactly 1 base unit
- **AND** existing bundle choice and serial assignment rules still apply

#### Scenario: Disabled conversion barcode
- **WHEN** a conversion barcode resolves to a sales-disabled conversion
- **THEN** the system rejects it without adding a base-unit fallback

### Requirement: Unit choice SHALL affect only the added base-unit quantity
The cart SHALL retain existing pricing, tax, rounding, stock, serial, and merge rules. A base-unit choice SHALL add 1; a supported conversion choice SHALL add its factor exactly once. Manual quantity and plus/minus controls SHALL continue operating in base units with existing authorization rules.

#### Scenario: Add BOX to existing matching ordinary row
- **WHEN** an existing merge-compatible row has quantity 3 and the cashier chooses BOX factor 12
- **THEN** the row quantity becomes 15 and existing calculation rules apply to that quantity

#### Scenario: Base unit selected
- **WHEN** the cashier chooses PCS in the unit picker
- **THEN** the add contributes exactly 1 base unit

#### Scenario: Quantity control after BOX addition
- **WHEN** a row has quantity 12 after BOX addition and the cashier presses plus
- **THEN** quantity becomes 13 rather than 24

### Requirement: Server SHALL validate conversion selection before cart mutation
The server SHALL validate conversion ownership, current-business sales enablement, and a finite integer factor greater than 1 for every conversion-based add. It SHALL reject invalid selections with an actionable message and no cart mutation. Client-supplied factors SHALL NOT override stored factors.

#### Scenario: Conversion disabled while picker is open
- **WHEN** BOX becomes sales-disabled after options load but before submission
- **THEN** submission fails without changing the cart or falling back to PCS

#### Scenario: Fractional factor submitted
- **WHEN** a picker, conversion-barcode, or direct cart add references factor 2.5
- **THEN** the server rejects the addition rather than truncating it to 2

#### Scenario: Invalid factor or foreign conversion
- **WHEN** a submitted conversion belongs to another product or has a non-finite factor or factor less than or equal to 1
- **THEN** the server rejects the request before mutation

### Requirement: Selection lifecycle SHALL prevent cancelled or duplicate additions
Cancelling either selection modal SHALL leave the cart unchanged. A single pending selection SHALL submit at most once despite repeated clicks or Enter activation. Late responses and modal cleanup from cancelled or previous operations SHALL NOT mutate or replace a later selection.

#### Scenario: Cancel unit picker
- **WHEN** the cashier closes the unit picker
- **THEN** no line is added and pending unit/bundle context is cleared

#### Scenario: Cancel bundle picker after choosing BOX
- **WHEN** the cashier chooses BOX and then cancels bundle selection
- **THEN** no quantity is added and BOX is not inherited by the next add

#### Scenario: Duplicate activation
- **WHEN** the cashier double-clicks an option or repeatedly presses Enter during one selection submission
- **THEN** only one add request is submitted for that operation

#### Scenario: Late response after cancellation
- **WHEN** an options response arrives after its operation was cancelled
- **THEN** it does not reopen the picker or add a product
