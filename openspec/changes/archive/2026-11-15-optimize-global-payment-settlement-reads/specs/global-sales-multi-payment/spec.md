# Spec Delta

## ADDED Requirements

### Requirement: Global Sales candidate search remains complete
Global Sales search SHALL preserve the complete union of tokenized text matches and full-input barcode or serial matches while avoiding repeated relationship traversal per eligible Sale. Every text token SHALL match a supported field on the same Sale, and the resulting distinct Sales SHALL retain all existing authorization, customer, business, date, lifecycle and archive constraints.

#### Scenario: Tokens match different sources
- **WHEN** tokens match different supported header, customer, tag, POS identifier, bundle or current-product fields on one Sale
- **THEN** that Sale is included only when every token matches
- **AND** repeated tokens do not duplicate rows or counts
- **AND** historical detail product snapshots do not replace current-product search authority

#### Scenario: Identity and text produce different Sales
- **WHEN** the complete input exactly identifies a barcode or serial for one Sale and also satisfies text criteria for other eligible Sales
- **THEN** the result includes both groups without duplication
- **AND** the identity match does not terminate text searching

#### Scenario: Exact identity preserves provenance
- **WHEN** search uses barcode or serial input with case differences, normalization, whitespace or partial identity text
- **THEN** complete-input exact matching and existing serial provenance rules are preserved
- **AND** a partial identity alone does not match through an exact-identity branch

#### Scenario: Search is absent or constrained
- **WHEN** search is empty or whitespace-only
- **THEN** existing non-search results remain unchanged
- **WHEN** nonempty search is combined with an embedded customer, business or date scope
- **THEN** candidate discovery does not expose Sales outside that scope

### Requirement: Exact global Sales financial pagination avoids duplicate evaluation
Nonempty financial-filtered global Sales pages SHALL obtain ordered rows and exact matching totals without a separate full financial-predicate evaluation solely for counting. Existing filter composition, reporting-date sort behavior, identifier tie-breaking, page sizes, URLs and normal non-global table behavior SHALL be preserved.

#### Scenario: Nonempty filtered page
- **WHEN** an outstanding, overdue or recently paid filter produces a nonempty page
- **THEN** its ordered Sale IDs and total match the existing scoped list
- **AND** counting does not independently repeat the full financial predicate
- **AND** detailed presentation data is loaded only for visible rows

#### Scenario: Last page and tied dates
- **WHEN** matching Sales have tied effective document dates or the last page is partially filled
- **THEN** identifier tie-breaking is deterministic and page boundaries neither skip nor duplicate Sales
- **AND** reporting-date overrides retain their existing sorting effect

#### Scenario: Empty or out-of-range page
- **WHEN** no Sale matches the filters
- **THEN** the page is empty with total zero
- **WHEN** a requested page lies beyond the last page of a nonempty result
- **THEN** it remains empty while reporting the correct positive total
- **AND** a fallback total calculation is permitted for this empty-page case

### Requirement: Global Sales summaries share fresh settlement aggregation
A global Sales summary refresh SHALL calculate all card counts and amounts from shared settlement aggregation rather than independently rebuilding the same eligible settlement set for each card. Existing per-Sale monetary rounding, clamping, credit and transfer effects, return reductions, recent-collection meaning and summary scope SHALL remain unchanged.

#### Scenario: Multiple ledgers and recent collections
- **WHEN** eligible Sales have multiple payments, credits, owner transfers or return adjustments
- **THEN** outstanding and overdue totals retain the existing live-balance values without multiplied ledger amounts
- **AND** recent collection totals retain active payment amounts and distinct eligible Sale counts within the existing period

#### Scenario: Refresh follows a financial change
- **WHEN** a new summary read follows a committed payment, invalidation, transfer or return adjustment
- **THEN** the refreshed cards observe current committed data without a persisted financial-result cache
- **AND** initialization, card selection, reset, retry and loading feedback retain their existing behavior

### Requirement: Global Sales multi-stage reads retain scoped consistency
Each global Sales list or summary read SHALL combine candidate discovery, settlement evaluation, totals and page hydration against mutually consistent scoped data. Optimized reads SHALL preserve immutable customer constraints and existing payment authorization without changing authoritative payment submission.

#### Scenario: Data changes between stages
- **WHEN** matching source records or settlement data change concurrently
- **THEN** the current operation does not combine incompatible candidate and financial snapshots
- **AND** a later read observes the committed update

#### Scenario: Existing payment validation remains authoritative
- **WHEN** a user starts a payment from an optimized page
- **THEN** existing payment permissions and fresh locked allocation validation remain in force
- **AND** no ledger, checkout or historical receipt is modified by the read
