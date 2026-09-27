## ADDED Requirements

### Requirement: Indonesian Supervisor Messages
Messages related to supervisor approval and variance override must be in Bahasa Indonesia.

#### Scenario: Invalid Supervisor Credentials
- **WHEN** Providing invalid supervisor credentials during variance override
- **THEN** The system returns 'Pengenal supervisor atau kata sandi tidak valid.' instead of 'Invalid supervisor identifier or password.'

#### Scenario: Missing Permission
- **WHEN** Supervisor lacks 'pos.sessions.approve-variance' permission
- **THEN** The system returns 'Supervisor yang disediakan tidak memiliki izin untuk menyetujui varian (pos.sessions.approve-variance).'
