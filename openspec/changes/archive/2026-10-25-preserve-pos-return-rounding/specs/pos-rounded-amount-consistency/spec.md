## MODIFIED Requirements

### Requirement: Downstream monetary allocations reconcile to captured POS amounts
Checkout, generated sales, applied payment allocations, reports, and returns SHALL consume captured charged amounts and reconcile in minor units. Owner, bundle, and internal return fragments SHALL use deterministic remainder allocation without independent increment rounding. Commercial POS cash-return amounts SHALL follow the cumulative return-rounding policy, with final residual allocation preserving the captured returnable charge. Cash tendered minus change, other applied payments, and outstanding debt SHALL reconcile to the billed amount under the existing payment model.

#### Scenario: Discounts and tax reconcile after row rounding
- **WHEN** an automatic row receives a row discount, increment rounding, and an allocated bill discount with applicable tax
- **THEN** the row discount SHALL be applied once and the bill discount SHALL be applied once
- **AND** tax and pre-tax allocations SHALL reconcile to the final charged amount
- **AND** no additional grand-total increment rounding SHALL occur

#### Scenario: Owner and payment allocations preserve the charge
- **WHEN** a rounded commercial row is split across owners and paid through multiple methods
- **THEN** generated owner sales and applied payment allocations SHALL reconcile to their respective captured charge totals
- **AND** reports SHALL reflect the same transaction charge

#### Scenario: Partial returns exhaust the original value
- **WHEN** successive eligible partial returns exhaust a rounded source row after the business increment changes
- **THEN** their allocated source valuations SHALL sum to the original returnable charged amount
- **AND** commercial cash-return valuations SHALL use the increment frozen at the first committed cash return for the source sale
- **AND** internal return fragments SHALL NOT receive independent increment rounding
