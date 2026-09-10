## MODIFIED Requirements

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

