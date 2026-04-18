## MODIFIED Requirements

### Requirement: Modal-based Bundle Detail Presentation
The system SHALL provide a way to view product bundle components within the Product Cart without disrupting the table layout. For POS cart rows, this SHALL be implemented via a read-only modal overlay triggered when the user clicks the row's `Paket: <bundle name>` bundle label.

#### Scenario: View POS bundle details from cart row label
- **WHEN** a POS cart row has selected bundle metadata
- **AND** the user clicks the `Paket: <bundle name>` label on that row
- **THEN** the system SHALL display a modal containing the bundle name, parent product name, cart row quantity, and all bundled item names and quantities from the cart line snapshot
- **AND** the modal SHALL be responsive and accessible on mobile devices

#### Scenario: Show simplified bundle price composition
- **WHEN** the POS bundle detail modal is opened for a bundled cart row
- **THEN** the modal SHALL display the base product price, bundle add-on price, final unit price, and line total for that row
- **AND** the modal MUST NOT display tax or discount breakdown details

#### Scenario: Stable cart layout
- **WHEN** bundle details are viewed or closed
- **THEN** the main Product Cart table layout SHALL remain stable without rows shifting or collapsing inline
