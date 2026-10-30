## MODIFIED Requirements

### Requirement: POS SHALL define a debt-checkout permission governing direct vs. approval-required behavior
The POS permission registry SHALL include `pos.checkout.debt` for direct debt checkout and `pos.checkout.debt.request` for approval-required debt checkout. Users holding direct permission SHALL self-authorize. Users lacking direct permission but holding request permission SHALL require supervisory approval at completion. Users holding neither SHALL NOT request or complete debt checkout, even with an approval token. Super Admin SHALL retain the established bypass. Both permissions SHALL be registered in parity with runtime checks. Administrators SHALL assign the new request permission explicitly; the change SHALL NOT automatically grant it to existing users, roles, or bundles.

#### Scenario: Direct permission holder self-authorizes
- **WHEN** a user holds `pos.checkout.debt`, with or without request permission
- **THEN** the system SHALL authorize debt checkout without supervisor approval or Lunas permission

#### Scenario: Request permission holder needs approval
- **WHEN** a user holds request permission but lacks direct debt permission
- **THEN** they SHALL be allowed to prepare Kasbon and request supervisor approval
- **AND** completion SHALL require a valid approval under existing supervisor and token rules

#### Scenario: Neither permission is present
- **WHEN** a user holds neither debt permission
- **THEN** debt approval request creation and debt completion SHALL be denied
- **AND** Lunas permission or an approval token SHALL NOT substitute for debt access

#### Scenario: Permission is revoked after approval
- **WHEN** a request-only user's request permission is revoked before completion
- **THEN** completion SHALL be denied even if an approval token was previously issued

#### Scenario: Registry and assignment parity
- **WHEN** permissions are synchronized and displayed for assignment
- **THEN** both debt permissions SHALL be available in the POS assignment surface
- **AND** existing user, role, and bundle assignments SHALL NOT receive automatic request permission grants
