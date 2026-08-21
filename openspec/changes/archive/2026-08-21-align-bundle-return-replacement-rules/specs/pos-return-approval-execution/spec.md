### Requirement: Product Replacement Preserves Source Sale Commercials
For every product-replacement POS Return line, the system SHALL keep original Sale, SaleDetail, SaleBundleItem, tax, payment, and customer-facing quantity and monetary fields unchanged. A serial-tracked replacement SHALL receive the exact returned serial into its original source owner/location as active saleable stock, create an owner-aware approved replacement dispatch for an eligible replacement serial of the same product, record inventory and immutable replacement HPP effects, and preserve returned-to-replacement serial lineage. A non-serial replacement SHALL complete as an auditable replacement note without receiving, replacement dispatch, stock, inventory-transaction, cost-reversal, or outgoing replacement-HPP effects.

#### Scenario: Serial replacement dispatches replacement serial
- **WHEN** final approval executes product replacement for a serial-tracked ordinary product, bundle parent, or bundle component
- **THEN** the exact returned serial SHALL be received to its original owner/location and become active and saleable immediately
- **AND** an approved replacement dispatch SHALL move the selected replacement serial under the applicable owner/location rules
- **AND** returned and replacement serial lineage and physical HPP effects SHALL be recorded exactly once
- **AND** original Sale commercial and payment fields SHALL remain unchanged

#### Scenario: Non-serial replacement completes as note only
- **WHEN** final approval executes product replacement for a non-serial ordinary product, bundle parent, or bundle component
- **THEN** the system SHALL complete and retain the replacement note and approval audit
- **AND** it SHALL NOT create receiving stock, replacement dispatch, stock mutation, inventory transaction, HPP reversal, or outgoing replacement HPP
- **AND** original Sale commercial and payment fields SHALL remain unchanged

#### Scenario: Serial replacement can represent a color exchange
- **WHEN** returned and replacement serials belong to the same product and the physical exchange differs only by an unmodeled attribute such as color
- **THEN** replacement SHALL be allowed using those distinct serial identities
- **AND** the system SHALL NOT require color to be modeled as a different product

### Requirement: Bundle Parent Return Uses Resolution-Sensitive Component Execution
The system SHALL allow a customer refund for bundled merchandise only through a complete parent bundle quantity with every originally fulfilled component included proportionally. A bundle parent alone and a bundle component alone MUST NOT be refundable. Product replacement SHALL be independently available for either the bundle parent or an individual bundle component without affecting other bundle content. Serial replacement SHALL execute only the selected product's serial physical lifecycle; non-serial replacement SHALL remain note-only. Missing or ambiguous persisted lineage MUST block the affected cash return or serial replacement before mutation.

#### Scenario: Partial-by-quantity whole-bundle cash return includes components
- **WHEN** final approval cash-refunds one unit from an original sale of multiple bundle units
- **THEN** the source Sale parent commercial amount SHALL be reduced by one complete bundle unit
- **AND** stock and HPP reversal SHALL include the proportional parent and every originally fulfilled component for that unit
- **AND** owner-specific internal reversal SHALL use original persisted allocations, tax, dispatch, and cost lineage

#### Scenario: Incomplete bundle cash return is blocked
- **WHEN** final approval receives cash-refund intent for only a bundle parent or only one component
- **THEN** execution SHALL be blocked before any lifecycle, Sale, payment, return, stock, dispatch, serial, inventory, or HPP mutation

#### Scenario: Bundle parent replacement affects parent only
- **WHEN** final approval executes replacement for a bundle parent
- **THEN** only the selected parent SHALL receive the applicable serial or note-only replacement behavior
- **AND** component rows and component physical/commercial state SHALL remain unchanged

#### Scenario: Bundle component replacement affects component only
- **WHEN** final approval executes replacement for an individual bundle component
- **THEN** only the selected component SHALL receive the applicable serial or note-only replacement behavior
- **AND** the parent and other component physical/commercial state SHALL remain unchanged
- **AND** no customer refund SHALL be created from the component allocation

#### Scenario: Historical component lineage is ambiguous
- **WHEN** the system cannot prove the exact original SaleBundleItem, dispatch, owner/location, or returned serial required for a component cash return or serial replacement
- **THEN** approval SHALL block the affected action before mutation with actionable ambiguity details

## ADDED Requirements

### Requirement: Replacement Execution SHALL Remain Atomic And Idempotent
Serial and note-only replacement execution SHALL participate in the existing final-approval transaction and idempotency guards. A retry MUST NOT duplicate notes, serial histories, receiving, dispatch, inventory, Sales Return, or HPP effects, and a failure MUST leave no partial execution.

#### Scenario: Serial component replacement retry
- **WHEN** an approved serial component replacement is retried after successful completion
- **THEN** no duplicate serial history, stock movement, dispatch, inventory transaction, Sales Return detail, or HPP effect SHALL be created

#### Scenario: Mixed execution failure rolls back
- **WHEN** one approval contains executable bundle refund, serial replacement, or note-only replacement lines and a later line fails
- **THEN** every mutation from that approval attempt SHALL roll back
- **AND** the POS Return SHALL remain in its pre-execution state
