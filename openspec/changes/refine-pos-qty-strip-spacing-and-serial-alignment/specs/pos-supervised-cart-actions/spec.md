## ADDED Requirements

### Requirement: Qty Column Controls MUST Use Compact Semantic Spinner Styling
The POS sell UI SHALL render quantity controls using a compact spinner composition for both privileged and non-privileged cart rows, with semantic directional styling for decrease and increase actions.

#### Scenario: Non-serial row renders compact spinner structure
- **WHEN** a non-serial cart row is rendered
- **THEN** the qty cell MUST render controls in compact order `[- or supervised slot][qty input][+]` with minimal inter-control spacing.

#### Scenario: Serial-required row renders compact spinner as top row
- **WHEN** a serial-required cart row is rendered
- **THEN** the qty cell MUST render the same compact spinner structure as the top row before serial-specific controls.

#### Scenario: Spinner action colors follow directional semantics
- **WHEN** the qty spinner renders idle action controls
- **THEN** the decrease control MUST use danger-outline styling and the increase control MUST use primary-outline styling while preserving existing button radius and size conventions.

### Requirement: Supervised Qty Slot MUST Preserve Existing Approval Semantics Under Compact Layout
For users without direct quantity-reduction permission, compact spinner rendering MUST NOT alter existing supervised approval slot behavior.

#### Scenario: Pending supervised request keeps Periksa state in left slot
- **WHEN** a non-privileged row has a pending qty-reduction request
- **THEN** the left spinner slot MUST render `Periksa` bound to the active request while qty input and plus control remain aligned in the same compact row.

#### Scenario: Approved supervised request keeps proceed state in left slot
- **WHEN** a non-privileged row has an approved qty-reduction request
- **THEN** the left spinner slot MUST render approved proceed state with token/approved-qty context without changing the compact row order.
