## ADDED Requirements

### Requirement: Ignore Incoming Product Tax When Non-PKP
The system MUST actively ignore any pre-assigned or incoming `tax_id` from the Cartesian details when processing row inserts or updates if the `is_pkp` setting is FALSE. In this non-PKP context, the resulting saved row details MUST have `tax_id = null` and a calculated `product_tax_amount = 0`, overriding whatever the cart or product model initially supplied.

#### Scenario: Storing a purchase with a tax-assigned product when Non-PKP
- **WHEN** a user Submits a Purchase Cart (Store or Update) and `is_pkp` is false
- **AND** a Cartesian row has a `tax_id` populated (e.g., from the Product default)
- **THEN** the backend process SHALL intercept this and set `tax_id = null` 
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`.

#### Scenario: Storing a sale with a tax-assigned product when Non-PKP
- **WHEN** a user Submits a Sale Cart (Store or Update) and `is_pkp` is false
- **AND** a Cartesian row has a `tax_id` populated (e.g., from the Product default)
- **THEN** the backend process SHALL intercept this and set `tax_id = null`
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`.
