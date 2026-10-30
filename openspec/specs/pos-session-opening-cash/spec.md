# pos-session-opening-cash Specification

## Purpose

Provide accountable opening cash capture for POS terminal sessions through global denomination counts and explicitly authorized nominal entry.

## Requirements

### Requirement: Opening cash uses globally enabled denominations
The system SHALL use the existing global cash denomination configuration for terminal session opening, without creating business or terminal-specific copies. It SHALL display enabled denominations in configured order with banknote/coin labels and quantity units.

#### Scenario: Same configuration across businesses
- **WHEN** users open terminal sessions in different businesses
- **THEN** both forms display the same globally enabled denominations and values
- **AND** opening a session does not modify the configuration

#### Scenario: No enabled denominations
- **WHEN** all denominations are disabled
- **THEN** denomination opening is blocked with a configuration message
- **AND** authorized users can still explicitly select nominal entry
- **AND** other users receive no automatic bypass

### Requirement: Denomination quantities determine opening cash by default
Terminal session opening SHALL default to denomination entry for every user. Each enabled denomination SHALL accept a nonnegative whole-number quantity, with blank quantities treated as zero. The form SHALL display subtotals and a calculated total. The server SHALL derive a strictly positive total from accepted counts without trusting a client-supplied total.

#### Scenario: Counted opening cash
- **WHEN** a user submits two Rp50,000 notes and three Rp10,000 notes
- **THEN** opening cash and initial expected cash equal Rp130,000
- **AND** the form requires no manually entered total

#### Scenario: Invalid counts
- **WHEN** counts are negative, fractional, nonnumeric, or sum to zero
- **THEN** opening fails with validation feedback and creates no session or cash event

#### Scenario: Forged or stale denominations
- **WHEN** a request includes an unknown or disabled denomination, including one disabled since the form loaded
- **THEN** opening fails with feedback to review the current configuration
- **AND** no session or cash event is created

#### Scenario: Tampered total
- **WHEN** a denomination-mode request includes a total different from its valid count sum
- **THEN** the persisted total equals the server-calculated count sum

### Requirement: Nominal entry requires explicit permission
The system SHALL allow users with both `pos.sessions.open` and `pos.sessions.open.total-only` to explicitly switch to nominal entry and submit a strictly positive numeric amount. The bypass permission SHALL be an assignable exception, absent from default cashier and floor-staff bundles. A terminal policy flag SHALL neither grant nor deny this user permission.

#### Scenario: Authorized nominal opening
- **WHEN** a permitted user explicitly selects nominal mode and submits Rp150,000
- **THEN** the session opens with opening and initial expected cash of Rp150,000
- **AND** denomination counts are not required

#### Scenario: Unauthorized bypass
- **WHEN** a user without bypass permission submits nominal mode or a total without denomination counts
- **THEN** opening is rejected without creating a session or cash event, regardless of the terminal total-only flag

#### Scenario: Permission revoked after page load
- **WHEN** a user's bypass permission is revoked before nominal submission
- **THEN** the server rejects nominal entry

#### Scenario: Bypass does not grant session access
- **WHEN** a user has only the bypass permission
- **THEN** the user cannot open a POS session

### Requirement: Opening cash evidence is retained atomically
The system SHALL atomically persist opening cash, initial expected cash, and one OPEN_FLOAT event. Denomination mode SHALL retain a value-to-quantity snapshot; nominal mode SHALL retain null denominations. The event SHALL identify the entry mode and acting user. Later configuration edits SHALL NOT alter historical counts or totals.

#### Scenario: Historical evidence
- **WHEN** a denomination is disabled after a successful counted opening
- **THEN** the recorded opening breakdown and total remain unchanged

#### Scenario: Nominal mode with stale counts
- **WHEN** an authorized nominal request also contains counts left over from denomination mode
- **THEN** the nominal amount is used and the cash event retains no denomination breakdown

#### Scenario: Failed opening
- **WHEN** opening fails validation or an existing terminal/session constraint
- **THEN** no partial session or opening event is created

