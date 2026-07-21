## ADDED Requirements

### Requirement: POS SHALL define a debt-checkout permission governing direct vs. approval-required behavior
The POS permission registry SHALL include a `pos.checkout.debt` permission that governs finishing a transaction as debt. Users holding it directly SHALL self-authorize the debt path; users lacking it SHALL require supervisory approval; Super Admin SHALL bypass. The permission SHALL be registered in parity with runtime authorization checks.

#### Scenario: Direct permission holder self-authorizes
- **WHEN** a user holds `pos.checkout.debt` directly and finishes a transaction as debt
- **THEN** the system MUST authorize the action without an approval request

#### Scenario: Missing permission requires approval
- **WHEN** a user lacks `pos.checkout.debt` and attempts to finish as debt
- **THEN** the system MUST require supervisory approval and MUST report an approval-required outcome

#### Scenario: Debt-checkout permission is registered in parity
- **WHEN** the POS permission registry is validated against runtime authorization
- **THEN** `pos.checkout.debt` MUST be present in the registry and map to the debt-checkout authorization check
