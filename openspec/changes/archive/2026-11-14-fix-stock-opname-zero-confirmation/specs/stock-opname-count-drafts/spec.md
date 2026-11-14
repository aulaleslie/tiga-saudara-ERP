# Spec Delta

## ADDED Requirements

### Requirement: Physical zero requires explicit counting intent
Each product row SHALL offer “Saya sudah menghitung: stok fisik kosong” when its entered good and bad totals are both zero. Confirmation SHALL apply to that product across the selected locations and SHALL be separate from numeric counts and serial entries. Default zero counts and an empty serial list MUST NOT imply confirmation. Drafts without confirmation SHALL remain saveable.

#### Scenario: Unfinished serialized row can be saved
- **WHEN** an operator adds a serialized product, leaves its serial list empty, and saves without confirming physical zero
- **THEN** the draft retains its zero counts without recording zero confirmation or changing inventory

#### Scenario: Ordinary product is intentionally counted empty
- **WHEN** an operator enters zero good and zero bad units and checks the physical-zero checkbox
- **THEN** the draft records explicit zero intent for that product and selected location pool
- **AND** no extra dialog or mandatory explanation is required

#### Scenario: Nonzero physical count
- **WHEN** the row has any good or bad physical quantity
- **THEN** it does not require total-zero confirmation
- **AND** this change does not introduce confirmation for partial reductions or condition-only changes

### Requirement: Zero confirmation has dedicated attribution and scope
Confirmation SHALL persist under explicitly named zero-confirmation properties with a server-attributed confirmer and timestamp. Client-supplied actor and time MUST NOT be trusted. Notes, quantity values, lifecycle approval fields, and unrelated fields MUST NOT encode confirmation. Unchanged save/reopen operations SHALL preserve valid attribution.

#### Scenario: Save and reopen a confirmed row
- **WHEN** an authorized operator saves a confirmed zero row and later reopens the unchanged draft
- **THEN** its explicit confirmation, actual confirming actor, and timestamp remain available
- **AND** saving alone does not replace the confirmer with the last editor

#### Scenario: Forged attribution
- **WHEN** a request supplies another user's identity or an invented confirmation time
- **THEN** the server derives attribution from the authenticated confirmation action and ignores the invented attribution

### Requirement: Counting changes invalidate zero confirmation
Changing good/bad counts, serial entries, or selected locations SHALL clear the affected zero confirmation and attribution. Returning a changed row to zero SHALL require a fresh explicit confirmation. Removing and re-adding a product MUST NOT restore its previous confirmation. An unchanged save or unrelated header edit SHALL preserve it.

#### Scenario: Remove the last serial after counting
- **WHEN** a row's serial list changes and ends empty
- **THEN** the row is not treated as explicitly confirmed empty until the operator confirms again

#### Scenario: Change a confirmed count and return to zero
- **WHEN** the operator changes a confirmed row's count and subsequently restores zero
- **THEN** the earlier confirmation no longer authorizes zeroing stock

#### Scenario: Change selected locations
- **WHEN** a confirmed location-set change clears location-dependent counting state through the existing workflow
- **THEN** its zero confirmations and attribution are cleared with that state

#### Scenario: Changed draft submitted through a direct request
- **WHEN** a saved confirmed row's count, serial list, or scope is changed by a direct update without a fresh confirmation action
- **THEN** the server does not carry the saved confirmation into the changed row

#### Scenario: Change document note only
- **WHEN** an operator changes only the document note without changing the row or selected locations
- **THEN** valid zero confirmation retains its original attribution
