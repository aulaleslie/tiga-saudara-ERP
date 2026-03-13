## MODIFIED Requirements

### Requirement: Finalize SHALL post one sales bundle per split group
For each planned split group, the system SHALL create exactly one `sale`, one payment allocation record, and associated dispatch records in the same finalize operation, and SHALL post that bundle using the group `source_setting_id` as owner context.

#### Scenario: Posting two split groups under different source owners
- **WHEN** finalize is executed for a checkout with two split groups owned by different `source_setting_id` values
- **THEN** two sales bundles are created and linked to the same checkout
- **AND** each created sale `setting_id` MUST equal its group `source_setting_id`
- **AND** inventory transactions created for each group line MUST use the same owner setting as that group.

#### Scenario: Owner-specific numbering follows source setting
- **WHEN** finalize posts split groups across multiple source settings
- **THEN** each sale reference MUST be generated from the owning group setting sequence/prefix rules
- **AND** no sale in the checkout MAY use another setting's numbering sequence.
