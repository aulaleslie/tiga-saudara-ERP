# Spec Delta

## MODIFIED Requirements

### Requirement: Approved receiving supports a controlled full reversal
The system SHALL allow only an authorized user to fully reverse an approved Consignment Receiving Note with a required reason when every affected product, location stock, serial, average-cost state, and transaction remains eligible for exact reversal and no later dependent movement, sale, transfer, return, adjustment, allocation, bill, or incompatible cost event exists. Active sold-receipt attribution, approved buyout reservations, and non-reversed acquisition evidence SHALL be included in these dependency checks even when current stock equals a historical balance. Partial reversal and direct editing SHALL be prohibited.

#### Scenario: Eligible receipt is fully reversed
- **WHEN** an authorized user confirms reversal of an eligible approved receipt
- **THEN** the system SHALL atomically restore exact pre-approval stock buckets, global and setting quantities, setting-scoped average snapshots, serial state/history, and supplier provenance availability
- **AND** it SHALL create explicit reversal transaction/audit evidence rather than deleting historical approval records

#### Scenario: Later movement blocks reversal
- **WHEN** any affected product or serial has a later incompatible inventory or cost movement
- **THEN** reversal SHALL be rejected with the blocking evidence
- **AND** no state SHALL change

#### Scenario: Partial reversal is attempted
- **WHEN** a request attempts to reverse only selected lines, quantities, or serials
- **THEN** the system SHALL reject it without mutation

#### Scenario: Buyout reserves the receipt
- **WHEN** an approved unfinalized buyout reserves any quantity or serial from a receiving detail
- **THEN** reversal of its receiving note SHALL be rejected with the buyout reference and no state change

#### Scenario: Ownership acquisition consumed the receipt
- **WHEN** a finalized non-reversed buyout depends on the receipt, including sold-only slices with no physical movement
- **THEN** receiving reversal SHALL remain blocked even if location balances happen to match the original receiving snapshot

#### Scenario: Buyout cancellation removes its reservation
- **WHEN** an unfinalized buyout is cancelled and releases its reservations
- **THEN** receiving reversal SHALL be evaluated against all remaining dependencies rather than treating the released reservation as active
