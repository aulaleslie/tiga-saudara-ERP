# translation Specification

## Purpose
This specification defines the requirements for localizing the user interface and system labels into Bahasa Indonesia, specifically focusing on role management and POS guidance.

## Requirements

### Requirement: Role Management String Localizations

#### Scenario: Translating static blade labels
- **WHEN** the user visits the role create page
- **THEN** the labels for "Role Name" and "Permissions" should be displayed as "Nama Peran" and "Hak Akses" respectively

#### Scenario: Translating permission groups
- **WHEN** the user views the permission cards
- **THEN** the permission card headers from `app/Config/Permissions.php` must be displayed in Bahasa Indonesia

#### Scenario: Translating POS Guidance
- **WHEN** the user views the POS guidance alert box in role management
- **THEN** the bundle names and their descriptions from `PosPermissionMatrix.php` must be displayed in Bahasa Indonesia
