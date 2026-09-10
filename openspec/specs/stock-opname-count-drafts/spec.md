# stock-opname-count-drafts Specification

## Purpose
Enable operators to perform location-based physical stock counts through barcode and serial scanning, review differences against existing stock, and persist proposed good/bad counts as pending draft proposals without mutating inventory before approval.
## Requirements
### Requirement: Location context governs counting
The editor SHALL use a searchable standard-location selector with a persistent selected label and derive tax treatment from that location's setting. Counting SHALL require a selected location owned by the active setting and SHALL reject consignment or foreign-setting locations before resolving scans, capturing baselines, or exposing stock information. Client-provided selected and pending location identifiers MUST NOT bypass the server-authorized location-change path. A populated location change SHALL require confirmation before clearing location-dependent state.

#### Scenario: Change location after counting
- **WHEN** the user confirms switching to another owned standard location with populated rows
- **THEN** the editor clears products, counts, serials, errors, and baselines together and displays the new selected label
- **AND** cancelling the change preserves the prior state

#### Scenario: Location determines tax allocation
- **WHEN** good and bad counts are saved at a location whose setting is PKP
- **THEN** both counts are allocated exclusively to tax quantities, regardless of serial tax metadata
- **AND** at a non-PKP location both are allocated exclusively to non-tax quantities

#### Scenario: Reject tampered location state
- **WHEN** a client attempts to set or confirm a foreign-setting or consignment location identifier
- **THEN** the editor rejects the location before any baseline or stock query uses it
- **AND** it preserves or restores the last valid selected location without disclosing stock from the rejected location

### Requirement: Search and scanning use base-unit physical counts
The editor SHALL provide a main scan input and tokenized product-search dialog, with one row per product. Search SHALL match all nonempty space-separated tokens across product name, code, barcode, category, or brand in any order and SHALL NOT require is_sold for stock-managed products. Search-added rows SHALL start at zero. Ordinary barcode scans SHALL increment the selected condition by one; conversion barcodes SHALL increment by the conversion factor in base units. Serialized barcode selection SHALL NOT increment quantity. Captured scans MUST be processed sequentially in FIFO order, independently of mutable input state, and each successful scan MUST update the authoritative count, visible count control, and submitted draft consistently.

#### Scenario: Search starts a physical count
- **WHEN** a product is selected from search
- **THEN** a missing row is created with zero good and bad counts and an existing row is focused without resetting counts
- **AND** ordinary counts are editable while serialized counts are read-only

#### Scenario: Repeated ordinary and conversion scans
- **WHEN** an ordinary product barcode is rapidly scanned twice and then its factor-12 conversion barcode is scanned with Good selected
- **THEN** the scans are processed in capture order and the one product row has good count 14 base units and unchanged bad count
- **AND** the visible count, Livewire state, and submitted draft all report 14

#### Scenario: Back-to-back scanner input remains distinct
- **WHEN** a second barcode is entered while the preceding scan request is still in flight
- **THEN** the second code is captured as a separate queue entry without concatenating with the first code or being erased by its response
- **AND** it is processed only after the preceding response completes

#### Scenario: Serialized barcode does not count a serial
- **WHEN** a serialized product or its conversion barcode is scanned
- **THEN** its row is created at zero or focused with existing counts preserved until serial entries are added

#### Scenario: Unsupported conversion precision
- **WHEN** a conversion factor cannot be represented by the supported base-quantity precision
- **THEN** the editor reports the unsupported increment without rounding or changing counts

#### Scenario: Component lookup is temporarily unavailable
- **WHEN** a captured scan cannot initially resolve its Livewire component and no request has been dispatched
- **THEN** the editor retains and retries that queue entry for a bounded period
- **AND** it displays an accessible failure message if the component remains unavailable

#### Scenario: Dispatched scan result is unknown
- **WHEN** a dispatched scan request rejects before the browser can establish its result
- **THEN** the editor MUST NOT automatically dispatch that scan again
- **AND** it displays an accessible warning containing the scanned code and instructing the operator to verify the count before rescanning

#### Scenario: Manual count remains editable
- **WHEN** an operator manually changes a non-serialized Good or Bad count and commits the field
- **THEN** the Livewire state and submitted draft retain the manual value
- **AND** no unrelated stale post-scan synchronization overwrites it

