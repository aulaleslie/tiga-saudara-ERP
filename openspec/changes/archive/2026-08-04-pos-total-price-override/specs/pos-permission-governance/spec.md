## ADDED Requirements

### Requirement: POS SHALL define a total-price-override permission governing direct versus supervised behavior
The POS permission registry SHALL include `pos.overrides.total-price` for cart-total overrides. Users holding it directly SHALL self-authorize total-price overrides; users lacking it SHALL require supervisory approval; Super Admin SHALL bypass. The permission SHALL be represented in the POS exception capability cluster and in the supported manager bundle that is authorized to approve this action.

#### Scenario: Direct permission holder self-authorizes total override
- **WHEN** a user holds `pos.overrides.total-price` and submits a valid cart target total
- **THEN** the system MUST authorize the action without an approval request

#### Scenario: Missing total-override permission requires approval
- **WHEN** a user lacks `pos.overrides.total-price` and submits a valid cart target total
- **THEN** the system MUST require supervisory approval
- **AND** MUST report an approval-required outcome

#### Scenario: Permission registry and role matrix remain in parity
- **WHEN** the POS permission registry and supported role bundles are validated against runtime authorization
- **THEN** `pos.overrides.total-price` MUST be present in the registry and exception capability cluster
- **AND** the supported manager bundle MUST include it
