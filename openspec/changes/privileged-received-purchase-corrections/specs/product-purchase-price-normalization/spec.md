## ADDED Requirements

### Requirement: Corrected header adjustments contribute deterministically to purchase cost
The system SHALL treat approved received purchase details corrected through the privileged workflow as authoritative purchase-cost inputs. For cost replay and normalization, it SHALL allocate corrected global discount and shipping across eligible positive-DPP lines proportionally, reconcile rounding deterministically, reduce cost by allocated discount, increase cost by allocated shipping, and exclude input tax.

#### Scenario: Global discount and shipping adjust corrected received cost
- **WHEN** a corrected received purchase has eligible stock-managed lines plus global discount or shipping
- **THEN** cost replay and normalization SHALL use the tax-exclusive line DPP adjusted by its deterministic allocated discount and shipping share
- **AND** repeated recalculation over unchanged data SHALL produce the same unit costs

#### Scenario: Input tax remains excluded after correction
- **WHEN** a corrected received purchase includes input tax
- **THEN** purchase cost replay and normalization SHALL exclude the input tax from the corrected average purchase cost
