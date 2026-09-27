## ADDED Requirements

### Requirement: Bundle HPP follows physical fulfillment
The system SHALL persist and recognize HPP for every stock-managed bundle parent and component actually fulfilled, SHALL recognize zero HPP for non-stock-managed content, and MUST NOT derive HPP from bundle sale price, informational component price, revenue allocation, or tax amount. The same physical cost MUST NOT be recognized more than once.

#### Scenario: Non-stock parent has stock-managed components
- **WHEN** a non-stock-managed bundle parent is fulfilled with stock-managed components
- **THEN** the parent SHALL contribute zero HPP
- **AND** every fulfilled stock-managed component SHALL contribute its persisted component HPP

#### Scenario: Stock parent has stock-managed add-ons
- **WHEN** a stock-managed bundle parent and one or more stock-managed components are fulfilled
- **THEN** recognized bundle HPP SHALL equal the parent physical HPP plus every fulfilled component physical HPP

#### Scenario: Non-stock component contributes verified zero
- **WHEN** a bundle component is authoritatively non-stock-managed
- **THEN** its unit and total cost snapshots SHALL be zero
- **AND** its snapshot source SHALL distinguish verified non-stock zero from missing stock cost

### Requirement: Bundle components persist immutable cost identity
Every persisted fulfilled bundle component SHALL store immutable unit cost, total cost, snapshot source, selected source setting, selected source PKP classification, and snapshot time. Component total cost SHALL equal its expanded persisted fulfilled quantity multiplied by its unit cost without expanding parent quantity twice.

#### Scenario: Multi-quantity component is snapshotted once
- **WHEN** two bundle parent units each fulfill three units of a stock-managed component
- **THEN** the component cost snapshot SHALL use expanded quantity six
- **AND** report aggregation SHALL NOT multiply that quantity by parent quantity again

#### Scenario: Historical average changes after posting
- **WHEN** the selected product average purchase price changes after a bundled Sale is posted
- **THEN** historical bundle HPP SHALL continue using the persisted parent and component snapshots

### Requirement: HPP fallback is owner-aware and PKP-aware
For each stock-managed fulfilled product, the system SHALL first use a positive `average_purchase_price` belonging to the physical stock owner. If unavailable, it SHALL select a positive average from nearby settings with the same `is_pkp` classification before considering nearby settings with the opposite classification. It MUST NOT use `last_purchase_price` or an arbitrary product-price row as live bundle HPP.

#### Scenario: Stock owner average wins
- **WHEN** the physical stock owner has a positive average purchase price for the fulfilled product
- **THEN** that average SHALL be persisted regardless of averages held by the POS owner or other settings

#### Scenario: Non-PKP owner uses nearest non-PKP fallback
- **WHEN** a non-PKP stock owner has no positive average and multiple nearby settings have positive averages
- **THEN** the first eligible non-PKP setting in configured sales-location priority SHALL be selected before every PKP setting

#### Scenario: PKP owner uses nearest PKP fallback
- **WHEN** a PKP stock owner has no positive average and multiple nearby settings have positive averages
- **THEN** the first eligible PKP setting in configured sales-location priority SHALL be selected before every non-PKP setting

#### Scenario: Same-class fallback is absent
- **WHEN** neither the stock owner nor any nearby same-PKP setting has a positive average
- **THEN** the first eligible opposite-PKP setting in deterministic proximity order SHALL supply the snapshot

#### Scenario: No positive average exists
- **WHEN** no setting resolves a positive average purchase price for a stock-managed fulfilled product
- **THEN** the transaction SHALL complete with zero cost and an explicit missing-average snapshot source
- **AND** the completed transaction SHALL expose a missing-HPP warning without presenting the zero as verified product cost

### Requirement: Proximity fallback is deterministic
Nearby settings SHALL be derived from the stock owner's enabled sales-location assignments ordered by position, collapsing multiple locations for one setting to its earliest position and breaking ties by setting ID. If no eligible configured setting resolves a positive average, remaining settings SHALL be evaluated by matching PKP classification first, opposite classification second, and ascending setting ID within each class.

#### Scenario: Multiple locations belong to one fallback setting
- **WHEN** a fallback setting owns multiple locations in the stock owner's configured priority list
- **THEN** that setting SHALL appear once at its earliest enabled position

#### Scenario: Retry sees unchanged price data
- **WHEN** finalization is retried with unchanged candidate average prices and setting configuration
- **THEN** it SHALL resolve the same source setting and unit cost

### Requirement: Reports recognize net bundle HPP exactly once
Operational HPP consumers SHALL aggregate persisted parent physical HPP plus persisted component physical HPP and subtract only effective immutable return HPP reversals. Bundle component revenue-allocation rows MUST NOT be added to Sale revenue a second time.

#### Scenario: Canonical split-owner bundle reconciles
- **WHEN** the Laptop, Mouse, and Mousepad fixture posts revenue of `5,550,000` with physical costs `4,500,000`, `25,000`, and `5,000`
- **THEN** recognized HPP SHALL be `4,530,000`
- **AND** gross profit SHALL be `1,020,000`

#### Scenario: Parent and component snapshots are both present
- **WHEN** a stock parent and stock component have persisted cost snapshots
- **THEN** each physical snapshot SHALL contribute exactly once
- **AND** no aggregate component cost SHALL also be embedded in parent HPP

