## Purpose

Identify and safely repair proven historical duplication of sale and payment value caused by completed POS replacements while preserving original transaction evidence.

## ADDED Requirements

### Requirement: Discovery and repair preview are read only and scoped
The repair tool SHALL require an explicit business scope. Discovery SHALL report candidate transaction identifiers without mutation. Detailed previews SHALL require explicit transaction selections and classify each checkout group as repairable, unchanged, or blocked, with source/replacement links, before/after sale and effective payment values, debt, expected receipt charges, and an evidence fingerprint.

#### Scenario: Discover and preview transaction 10717
- **WHEN** an operator discovers candidates for a business and previews selected transaction 10717 in its owning business
- **THEN** no monetary, stock, serial, or audit records change
- **AND** the preview identifies the duplicated Rp2,225,000 and proposes Rp2,600,000 total and effective paid, zero debt, and a Rp45,000 paper charge

#### Scenario: Missing or incorrect scope
- **WHEN** scope is missing or a selected transaction belongs to another business
- **THEN** the tool refuses that selection without mutation

### Requirement: Repair requires proven replacement and payment evidence
Repair SHALL require completed non-reversed replacement lineage, authoritative remaining charges, and traceable payment evidence proving the exact adjustment. Missing links, conflicting edits, unsupported tax or charge allocation, active overlapping returns, and unexplained payments SHALL block repair. Snapshots alone MUST NOT override legitimate later sale edits.

#### Scenario: Exact transfer can be proven
- **WHEN** source sale 214729 retains Rp2,270,000 after its printer detail becomes zero and replacement sale 214745 records Rp2,225,000 for the same completed replacement
- **AND** payment provenance proves the duplicated allocation
- **THEN** the proposed source sale and effective paid become Rp45,000 while the replacement retains Rp2,225,000
- **AND** unrelated sale 214728 remains Rp330,000

#### Scenario: Conflicting evidence
- **WHEN** payment origins or replacement lineage are missing, or an unexplained edit could account for a difference
- **THEN** the tool reports specific blocking reasons and makes no repair

### Requirement: Reviewed repair is atomic audited and repeatable
Apply SHALL require explicit transactions, business, actor, reason, and the reviewed preview fingerprint. It SHALL lock and revalidate the complete affected checkout group, update only proven accounting allocations and headers, and record source identifiers, provenance, fingerprint, actor, reason, and before/after values atomically. Failed or stale applications SHALL make no changes; unchanged reruns SHALL add no corrective entries.

#### Scenario: Evidence changes after review
- **WHEN** the locked evidence differs from the reviewed fingerprint
- **THEN** apply refuses the transaction and requires a fresh preview

#### Scenario: Failure during repair
- **WHEN** any allocation, header, audit, or reconciliation step fails
- **THEN** all writes for that checkout group roll back

#### Scenario: Repeated application
- **WHEN** a successfully repaired transaction is previewed and applied again
- **THEN** it is classified unchanged without another transfer or corrective audit

### Requirement: Repair preserves historical and physical evidence
Repair SHALL preserve original customer payment facts, completed return amounts and resolutions, sale detail quantities and selling prices, checkout snapshots, stock movements, serial lineage, and cost snapshots. It SHALL verify resulting current receipt and settlement totals using the same authoritative accounting as ordinary transactions. It MUST NOT create a customer collection or refund to balance a replacement.

#### Scenario: Repair the transaction 10717 reproduction
- **WHEN** the proven duplication is repaired
- **THEN** current receipt charges are Rp2,225,000, Rp45,000, and Rp330,000, totaling Rp2,600,000
- **AND** effective paid is Rp2,600,000 with zero debt and effective printer serial XE4Q318288
- **AND** original serial XE4Q304308 remains in return lineage and historical receipt evidence
- **AND** original payment facts, stock, return history, and checkout snapshots are preserved

### Requirement: Chained historical repair replays proven allocations in order
Historical repair SHALL replay replacement events chronologically so that an intermediate owner's recorded paid value and stale header are validated against evidence including the proven incoming allocation planned earlier in the chain. Funds consumed by one hop MUST NOT back a later hop, and intermediate headers SHALL be validated only after the complete chain is replayed.

#### Scenario: Partial chain
- **WHEN** a Rp2,225,000 replacement from A to B is followed by a Rp1,112,500 partial replacement from B to C with complete evidence
- **THEN** preview is repairable and apply succeeds with a linked second transfer of Rp1,112,500 whose predecessor is the first
- **AND** the group total remains Rp2,600,000 and no payment is allocated beyond its amount

