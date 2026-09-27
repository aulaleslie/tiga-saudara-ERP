## ADDED Requirements

### Requirement: Cross-tenant return obligations derive from actual taxed dispatch
For a transfer whose origin and destination belong to different tenants, the system SHALL create mandatory return obligations only for the actual dispatched taxed quantities and exact taxed serials, including taxed broken stock intentionally dispatched.

#### Scenario: Cross-tenant dispatch contains only non-tax stock
- **WHEN** actual dispatch provenance contains no normal-tax, broken-tax, or taxed serial quantity
- **THEN** destination receipt creates no physical return obligation and the transfer can complete after receipt

#### Scenario: Cross-tenant dispatch contains mixed tax provenance
- **WHEN** actual dispatch contains non-tax and taxed quantities
- **THEN** the destination may retain the non-tax quantities but the system creates return obligations for all and only the taxed quantities

#### Scenario: Taxed broken stock is dispatched
- **WHEN** an intentionally broken-stock line includes actual broken-tax quantity
- **THEN** that broken-tax quantity is included in the mandatory return obligation

#### Scenario: Taxed serialized stock is dispatched
- **WHEN** an actual dispatched serial has taxed provenance
- **THEN** the return obligation identifies that exact serial and cannot be fulfilled by a different serial

### Requirement: Destination receipt determines whether return is awaited
After destination receipt, the system SHALL complete a same-tenant transfer or a cross-tenant transfer with no outstanding taxed obligation, and SHALL place a cross-tenant transfer with outstanding taxed obligations into an awaiting-return state.

#### Scenario: Receive cross-tenant non-tax transfer
- **WHEN** the destination receives a cross-tenant transfer whose actual taxed return obligation is zero
- **THEN** the system marks the transfer complete and leaves the non-tax stock at the destination

#### Scenario: Receive cross-tenant taxed transfer
- **WHEN** the destination receives a cross-tenant transfer with an outstanding taxed return obligation
- **THEN** the system marks the transfer as awaiting return and displays the required quantities and serials

#### Scenario: Receive same-tenant transfer
- **WHEN** the origin and destination locations belong to the same tenant and destination receipt succeeds
- **THEN** the system completes the transfer without creating a cross-tenant return obligation

### Requirement: Return dispatch moves only outstanding obligated provenance
An authorized destination-tenant dispatcher SHALL be able to dispatch the outstanding mandatory taxed return quantities and exact taxed serials, and the system MUST NOT automatically return non-tax quantities.

#### Scenario: Dispatch mixed transfer return
- **WHEN** a received cross-tenant transfer contains retained non-tax quantity and outstanding taxed quantity
- **THEN** return dispatch deducts only the obligated taxed quantity from destination stock and leaves retained non-tax quantity unchanged

#### Scenario: Return exact taxed serial
- **WHEN** the return obligation identifies a taxed serial
- **THEN** return dispatch succeeds only when that exact active serial is available at the destination and moves it toward the origin

#### Scenario: Attempt return with insufficient obligated stock
- **WHEN** destination stock or an obligated serial cannot fulfill the outstanding return obligation
- **THEN** return dispatch fails atomically and leaves the transfer awaiting return

### Requirement: Return receipt completes the obligation atomically
An authorized origin-tenant receiver SHALL receive exactly the return-dispatched obligated quantities and serials, restore them to origin stock under their taxed provenance, and complete the transfer only when no mandatory obligation remains outstanding.

#### Scenario: Receive all returned taxed quantity
- **WHEN** the origin receives all return-dispatched taxed quantities and serials
- **THEN** the system restores those quantities to the corresponding origin taxed buckets, closes the obligations, and marks the transfer complete

#### Scenario: Duplicate return receipt request
- **WHEN** the same return receipt action is submitted more than once
- **THEN** origin inventory is increased at most once and the completed transfer remains unchanged

### Requirement: Return obligations and fulfillment are auditable
The system SHALL preserve the actual dispatched provenance, obligated return amount, returned amount, exact obligated serials, actors, timestamps, and inventory transaction references for each cross-tenant transfer line.

#### Scenario: Review completed mixed transfer
- **WHEN** a user views a completed cross-tenant transfer that dispatched non-tax and taxed stock
- **THEN** the detail shows what remained at the destination, what was required to return, and what was received back at the origin

### Requirement: Historical transfers remain compatible
The system SHALL preserve existing transfer records and document numbers and SHALL provide deterministic behavior for historical `RECEIVED` and `RETURN_RECEIVED` records that lack newly introduced obligation data.

#### Scenario: View historical received transfer without obligation records
- **WHEN** a user views a pre-change received transfer
- **THEN** the system renders it without requiring destructive backfill and identifies its historical lifecycle state consistently

#### Scenario: Historical transfer is not silently reopened
- **WHEN** the change is deployed
- **THEN** previously terminal historical transfers do not acquire new mandatory return work solely because of the deployment
