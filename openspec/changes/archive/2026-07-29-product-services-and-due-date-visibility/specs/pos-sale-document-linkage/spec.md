## MODIFIED Requirements

### Requirement: Generated Sale documents open in a read-only modal
Selecting a linked Sale reference on POS transaction detail SHALL open a read-only view of that Sale in a modal on the same page. The system SHALL NOT navigate away to the Sales module, and the modal SHALL NOT expose actions that modify the Sale. The invoice information in the modal SHALL display both the sale date and the existing sale due date, using a neutral placeholder when no due date exists.

#### Scenario: Opening the modal
- **WHEN** a user selects a linked Sale reference on POS transaction detail
- **THEN** a modal opens in place showing that Sale's details
- **AND** the browser does not navigate to the Sales module

#### Scenario: Modal shows sale date and due date
- **WHEN** the Sale detail modal opens for a Sale with a due date
- **THEN** its invoice information displays `Tanggal` and `Tanggal Jatuh Tempo` as distinct values

#### Scenario: Modal handles missing due date
- **WHEN** the Sale detail modal opens for a Sale without a due date
- **THEN** the `Tanggal Jatuh Tempo` value displays a neutral placeholder

#### Scenario: Modal is read-only
- **WHEN** the Sale detail modal is open
- **THEN** no control that edits, deletes, or otherwise mutates the Sale is presented

#### Scenario: Closing the modal
- **WHEN** the user dismisses the modal
- **THEN** the modal closes and the POS transaction detail page is unchanged
