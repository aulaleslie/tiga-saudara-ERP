## ADDED Requirements

### Requirement: Future product and bundle changes produce immutable feed events
The system SHALL record an immutable update-feed event only for qualifying changes completed after this capability is deployed. Qualifying changes SHALL be product creation, a change to `last_purchase_price`, `sale_price`, `tier_1_price`, or `tier_2_price`, bundle creation, and a change to `bundle_sale_price`. Each event SHALL preserve its type, occurrence time, source or actor, affected setting, subject identifiers and display snapshots, and the authorized before and after price values needed to explain the change.

#### Scenario: Product is created after deployment
- **WHEN** a product is successfully created after the feed capability is deployed
- **THEN** the system records a product-created event containing the affected business price snapshot

#### Scenario: Tracked product price changes
- **WHEN** a persisted tracked price changes from one value to a different value
- **THEN** the system records the before and after values in a product-price-updated event

#### Scenario: Price write is a no-op
- **WHEN** a workflow persists the same tracked price values already stored
- **THEN** the system does not record a product-price-updated event

#### Scenario: Bundle is created
- **WHEN** a bundle is successfully created after deployment
- **THEN** the system records a bundle-created event with its business and bundle sale price

#### Scenario: Bundle price changes
- **WHEN** a bundle sale price changes from one persisted value to another
- **THEN** the system records a bundle-price-updated event with its before and after values

#### Scenario: Existing historical catalog data
- **WHEN** the capability is deployed over existing products, prices, and bundles
- **THEN** the system does not backfill events for those existing records

### Requirement: Event capture covers supported price mutation paths
The system SHALL emit qualifying events from supported product forms and quick-add flows, cross-business price management, purchase-driven latest-price synchronization, product import and background processing, bundle creation, single-business bundle updates, and replicated multi-business bundle price updates. Event persistence SHALL participate in the successful domain operation so failed or rolled-back mutations do not leave visible feed events.

#### Scenario: Purchase synchronization changes latest price
- **WHEN** a supported purchase workflow successfully changes a product's latest purchase price
- **THEN** the system records the resulting update for the affected setting

#### Scenario: Import changes tracked prices
- **WHEN** a supported import or background job successfully changes tracked product prices
- **THEN** the system records the resulting changes with an automated or import source label

#### Scenario: Multi-business operation succeeds
- **WHEN** one successful operation changes a product or bundle across multiple businesses
- **THEN** the system records the affected setting snapshots under a common operation group

#### Scenario: Mutation rolls back
- **WHEN** a qualifying catalog mutation is rolled back or fails
- **THEN** no event from that failed mutation is visible in the feed

### Requirement: Super Admin receives unrestricted event visibility
The system SHALL allow a user with the `Super Admin` role to see all recorded events for all businesses and every recorded tracked purchase, sales-tier, and bundle price field without applying user-setting assignment or price-field permission filters.

#### Scenario: Super Admin views the feed
- **WHEN** a Super Admin opens the Home preview, history page, or an event detail modal
- **THEN** the system returns all otherwise matching events and complete recorded price details across all businesses

### Requirement: Regular-user visibility is evaluated per assigned setting
For a regular user, the system SHALL return events only for settings assigned through `user_setting` and SHALL evaluate visibility using the role attached to each affected setting. The `purchases.create` permission SHALL expose latest purchase price information; `sales.create` SHALL expose regular, Tier 1, and Tier 2 sales prices; and the combination of `pos.access` and `pos.sessions.open` SHALL expose those sales prices. Bundle-created and bundle-price-updated events SHALL be visible through the Sales or POS sales-price rule. A grouped event or setting section with no authorized information SHALL be omitted.

#### Scenario: Purchase-only user views a product event
- **WHEN** a regular user has `purchases.create` but no Sales or qualifying POS permissions in an assigned setting
- **THEN** the user sees the product event and its latest purchase price without any sales-price fields

#### Scenario: Sales-only user views a product event
- **WHEN** a regular user has `sales.create` but not `purchases.create` in an assigned setting
- **THEN** the user sees the product event and all sales-price tiers without the latest purchase price

