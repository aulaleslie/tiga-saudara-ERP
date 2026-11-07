## ADDED Requirements

### Requirement: Replacement charges reconcile without unrelated inflation
Current completed receipts SHALL count each replacement charge once across reachable owners and preserve unrelated line charges. Proven duplicated replacement value MUST NOT be spread over remaining products as a header adjustment. Contradictory replacement accounting SHALL produce an actionable refusal with historical access until repaired. Printing MUST NOT repair financial data.

#### Scenario: Transaction 10717 replacement
- **WHEN** the proven source and replacement accounting is consistent for transaction 10717
- **THEN** ordinary completed receipts show printer Rp2,225,000, SIDU paper Rp45,000, and Logitech Rp330,000
- **AND** total and paid are Rp2,600,000 with zero debt
- **AND** the printer displays XE4Q318288 and return history retains XE4Q304308 to XE4Q318288 when enabled

#### Scenario: Duplicated source value remains unrepaired
- **WHEN** replacement evidence proves that a source header retains a charge already moved into a replacement sale
- **THEN** current receipt generation refuses with a reconciliation explanation and historical receipt access
- **AND** no successful print audit, financial repair, or allocation of that duplicate onto unrelated lines occurs

#### Scenario: Legitimate later monetary edits
- **WHEN** an authorized later sale price, discount, shipping, or tax adjustment has consistent accounting evidence
- **THEN** current receipt charges reflect that adjustment with existing eligible price explanations
- **AND** the original checkout total is not used as a blanket override

#### Scenario: Equivalent ordinary print paths
- **WHEN** checkout, transaction, and global-payment ordinary completed print or reprint paths read the same replacement state
- **THEN** they show equivalent charges, effective payments, debt, quantities, and serials under existing permissions and audit distinctions
- **AND** historical receipts and draft or loaded previews retain their existing behavior

#### Scenario: Repeated replacement followed by cash return
- **WHEN** an item is replaced across owners twice and later cash-returned
- **THEN** each replacement preserves the charge once and updates effective serial identity chronologically
- **AND** the cash return reduces that current charge once under existing debt-first settlement rules
- **AND** unrelated purchase lines remain unchanged
