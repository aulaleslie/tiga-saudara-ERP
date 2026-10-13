# Spec Delta

## ADDED Requirements

### Requirement: Expense approval requests are isolated by expense
The system SHALL distinguish approval requests for different expenses made by the same user while preventing repeated concurrent processing of an approval for the same expense.

#### Scenario: Approve distinct expenses consecutively
- **WHEN** an authorized user approves one submitted expense and immediately approves a different submitted expense in the current setting
- **THEN** both expenses MUST be approved without waiting for an idempotency timeout

#### Scenario: Repeat approval while first request is protected
- **WHEN** the same user repeats an approval request for the same expense while its idempotency claim is active
- **THEN** the repeated request MUST NOT run approval processing a second time
- **AND** the user MUST see a clear explanation that the request was already received or processed

#### Scenario: Approval failure can be retried
- **WHEN** approval fails before its changes are committed and the expense remains submitted
- **THEN** the user MUST be able to retry approval without waiting for the idempotency timeout

#### Scenario: Approval rejection is visible on expense screens
- **WHEN** an approval request is rejected by duplicate protection from the expense list or show page
- **THEN** the resulting page MUST display the rejection message

