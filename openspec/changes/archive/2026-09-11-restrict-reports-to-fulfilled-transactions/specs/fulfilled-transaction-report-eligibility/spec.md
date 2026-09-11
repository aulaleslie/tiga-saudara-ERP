## ADDED Requirements

### Requirement: Sales reports recognize only fully dispatched transactions
Sales-derived reports SHALL include a sale only after full dispatch. Eligible active statuses SHALL include `DISPATCHED` and `RETURNED PARTIALLY`; `DISPATCHED PARTIALLY` and every pre-dispatch status SHALL be excluded.

#### Scenario: Partially dispatched sale remains on hold
- **WHEN** a sale has status `DISPATCHED PARTIALLY`
- **THEN** it contributes no row, aggregate, tax, receivable, or operational financial value to an affected report

#### Scenario: Partially returned sale retains remaining value
- **WHEN** a fully dispatched sale is later settled as `RETURNED PARTIALLY`
- **THEN** affected reports include its currently persisted remaining values

### Requirement: Purchase reports recognize only fully received transactions
Purchase-derived reports SHALL include a purchase only after full receipt. Eligible active statuses SHALL include `RECEIVED` and `RETURNED PARTIALLY`; `RECEIVED PARTIALLY` and every pre-receipt status SHALL be excluded.

#### Scenario: Partially received purchase remains on hold
- **WHEN** a purchase has status `RECEIVED PARTIALLY`
- **THEN** it contributes no row, aggregate, tax, payable, or operational financial value to an affected report

#### Scenario: Partially returned purchase retains remaining value
- **WHEN** a fully received purchase is later settled as `RETURNED PARTIALLY`
- **THEN** affected reports include its currently persisted remaining values

### Requirement: Completed full returns are excluded at lifecycle completion
Affected reports SHALL exclude fully returned documents once the established return completion/archive signal is present and SHALL NOT prematurely exclude an unfinished full return solely because its header status is `RETURNED`.

#### Scenario: Full return awaits settlement
- **WHEN** returned goods have changed an origin status to `RETURNED` but settlement is unfinished and the source remains active
- **THEN** the origin remains reportable using its currently persisted values

#### Scenario: Full return is completed
- **WHEN** full-return settlement is completed and the source is archived or otherwise marked completed by the established lifecycle
- **THEN** the source contributes no value to affected reports

### Requirement: Reports use settlement-modified origin values without return subtraction
Affected reports SHALL treat current persisted sale or purchase header and detail values as authoritative and SHALL NOT additionally subtract related return headers or details.

#### Scenario: Modified origin is counted once
- **WHEN** settlement approval reduces an eligible origin or target document
- **THEN** reports use the reduced persisted values exactly once
- **AND** related return values are not subtracted again

### Requirement: Eligibility is consistent across report outputs
The same eligibility predicate SHALL apply to on-screen rows, grouped totals, grand totals, pagination, local/global setting scope, and all supported exports.

#### Scenario: Export matches displayed eligible population
- **WHEN** a user exports an applied report snapshot
- **THEN** exported rows and totals use the same eligible transaction population as the on-screen report

### Requirement: Event and order-stage reports retain their semantics
Delivery reports SHALL continue to use approved dispatch or receiving events, and order-completion reports SHALL continue to include their defined pre-fulfillment stages.

#### Scenario: Order completion retains pre-fulfillment rows
- **WHEN** an approved but unfulfilled order is queried in an order-completion report
- **THEN** that report may include it according to its existing source-stage rules
