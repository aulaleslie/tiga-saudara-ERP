## MODIFIED Requirements

### Requirement: Ignore Incoming Product Tax When Non-PKP
The system MUST actively ignore any pre-assigned or incoming `tax_id` from the Cartesian details when processing sale or purchase row inserts or updates if the `is_pkp` setting is FALSE. In this non-PKP context, the resulting saved row details MUST have `tax_id = null` and a calculated `product_tax_amount = 0`, overriding whatever the cart or product model initially supplied. For non-PKP sale persistence, the system MUST also recompute persisted line subtotals and header totals using tax-excluded values so hidden or restored tax-bearing cart state cannot survive as gross saved amounts.

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

#### Scenario: Storing a non-PKP sale with hidden tax-bearing cart economics
- **WHEN** a user Submits a Sale Cart (Store or Update) and `is_pkp` is false
- **AND** a sale cart row contains hidden or restored tax-bearing values such as `product_tax_amount > 0` or a tax-inflated `sub_total`
- **THEN** the backend process SHALL persist the row with `tax_id = null`
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`
- **AND** the backend process SHALL persist the row `sub_total` using the tax-excluded amount
- **AND** the backend process SHALL recompute sale `tax_amount = 0`
- **AND** the backend process SHALL recompute sale `total_amount` and `due_amount` from normalized non-tax line economics.

#### Scenario: Re-saving a restored non-PKP sale edit does not preserve hidden tax
- **WHEN** a user opens a Sale Edit flow while `is_pkp` is false
- **AND** restored cart state contains line values derived from previously tax-bearing sale details
- **AND** the user updates and saves the sale
- **THEN** the persisted sale details SHALL not retain hidden tax-bearing economic amounts
- **AND** the persisted sale header SHALL reflect normalized tax-excluded totals for non-PKP mode.
