# Customer CRUD Observability

## Purpose
Define logging and user-facing error handling requirements for customer save failures.

## Requirements
### Requirement: Error Logging on Save Failure

When a database exception or any other error prevents a customer from being created, the system MUST log the error. When the error is an integrity constraint violation (duplicate entry), the system SHALL redirect back with a user-friendly validation error message identifying the conflicting field, instead of showing the generic "Hubungi Administrator" toast.

#### Scenario: Duplicate email caught at database level
- **WHEN** customer creation fails due to a duplicate `customer_email` constraint violation
- **THEN** the system SHALL log the error at error level
- **AND** the system SHALL redirect back with a validation error "Email sudah digunakan" on the `customer_email` field
- **AND** the system SHALL NOT show the generic "Hubungi Administrator" toast

#### Scenario: Duplicate phone caught at database level
- **WHEN** customer creation fails due to a duplicate `customer_phone` constraint violation
- **THEN** the system SHALL log the error at error level
- **AND** the system SHALL redirect back with a validation error "Nomor telepon sudah digunakan" on the `customer_phone` field

#### Scenario: Unknown database error
- **WHEN** customer creation fails due to a non-constraint database error
- **THEN** the system SHALL log the error at error level with full trace
- **AND** the system SHALL show the generic "Hubungi Administrator" toast

### Requirement: Payload Inclusion

The error log MUST include the incoming request data payload to assist in reproducing the issue, ensuring no highly sensitive data breaks privacy rules.

#### Scenario: Logged error includes request payload
- **WHEN** customer creation fails and the system writes an error log
- **THEN** the log entry SHALL include the incoming request payload needed to reproduce the issue
- **AND** the logged payload SHALL omit or sanitize highly sensitive data as required

### Requirement: Execution Tracing

The system SHALL log debug-level information immediately prior to attempting the database transaction, allowing administrators to trace the execution path.

#### Scenario: Save attempt emits debug trace before database write
- **WHEN** the system is about to attempt customer persistence
- **THEN** the system SHALL emit debug-level trace information before the database transaction starts
- **AND** the trace SHALL provide enough context to identify the execution path leading to the save attempt

### Requirement: Resilience

The logging mechanism itself MUST NOT disrupt the user flow; the original error or a generic error response MUST still be returned to the client appropriately.

#### Scenario: Logging failure does not replace primary error handling
- **WHEN** an error occurs during customer creation and the system attempts to log it
- **THEN** the logging mechanism SHALL NOT block the response flow
- **AND** the client SHALL still receive the original validation response or generic error response appropriate to the failure
