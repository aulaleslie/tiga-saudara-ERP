# Spec Delta

## MODIFIED Requirements

### Requirement: Individual purchase payment accepts multiple separate attachments
The system SHALL allow an authorized user to attach zero or more files to one individual purchase payment during creation. It MUST retain each accepted file as a distinct attachment associated with that payment and MUST NOT package files into a ZIP archive. Global purchase payment row uploads SHALL use the same supported file types and file validation rules.

#### Scenario: Payment created with several files
- **WHEN** an authorized user submits an individual purchase payment with several accepted files
- **THEN** one payment is created with one separate attachment per file
- **AND** each attachment can be opened independently

#### Scenario: Payment created without files
- **WHEN** an authorized user submits a valid individual purchase payment without attachments
- **THEN** the payment is created without attachments

#### Scenario: Global row upload uses the same file rules
- **WHEN** a user uploads files on a global purchase payment allocation row
- **THEN** the accepted types, content checks, image processing, and application file-size behavior match those for an individual purchase payment
- **AND** unsupported or disguised files are rejected

#### Scenario: Global payment remains unchanged
- **WHEN** an individual purchase payment is created with multiple files
- **THEN** its file processing remains independent of the global purchase payment workflow
- **AND** global row uploads follow their own purchase-to-payment association rule
