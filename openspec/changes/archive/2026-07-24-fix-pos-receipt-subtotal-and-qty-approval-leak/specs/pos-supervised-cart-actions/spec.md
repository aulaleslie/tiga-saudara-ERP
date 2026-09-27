## MODIFIED Requirements

### Requirement: Supervised Qty Slot MUST Preserve Existing Approval Semantics Under Compact Layout
For users without direct quantity-reduction permission, compact spinner rendering MUST NOT alter existing supervised approval slot behavior. The quantity-reduce slot MUST reflect only QTY_REDUCE approval requests for that line; approval requests of other action types (such as PRICE_OVERRIDE) for the same line MUST NOT change the quantity-reduce slot state.

#### Scenario: Pending supervised request keeps Periksa state in left slot
- **WHEN** a non-privileged row has a pending qty-reduction request
- **THEN** the left spinner slot MUST render `Periksa` bound to the active request while qty input and plus control remain aligned in the same compact row.

#### Scenario: Approved supervised request keeps proceed state in left slot
- **WHEN** a non-privileged row has an approved qty-reduction request
- **THEN** the left spinner slot MUST render approved proceed state with token/approved-qty context without changing the compact row order.

#### Scenario: Pending price override does not alter the quantity-reduce slot
- **WHEN** a non-privileged row has a pending or approved PRICE_OVERRIDE request but no QTY_REDUCE request
- **THEN** the quantity-reduce slot MUST render its normal reduce (−) control
- **AND** the quantity-reduce slot MUST NOT render `Periksa` or an approved proceed state

#### Scenario: Independent quantity and price approval states coexist on one line
- **WHEN** a non-privileged row has both a pending QTY_REDUCE request and a pending PRICE_OVERRIDE request
- **THEN** the quantity-reduce slot MUST reflect only the QTY_REDUCE request state
- **AND** the price control MUST reflect only the PRICE_OVERRIDE request state