#### Scenario: POS user views a product event
- **WHEN** a regular user has both `pos.access` and `pos.sessions.open` but not `purchases.create` in an assigned setting
- **THEN** the user sees the product event and all sales-price tiers without the latest purchase price

#### Scenario: POS permission combination is incomplete
- **WHEN** a regular user has only one of `pos.access` or `pos.sessions.open` and has no other qualifying permission in the affected setting
- **THEN** POS permissions do not grant visibility to the event or its sales prices

#### Scenario: User has combined permissions
- **WHEN** a regular user has purchase permission and either Sales or qualifying POS permissions in the affected setting
- **THEN** the user sees both latest purchase price and all sales-price tiers for that setting

#### Scenario: Roles differ between assigned businesses
- **WHEN** one grouped operation affects multiple assigned settings in which the user has different roles
- **THEN** each business section independently exposes only the event information permitted by that setting's role

#### Scenario: Business is not assigned
- **WHEN** an event belongs to a setting not assigned to a regular user
- **THEN** neither the event nor any value from that setting is returned to the user

### Requirement: Multi-business changes have a compact grouped presentation
The system SHALL present one compact event item for one grouped operation and SHALL list only the business sections and price fields visible to the current user. Product price updates SHALL display only tracked fields whose persisted values changed.

#### Scenario: Replicated operation affects two visible businesses
- **WHEN** a grouped operation contains authorized updates for two businesses
- **THEN** the preview, history item, and modal represent one event with two business sections

#### Scenario: User can see only one affected business
- **WHEN** a grouped operation affects multiple businesses but only one is visible to a regular user
- **THEN** the system presents the event as a single-business item without revealing the other businesses

### Requirement: Full event history supports tokenized partial search and filters
The system SHALL provide a newest-first, paginated history of visible events. Search SHALL be case-insensitive, SHALL split input on whitespace, SHALL require every non-empty token to partially match at least one searchable subject field, and SHALL search product name, product code, and bundle name using the Product List-style tokenized `LIKE` behavior. The page SHALL also support visible business, event type, and date-range filters.

#### Scenario: Multiple partial tokens match
- **WHEN** a user searches for `n150 512` and a visible event subject contains both partial tokens across its searchable name or code fields
- **THEN** the matching event is included

#### Scenario: One token does not match
- **WHEN** at least one non-empty search token does not partially match any searchable subject field for an event
- **THEN** that event is excluded

#### Scenario: Search is not spelling correction
- **WHEN** a misspelled token is not itself a partial substring of any searchable subject field
- **THEN** the system is not required to return a phonetically or fuzzily similar event

#### Scenario: Regular user filters by business
- **WHEN** a regular user opens the business filter
- **THEN** only settings assigned to that user and relevant to visible events are available

#### Scenario: Super Admin filters by business
- **WHEN** a Super Admin opens the business filter
- **THEN** all businesses are available

#### Scenario: Filters change while viewing a later page
- **WHEN** the user changes search or filter criteria while viewing a later pagination page
- **THEN** pagination resets and results are returned from the first matching page

### Requirement: Event detail modal is permission-safe and snapshot-based
Clicking a visible event SHALL open a reusable styled detail modal containing the subject, type, actor or source, exact occurrence timestamp, visible businesses, and authorized price snapshot or before/after comparison. Authorization and field masking SHALL be applied server-side whenever detail data is retrieved, and the modal SHALL remain usable when the current product or bundle has subsequently changed or been deleted.

#### Scenario: User opens an update detail
- **WHEN** a user clicks a visible price-update item
- **THEN** the modal displays only changed authorized fields in a compact before-and-after comparison

#### Scenario: User requests a hidden event directly
- **WHEN** a regular user requests modal detail for an event with no information visible to that user
- **THEN** the system denies the request without returning hidden business names or prices

#### Scenario: Subject no longer exists
- **WHEN** a visible event references a product or bundle that has subsequently changed or been deleted
- **THEN** the modal renders the stored subject and price snapshots without requiring the current subject record

#### Scenario: Modal closes on filtered history
- **WHEN** the user closes an event modal on the history page
- **THEN** the current search, filters, and pagination remain unchanged

