## MODIFIED Requirements

### Requirement: Finalize response SHALL include split result arrays
With split posting enabled, the finalize response SHALL include `split_groups[]`, `sales[]`, and `sale_payments[]` describing all posted groups, and SHALL additionally include checkout payment composition and payment-to-group allocation structures for mixed-method tender.

#### Scenario: Split-aware finalize response payload
- **WHEN** finalize succeeds for a checkout that posts into multiple groups
- **THEN** the response contains all split group records and posted IDs in grouped arrays
- **AND** the response includes normalized checkout `payments[]`
- **AND** the response includes deterministic payment allocation rows linking payment entries to split groups.

## ADDED Requirements

### Requirement: Legacy finalize fields MUST remain available
The finalize response and persisted checkout record MUST continue to expose `sale_id`, `sale_payment_id`, and `dispatch_ids` for backward compatibility.

#### Scenario: Existing client reads legacy fields
- **WHEN** an existing client consumes finalize response without split-aware parsing
- **THEN** it can still use top-level `sale_id`, `sale_payment_id`, and `dispatch_ids` fields.

### Requirement: Compatibility fields SHALL reference first deterministic group
When split posting produces more than one group, legacy compatibility fields SHALL reference the first group in deterministic `split_key` order.

#### Scenario: Multiple groups with compatibility projection
- **WHEN** finalize posts three split groups
- **THEN** top-level compatibility fields map to the first group by deterministic order.
