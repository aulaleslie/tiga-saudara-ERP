## ADDED Requirements

### Requirement: Undispatched transfer lines initialize dispatch counters to zero
Every newly persisted `transfer_products` row SHALL initialize `dispatched_quantity`, `dispatched_quantity_tax`, `dispatched_quantity_non_tax`, `dispatched_quantity_broken_tax`, and `dispatched_quantity_broken_non_tax` to zero when dispatch values are omitted. The database schema MUST enforce these non-null zero defaults consistently on MySQL-compatible production databases.

#### Scenario: Save destination-optional draft without dispatch fields
- **WHEN** an authorized user saves a valid transfer draft with an origin and product lines but no destination or dispatch data
- **THEN** the draft and lines persist atomically with every dispatch counter equal to zero

#### Scenario: Save serialized draft without dispatch fields
- **WHEN** an authorized user saves a valid serialized transfer line before any dispatch occurs
- **THEN** selected serial intent persists and all dispatch counters initialize to zero without requiring application-supplied counter values

#### Scenario: Preserve existing dispatch values during schema repair
- **WHEN** the dispatch-counter default repair is applied to a database containing transfer lines
- **THEN** existing counter values remain unchanged while future omitted values receive zero

#### Scenario: Inspect production-compatible schema defaults
- **WHEN** the repaired schema is inspected on MySQL-compatible storage
- **THEN** all five dispatch counters are unsigned, non-null, and report a default of zero
