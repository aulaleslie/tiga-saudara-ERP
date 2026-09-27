## ADDED Requirements

### Requirement: Centralized permission configuration
The system SHALL provide a single PHP configuration file that defines all application permissions grouped by feature, with human-readable labels for each permission. This configuration serves as the authoritative source of truth for permission definitions.

#### Scenario: Permission configuration is loaded by seeder
- **WHEN** the PermissionsTableSeeder runs
- **THEN** it reads all permission definitions from `app/Config/Permissions.php`
- **AND** creates Permission records in the database for each permission in the configuration
- **AND** removes any permissions from the database that are not in the configuration

#### Scenario: Permission groups are accessible to views
- **WHEN** a role creation or editing view renders
- **THEN** it can access grouped permission definitions from the configuration
- **AND** display permissions organized by feature groups (Adjustments, Penjualan, POS, etc.)
- **AND** each permission has both a code identifier (e.g., `sales.create`) and a human label (e.g., "Buat Penjualan")

#### Scenario: Permission configuration includes all active permissions
- **WHEN** the configuration is examined
- **THEN** it includes exactly 196 permissions that are used in the codebase
- **AND** all permissions are organized into logical feature groups
- **AND** each group contains a map of permission code to human-readable label
- **AND** the configuration is formatted as a structured PHP array for easy maintenance

### Requirement: Permission configuration structure
The permissions configuration SHALL use a grouped structure where each group is a feature area (e.g., Sales, Purchases, POS) and contains a map of permission identifiers to labels.

#### Scenario: Configuration structure supports easy lookup
- **WHEN** code needs to retrieve permissions for a specific group (e.g., "Penjualan")
- **THEN** it can access all permissions for that group with their labels
- **AND** iteration over all permissions can enumerate both identifier and label together
- **AND** validation against the configuration checks if a permission identifier is valid

#### Scenario: Configuration is version-controllable and auditable
- **WHEN** a new permission is added or removed
- **THEN** the change is reflected in the configuration file
- **AND** the change is committed to version control with clear tracking
- **AND** the file structure makes it obvious what permissions exist at any point in time

### Requirement: Seeder uses configuration as source of truth
The PermissionsTableSeeder SHALL load permissions from the centralized configuration and synchronize the database to match exactly.

#### Scenario: Seeder creates missing permissions
- **WHEN** the seeder runs and a permission in configuration does not exist in the database
- **THEN** the seeder creates that permission record
- **AND** the permission is associated with the correct guard (web)

#### Scenario: Seeder removes orphaned permissions
- **WHEN** the seeder runs and a permission exists in the database but is not in the configuration
- **THEN** the seeder deletes that permission from the database
- **AND** any role assignments to that permission are also deleted (CASCADE)

#### Scenario: Seeder syncs Admin role to all permissions
- **WHEN** the seeder completes
- **THEN** the Admin role is assigned exactly the permissions defined in the configuration
- **AND** no other roles are modified by the seeder
