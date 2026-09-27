## ADDED Requirements

### Requirement: Finish-as-debt checkout MUST require supervisory approval for non-authorized users
The POS system SHALL treat finishing a transaction as debt as a supervised action that follows the same request → approve → token-consume flow as restricted cart mutations. When the acting user lacks direct permission for the action, the system MUST create an approval request and MUST NOT post the debt sale, UNLESS the user has Super Admin role.

#### Scenario: Non-authorized user requests debt checkout
- **WHEN** a Cashier Staff user attempts finish-as-debt without direct debt-checkout permission
- **THEN** the system MUST create an approval request of the debt-checkout action type and MUST NOT post the sale immediately

#### Scenario: Authorized user completes debt checkout directly
- **WHEN** a user holding the direct debt-checkout permission completes finish-as-debt
- **THEN** the system MUST post the debt sale immediately without creating an approval request

#### Scenario: Super Admin completes debt checkout without approval
- **WHEN** a Super Admin user completes finish-as-debt
- **THEN** the system MUST post the debt sale immediately based solely on Super Admin role, without creating an approval request

#### Scenario: Approved debt request issues execution token consumed at finalize
- **WHEN** a supervisor approves a pending debt-checkout request and the requester finalizes the debt checkout with the issued token
- **THEN** the system MUST validate and consume the one-time token for the debt-checkout action before posting the sale, and MUST reject finalize if the token is missing, expired, or for a different action
