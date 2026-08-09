## ADDED Requirements

### Requirement: Database-only logical backup command
The system SHALL provide an Artisan command that creates a backup of the active configured MySQL connection's complete database without including application files. The command MUST use the configured MySQL dump executable and MUST include database schema, data, triggers, routines, and events in the backup.

#### Scenario: Successful configured MySQL backup
- **WHEN** an operator runs the command with a reachable configured MySQL connection and dump executable
- **THEN** the system creates a restorable archive containing the complete configured database dump and reports success

#### Scenario: Missing dump executable
- **WHEN** an operator runs the command with a missing or non-executable configured dump path
- **THEN** the system fails before replacing any existing backup slot and reports the configuration failure

### Requirement: Consistent low-disruption database snapshot
The system SHALL request a single-transaction MySQL dump and MUST include routines, events, and triggers so that normal InnoDB ERP operations are not deliberately table-locked by the backup command.

#### Scenario: Dump command construction
- **WHEN** the backup command starts a database dump
- **THEN** it invokes the dump executable with single-transaction, routines, events, and triggers enabled

### Requirement: Safe bounded backup replacement
The system SHALL retain at most two configured final backup slots. It MUST write each new archive to a temporary path on the destination volume and MUST promote it to the selected final slot only after the dump and archive are successfully completed and non-empty.

#### Scenario: First successful backup
- **WHEN** no final backup slots exist and a backup succeeds
- **THEN** the system promotes one completed archive to a configured final slot and leaves no incomplete archive as a final backup

#### Scenario: Subsequent successful backup
- **WHEN** both final slots exist and a backup succeeds
- **THEN** the system replaces only the older slot and retains the other slot unchanged

#### Scenario: Failed backup preserves recovery data
- **WHEN** dump creation, archive creation, or validation fails while a final backup slot exists
- **THEN** the system does not replace either existing final slot, returns a failure status, and records the failure reason

### Requirement: Environment-configurable Windows deployment settings
The system SHALL obtain the dump executable path, backup destination directory, final slot filenames, and optional archive password from environment-backed configuration. It MUST NOT require database credentials to be repeated in a Windows scheduled-task command.

#### Scenario: Configured Windows destination
- **WHEN** the deployment configures `D:\Backup_WK` as the destination directory
- **THEN** the command stores temporary and final backup artifacts in that directory without relying on the Laravel release directory

#### Scenario: Archive encryption configured
- **WHEN** the deployment supplies a non-empty archive password
- **THEN** the system creates an encrypted final backup archive using that password

### Requirement: Observable command execution
The system SHALL return a non-zero process status for any backup failure and MUST report a diagnostic message through command output and application logging. On success it SHALL report the selected final slot and resulting archive size.

#### Scenario: Scheduled-task failure diagnosis
- **WHEN** a scheduled execution cannot write to the configured destination
- **THEN** the command exits unsuccessfully and logs that the destination is unavailable or not writable

### Requirement: Windows scheduling deployment guidance
The system SHALL document an operator-managed Windows Task Scheduler setup that directly runs the Artisan command hourly at minute 05. The guidance MUST require the Laravel project root as the working directory, a task account with Modify access to the destination, prevention of overlapping runs, and execution as soon as possible after a missed schedule.

#### Scenario: Server unavailable overnight
- **WHEN** the Windows server is off during one or more scheduled hours and later starts
- **THEN** the documented task configuration runs one missed backup after startup and resumes its hourly schedule
