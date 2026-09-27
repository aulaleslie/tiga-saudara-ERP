## ADDED Requirements

### Requirement: Home previews product and price updates
The Home page SHALL render a `Pembaruan Produk & Harga` card beneath Quick Access for authenticated users. The card SHALL contain up to the ten newest events visible under the product-price-update-feed rules as compact clickable list rows, SHALL open the shared event-detail modal when a row is activated, and SHALL render an explicit empty state when no event is visible.

#### Scenario: User has visible updates
- **WHEN** an authenticated user opens Home and has at least one visible product or bundle update
- **THEN** Home displays up to ten newest visible grouped events beneath Quick Access

#### Scenario: User has no visible updates
- **WHEN** an authenticated user opens Home and has no visible product or bundle update
- **THEN** the update card remains visible with an empty-state message

#### Scenario: User activates a compact event row
- **WHEN** the user clicks or keyboard-activates a Home update row
- **THEN** the system opens a styled detail modal containing only information authorized for that user

### Requirement: Home is the sole navigation entry to full update history
The Home update card SHALL provide a `Lihat Semua Pembaruan` link to the full product and price update history. The system SHALL NOT add that history page to the sidebar, header, or another global navigation surface; authenticated users MAY revisit or directly open the route, subject to the same event-level visibility rules.

#### Scenario: User follows the Home history link
- **WHEN** an authenticated user selects `Lihat Semua Pembaruan` on Home
- **THEN** the system opens the full paginated update-history page

#### Scenario: Navigation is rendered elsewhere
- **WHEN** the authenticated sidebar and header navigation are rendered
- **THEN** they do not contain a product and price update-history link

#### Scenario: Authorized user revisits the history URL
- **WHEN** an authenticated user directly revisits the history route
- **THEN** the route remains available and returns only events visible to that user

