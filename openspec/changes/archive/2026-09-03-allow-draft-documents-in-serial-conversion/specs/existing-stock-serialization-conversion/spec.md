## MODIFIED Requirements

### Requirement: Eligible product selection
The system SHALL allow conversion only for an active, stock-managed product that does not require serial numbers, has positive available stock, has no existing serial-number records, has whole-number stock quantities, and has no active stock-affecting process that would make the conversion unsafe. A related document in a header-level DRAFT status (Purchase Return, Transfer, Sale, Adjustment, or Sale Return) SHALL NOT be treated as an active stock-affecting process, since a draft document has not moved stock. A Sale Return whose settlement items are in DRAFT status SHALL still be treated as an active stock-affecting process, since it represents an already-active return in progress rather than an unsubmitted document.

#### Scenario: Eligible product is selected
- **WHEN** an authorized user selects a non-serialized stock-managed product satisfying every eligibility rule
- **THEN** the system loads that product's complete stock across all settings and locations

#### Scenario: Ineligible product is rejected
- **WHEN** the selected product is already serialized, has fractional or inconsistent stock, has existing serial records, or participates in an active stock-affecting process
- **THEN** the system blocks conversion and explains the applicable reason in Bahasa Indonesia

#### Scenario: Draft document does not block conversion
- **WHEN** the only related documents for the selected product are a Purchase Return, Transfer, Sale, Adjustment, or Sale Return in header-level DRAFT status
- **THEN** the system does not treat those documents as blockers and allows conversion to proceed if all other eligibility rules are satisfied

#### Scenario: Sale Return with draft settlement items still blocks conversion
- **WHEN** a Sale Return for the selected product has one or more settlement items in DRAFT status
- **THEN** the system still blocks conversion due to that active settlement process