### Requirement: Main scans resolve existing serials and ambiguous codes
The main field SHALL resolve existing serials across recorded locations and SHALL NOT reject them for status, dispatch, return, or tax differences. Unknown serials SHALL NOT be created by the main field. Multiple product, serial, or conversion interpretations SHALL require a selection dialog before mutation.

#### Scenario: Existing serial adds its product
- **WHEN** a uniquely resolved existing serial is scanned with Bad selected
- **THEN** its product row is created if absent and that serial contributes one to bad count
- **AND** its source location or status does not prevent draft entry

#### Scenario: Unknown main-field serial
- **WHEN** scanned text has no recognized product barcode, conversion barcode, or existing serial match
- **THEN** the editor shows not-found feedback and leaves the draft unchanged

#### Scenario: Ambiguous scan
- **WHEN** a code matches serials on multiple products or more than one barcode/serial interpretation
- **THEN** the editor shows selectable matches and applies only the chosen interpretation
- **AND** dismissing the dialog changes no counts

### Requirement: Row serial entry accepts draft serials and prevents product-scoped duplicates
The product row serial dialog SHALL accept existing or unregistered serial text for that row's product. Serial text existing only on another product SHALL be accepted as a new draft entry for the row product. Duplicate validation SHALL use product plus serial text across good and bad entries, and SHALL apply consistently in both entry paths and save validation. Serialized counts SHALL equal the number of accepted serial entries per condition server-side.

#### Scenario: Enter an unregistered serial on a row
- **WHEN** a user enters serial text not registered to the row product in its serial dialog
- **THEN** a draft serial entry is added to that product and selected condition and its count increases by one
- **AND** no live serial record is created

#### Scenario: Duplicate across conditions
- **WHEN** a serial already counted as Good for a product is entered as Bad for that same product
- **THEN** duplicate feedback is shown without increasing counts or silently reclassifying the entry

#### Scenario: Same text on different products
- **WHEN** serial text already counted on one product is entered for a different product
- **THEN** it is accepted for the different product

#### Scenario: Remove or reclassify a serial
- **WHEN** the user removes a serial or explicitly changes its condition in the row dialog
- **THEN** the derived good/bad counts reflect the remaining entries without direct quantity editing

### Requirement: Condition entry preserves separate counts and review evidence
The editor SHALL provide a Good/Bad toggle directing subsequent inputs without changing prior entries. It SHALL display existing and proposed good/bad counts and signed differences, with baseline capture time. Known source serial information and proposed condition SHALL remain reviewable.

#### Scenario: Switch counting condition
- **WHEN** a user switches from Good to Bad and scans an ordinary barcode
- **THEN** good count is preserved and bad count increases by one

#### Scenario: Compare proposed stock
- **WHEN** a row baseline is good 10 and bad 1 and proposed counts are good 8 and bad 3
- **THEN** the comparison displays differences of minus 2 good and plus 2 bad
- **AND** save/edit retains the captured baseline rather than presenting it as approval-time live stock

### Requirement: Pending proposals persist without inventory mutations
Create/update SHALL atomically persist a versioned count proposal with location, location-setting context, per-product counts, serial text/condition/source references, and baseline. A newly created or edited proposal SHALL have `draft` status, SHALL remain editable by authorized counters, and SHALL NOT notify approvers until explicit submission. Actual stock, serial records, transactions, and stock histories SHALL remain unchanged. Explicit zero rows SHALL remain part of the proposal and omitted products SHALL remain outside it. Historical legacy-format adaptation is not required for the supported workflow.

#### Scenario: Save and reopen counts
- **WHEN** a draft proposal containing good/bad counts and unregistered serial text is saved and reopened
- **THEN** all counts, serial assignments, and baseline information are restored exactly without live inventory changes or an approval-needed notification

#### Scenario: Edit location or encounter validation errors
- **WHEN** a valid location change is saved
- **THEN** the adjustment header and proposal use the same new location
- **AND** failed validation preserves the attempted location, counts, serials, date, and note for correction

#### Scenario: Explicit zero and omitted product
- **WHEN** a proposal includes one product at zero and omits another
- **THEN** the zero-count product remains explicitly proposed as zero and no count is inferred for the omitted product

#### Scenario: Save a rejected proposal revision
- **WHEN** an authorized counter edits and saves a rejected proposal
- **THEN** the revised document becomes `draft` and requires explicit resubmission before approval

