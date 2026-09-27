## ADDED Requirements

### Requirement: PKP Default Tax Fallback
The system SHALL prioritize product-specific tax configurations for PKP businesses, but MUST fallback to the system default tax or the first available tax if no explicit mapping exists, ensuring the cart retains a valid tax ID.

#### Scenario: Product without specific tax added to PKP cart
- **WHEN** a product is added to a sales cart in a PKP-enabled business environment, and the product lacks a specific sale tax mapping
- **THEN** the system MUST auto-assign the explicit default tax (if one exists), or the first available tax in the system (if no default exists).

### Requirement: Proper Binding of PKP Tax Validation Errors
The system SHALL NOT silently swallow PKP tax validation errors by binding them to unrelated input fields. Instead, it MUST provide clear, visible feedback when a cart item is missing a required tax ID.

#### Scenario: Saving a cart with missing taxes
- **WHEN** the user attempts to save a sale and the PKP validation confirms a missing tax ID on any cart item
- **THEN** the system MUST emit a visible flash notification (e.g., dispatch 'notify' with type 'error') or bind the validation failure to a general level that halts processing and alerts the user immediately, rather than failing silently on the `paymentTermId` field.
