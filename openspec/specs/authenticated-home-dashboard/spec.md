# authenticated-home-dashboard Specification

## Purpose
TBD - created by archiving change move-home-content-to-dashboard. Update Purpose after archive.
## Requirements
### Requirement: Separate authenticated Home and Dashboard pages
The system SHALL retain Home as the authenticated post-login landing page and SHALL provide a distinct Dashboard page containing all reporting cards, charts, scripts, and permission-based visibility previously rendered on Home. The move SHALL NOT change reporting calculations or chart data behavior.

#### Scenario: User opens Home
- **WHEN** an authenticated user with a selected business visits the Home route
- **THEN** the system displays the personalized Home content and does not display the reporting cards or charts moved to Dashboard

#### Scenario: Authorized report user opens Dashboard
- **WHEN** an authenticated user with `reports.access` and a selected business visits the Dashboard route
- **THEN** the system displays the reporting cards and charts that were previously displayed on Home

#### Scenario: User without report permission opens Dashboard
- **WHEN** an authenticated user without `reports.access` visits the Dashboard route
- **THEN** reporting content governed by `reports.access` remains hidden exactly as it was on Home

#### Scenario: Dashboard chart data loads
- **WHEN** the Dashboard requests data for its existing charts
- **THEN** the system uses the existing chart data endpoints and calculations without changing their responses

### Requirement: Home displays a time-dependent personalized greeting
The Home page SHALL display an Indonesian greeting based on the current application-local time and SHALL address the authenticated user using the first whitespace-delimited token of the user's name. The greeting periods SHALL be `Selamat pagi` from 04:00 through 10:59, `Selamat siang` from 11:00 through 14:59, `Selamat sore` from 15:00 through 17:59, and `Selamat malam` from 18:00 through 03:59.

#### Scenario: Morning greeting
- **WHEN** the application-local time is between 04:00 and 10:59 and the authenticated user's name is `BUDI SANTOSO`
- **THEN** Home displays `Selamat pagi, BUDI`

#### Scenario: Midday greeting
- **WHEN** the application-local time is between 11:00 and 14:59
- **THEN** Home displays `Selamat siang` addressed to the user's first name

#### Scenario: Afternoon greeting
- **WHEN** the application-local time is between 15:00 and 17:59
- **THEN** Home displays `Selamat sore` addressed to the user's first name

#### Scenario: Evening or overnight greeting
- **WHEN** the application-local time is between 18:00 and 03:59
- **THEN** Home displays `Selamat malam` addressed to the user's first name

### Requirement: Home provides permission-aware Quick Access
The Home page SHALL contain a Quick Access card for the requested operational actions and SHALL render each action only when the authenticated user satisfies every permission and current-business feature prerequisite enforced by its destination workflow. When no action is available, the card SHALL remain visible and display an empty-state message instead of an unauthorized link.

#### Scenario: Purchase creation is available
- **WHEN** the authenticated user has `purchases.create`
- **THEN** Quick Access displays `Buat Pembelian` linked to the existing purchase creation route

#### Scenario: Sale creation is available
- **WHEN** the authenticated user has `sales.create`
- **THEN** Quick Access displays `Buat Penjualan` linked to the existing sale creation route

#### Scenario: POS session opening is available
- **WHEN** POS is enabled for the current business and the authenticated user has both `pos.access` and `pos.sessions.open`
- **THEN** Quick Access displays `Buka Sesi POS` linked to the existing POS session creation route

#### Scenario: POS session opening is unavailable
- **WHEN** POS is disabled for the current business or the authenticated user lacks either `pos.access` or `pos.sessions.open`
- **THEN** Quick Access does not display `Buka Sesi POS`

#### Scenario: Global purchase payment is available
- **WHEN** the authenticated user has both `purchasePayments.global.access` and `purchasePayments.create`
- **THEN** Quick Access displays `Buat Pembayaran Pembelian Global` linked to the existing global purchase payment workspace

#### Scenario: Global sales payment is available
- **WHEN** the authenticated user has both `salePayments.global.access` and `salePayments.create`
- **THEN** Quick Access displays `Buat Pembayaran Penjualan Global` linked to the existing global sales payment workspace

#### Scenario: User lacks an action permission
- **WHEN** the authenticated user does not satisfy every permission required by a Quick Access action
- **THEN** the system does not render that action or its destination link

### Requirement: Sidebar exposes working Home and Dashboard navigation
The authenticated sidebar SHALL provide separate functional links for Home and Dashboard and SHALL indicate the active item according to the current route.

#### Scenario: User follows Dashboard navigation
- **WHEN** the user selects Dashboard in the sidebar
- **THEN** the system navigates to the dedicated Dashboard route and marks Dashboard active

#### Scenario: User follows Home navigation
- **WHEN** the user selects Home in the sidebar
- **THEN** the system navigates to the Home route and marks Home active

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

