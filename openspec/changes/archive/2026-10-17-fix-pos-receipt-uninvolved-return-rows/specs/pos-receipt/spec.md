# Spec Delta

## MODIFIED Requirements

### Requirement: Receipt return history includes completed effective returns
The receipt SHALL list every completed, non-reversed return in execution order with a stable tie-break, including reference, completion date, completed status, product, returned quantity, resolution, and actual cash-refund amount or replacement details. Rows with no return action (`resolution = none`) SHALL NOT appear as returned items, even when they retain a positive quantity or serial identity. This exclusion SHALL apply to existing persisted records as well as future records and SHALL NOT change stored return evidence, purchase quantities, serial replacement lineage, or settlement amounts. Serial replacements SHALL identify both returned and replacement serials. Cash-return details SHALL distinguish the purchase-value reduction, debt reduction, and actual refund so a debt offset is not described as cash paid. Draft, pending, approval-only, incomplete, rejected, cancelled, archived, reversed, and deleted returns SHALL NOT contribute return adjustments or appear as completed history.

#### Scenario: Multiple mixed returns
- **WHEN** a receipt has multiple completed cash returns and replacements alongside a pending return
- **THEN** all completed effective returns appear once with their individual details and the main purchase reflects their combined effect
- **AND** the pending return has no receipt adjustment or completed-history entry

#### Scenario: Approval alone is insufficient
- **WHEN** a return is approved but its execution is not completed
- **THEN** no completed return history or corresponding quantity or serial adjustment is shown

#### Scenario: Replacement followed by an unrelated partial cash return
- **WHEN** a completed laptop replacement is followed by a completed one-unit photo-paper cash return whose saved lines also contain the laptop with no return action
- **THEN** the first history entry shows the laptop replacement with its returned and replacement serials
- **AND** the second entry shows only the one-unit photo-paper cash return with its saved settlement values
- **AND** five originally purchased photo-paper units leave four in the main purchase list
- **AND** the laptop remains in the main purchase list with its effective replacement serial

#### Scenario: Positive quantity and serial do not establish a return action
- **WHEN** an existing completed return contains a no-action row with positive quantity and a serial alongside actual cash-return or replacement rows
- **THEN** that no-action row is omitted from receipt return-history items
- **AND** the actual return rows retain their quantities, values, and serial details
- **AND** receipt generation leaves persisted return records unchanged
