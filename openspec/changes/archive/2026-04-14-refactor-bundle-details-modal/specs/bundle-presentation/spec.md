## ADDED Requirements

### Requirement: Modal-based Bundle Detail Presentation
The system SHALL provide a way to view product bundle components within the Product Cart without disrupting the table layout. This SHALL be implemented via a modal overlay triggered by a user action on the bundle product line.

#### Scenario: View bundle details in modal
- **WHEN** a product that is part of a bundle is present in the cart
- **AND** the user clicks the "Lihat Paket Penjualan" button
- **THEN** the system SHALL display a modal containing the bundle name, bundle price, and a list of all items included in that bundle
- **AND** the modal SHALL be responsive and accessible on mobile devices

#### Scenario: Stable cart layout
- **WHEN** bundle details are viewed or closed
- **THEN** the main Product Cart table layout SHALL remain stable without rows shifting or collapsing inline
