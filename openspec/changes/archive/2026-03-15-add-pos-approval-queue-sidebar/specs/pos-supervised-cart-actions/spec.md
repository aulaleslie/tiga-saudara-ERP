## MODIFIED Requirements

### Requirement: Supervisory Queue MUST Resolve Pending Requests
Users with supervisory approval permission SHALL be able to review, approve, and reject pending POS approval requests. The system SHALL provide a direct navigation link to this queue in the main sidebar to ensure accessibility independent of active POS sessions.

#### Scenario: Supervisor approves request
- **WHEN** a supervisor approves a pending request from queue
- **THEN** the request status MUST become approved and MUST be available for requester status check

#### Scenario: Supervisor rejects request
- **WHEN** a supervisor rejects a pending request from queue
- **THEN** the request status MUST become rejected and MUST prevent the requested mutation from executing

#### Scenario: Supervisor accesses queue from sidebar
- **WHEN** a user with `pos.supervisor.approval` permission clicks the "Antrian Persetujuan" link in the sidebar
- **THEN** the system MUST display the approval queue index page regardless of whether the user has an active POS session
