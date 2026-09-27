## ADDED Requirements

### Requirement: UOM normalization is distinct from received-purchase monetary correction
The system SHALL expose received-purchase UOM normalization as a separate privileged workflow from monetary correction and SHALL not allow the monetary correction workflow to alter received quantities, receipt identity, inventory transactions, or HPP solely for a UOM error.

#### Scenario: User opens a received purchase with both workflows available
- **WHEN** an authorized user views an eligible received purchase
- **THEN** the system SHALL present monetary correction and UOM normalization as distinct actions with their respective permissions and purposes

#### Scenario: Monetary correction remains quantity-safe
- **WHEN** a user submits the existing received-purchase monetary correction workflow
- **THEN** the system SHALL continue to preserve purchase-detail and receiving-detail quantities and inventory transaction quantities

### Requirement: UOM normalization requires dedicated authority and reason
The system SHALL require Super Admin or a user granted the dedicated received-purchase UOM-normalization authority to preview or execute normalization, and SHALL require a non-empty reason for execution.

#### Scenario: Unauthorized user is denied normalization
- **WHEN** a user without UOM-normalization authority attempts to access preview or execution endpoints
- **THEN** the system SHALL deny access and SHALL not reveal or change normalization data

#### Scenario: Missing reason blocks execution
- **WHEN** an authorized user attempts to execute a normalization without a reason
- **THEN** the system SHALL reject the request without changing purchase, receipt, stock, transaction, or cost data
