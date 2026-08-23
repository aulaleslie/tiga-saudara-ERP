## MODIFIED Requirements

### Requirement: Safe bounded backup replacement
The system SHALL retain at most two configured final backup slots. It MUST create the database dump and new archive inside a unique workspace beneath a configured working directory that is separate from the final destination and on the same volume. It MUST promote the completed archive to the selected final slot only after the dump and archive are successfully completed and non-empty. The final destination MUST NOT contain the SQL dump, temporary archive, or temporary workspace.

#### Scenario: First successful backup
- **WHEN** no final backup slots exist and a backup succeeds
- **THEN** the system promotes one completed archive from the working directory to a configured final slot and leaves no incomplete or temporary artifact in the destination

#### Scenario: Subsequent successful backup
- **WHEN** both final slots exist and a backup succeeds
- **THEN** the system replaces only the older slot, retains the other slot unchanged, and leaves only configured final slots in the destination

#### Scenario: Failed backup preserves recovery data
- **WHEN** dump creation, archive creation, validation, or final promotion fails while a final backup slot exists
- **THEN** the system does not replace either existing final slot, returns a failure status, records the failure reason, and attempts to remove the run-specific workspace

#### Scenario: Working directory matches destination
- **WHEN** the configured working directory resolves to the same path as the final destination
- **THEN** the system fails before creating a database dump and reports that the directories must be separate

#### Scenario: Windows directories use different volumes
- **WHEN** the configured Windows working directory and destination directory are on different volumes
- **THEN** the system fails before creating a database dump and reports that same-volume placement is required for safe promotion

### Requirement: Environment-configurable Windows deployment settings
The system SHALL obtain the dump executable path, backup working directory, backup destination directory, final slot filenames, and optional archive password from environment-backed configuration. It MUST NOT require database credentials to be repeated in a Windows scheduled-task command.

#### Scenario: Configured Windows working and destination directories
- **WHEN** the deployment configures an unsynchronized `D:\Backup_Work` working directory and a synchronized `D:\Backup_WK` destination directory
- **THEN** the command stores temporary backup artifacts only beneath the working directory and completed rotating ZIP archives only in the destination

#### Scenario: Archive encryption configured
- **WHEN** the deployment supplies a non-empty archive password
- **THEN** the system creates an encrypted final backup archive using that password

#### Scenario: Working directory unavailable
- **WHEN** the configured working directory cannot be created or is not writable by the scheduled-task account
- **THEN** the command fails before dumping or replacing a final backup and reports the working-directory configuration failure

### Requirement: Windows scheduling deployment guidance
The system SHALL document an operator-managed Windows Task Scheduler setup that directly runs the Artisan command every 15 minutes. The guidance MUST require the Laravel project root as the working directory, a task account with Modify access to both backup directories, prevention of overlapping runs, execution as soon as possible after a missed schedule, and exclusion of the backup working directory from cloud synchronization.

#### Scenario: Recurring scheduled backup
- **WHEN** the Windows server remains available during the configured schedule
- **THEN** the documented task runs the backup every 15 minutes without starting an overlapping command instance

#### Scenario: Server unavailable during scheduled runs
- **WHEN** the Windows server is off during one or more scheduled runs and later starts
- **THEN** the documented task configuration runs one missed backup after startup and resumes its 15-minute schedule
