## MODIFIED Requirements

### Requirement: Mapping session payments for receipt consistency
The `checkoutFinalize` logic SHALL map session payments for the finalize service, but the receipt generation logic SHALL use the `amount` accessor on `PosCheckoutPayment` entities to ensure consistency with the database schema (`amount_minor_units`).

#### Scenario: Receipt nominal mapping
- **WHEN** `PosReceiptService::getReceiptData()` loads `PosCheckoutPayment`
- **THEN** it MUST use `$payment->amount` to retrieve the decimal value, not `$payment->amount_paid`
