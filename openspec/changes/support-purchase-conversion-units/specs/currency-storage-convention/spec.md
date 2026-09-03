## MODIFIED Requirements

### Requirement: Decimal Rupiah Storage Outside POS

All monetary values outside the POS module SHALL be stored as `decimal(15,2)` columns representing rupiah directly — no unit conversion or scaling applied — except as explicitly modified for canonical Purchase unit prices. This includes:

- `sales` (total_amount, tax_amount, discount_amount, shipping_amount)
- `sale_details` (price, unit_price, sub_total, product_discount_amount, product_tax_amount)
- `sale_payments` (amount)
- `sale_returns` (total_amount, paid_amount)
- `sale_return_payments` (amount)
- `purchases` (total_amount, tax_amount, discount_amount, shipping_amount, paid_amount, due_amount)
- `purchase_details` (sub_total, product_discount_amount, product_tax_amount)
- `purchase_payments` (amount)
- `purchase_returns` (total_amount, paid_amount)
- `purchase_return_payments` (amount)
- `expenses` (amount)
- `products` (product_cost, product_price)
- `quotations` (total_amount, tax_amount, discount_amount, shipping_amount)
- `quotation_details` (price, unit_price, sub_total, product_discount_amount, product_tax_amount)

As an explicit capability modification for Purchase UOM conversions:
- `purchase_details.price` and `purchase_details.unit_price` SHALL be stored as `decimal(15,6)` columns to preserve high-precision canonical base-unit prices (such as `100000.00 / 3 = 33333.333333`) without costing drift in production databases.

#### Scenario: High-precision canonical purchase detail unit price
- **WHEN** a Purchase detail line is created with a repeating base-unit price
- **THEN** the system SHALL store `purchase_details.unit_price` and `price` with six decimal places of precision (`decimal(15,6)`)
- **AND** Purchase totals and other monetary columns SHALL remain stored as standard two-decimal rupiah values (`decimal(15,2)`)
