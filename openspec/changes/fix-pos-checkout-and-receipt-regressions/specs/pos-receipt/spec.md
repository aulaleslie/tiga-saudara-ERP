## MODIFIED Requirements

### Requirement: Receipt expresses packing split for conversion lines
The POS receipt SHALL express a packed line as its packing split using the actual snapshotted conversion-unit and base-unit labels. Every packed breakdown price stored in internal minor units MUST be converted to Rupiah exactly once before formatting, and placeholder labels such as `[K]`, `[P]`, or other derived initials MUST NOT be printed.

#### Scenario: Receipt shows box plus loose base-unit split
- **WHEN** a packed line has quantity 6 decomposed into 1 DUS at Rp210,000 plus 1 RIM at Rp45,000
- **THEN** the receipt displays `1 DUS @ Rp210.000` and `1 RIM @ Rp45.000` using the snapshotted unit labels

#### Scenario: Pure packed line displays the configured conversion unit
- **WHEN** a packed line consists only of one or more full conversion groups
- **THEN** the receipt displays the configured conversion-unit label and does not display `[K]` or a hardcoded generic box label

#### Scenario: Pure loose line shows the configured base unit
- **WHEN** a packed line contains only loose base units
- **THEN** the receipt displays the full configured base-unit label and does not display a first-letter placeholder

#### Scenario: Packed price remains in Rupiah on completed receipt
- **WHEN** a completed transaction snapshot stores `box_price_applied = 21000000` minor units
- **THEN** the completed receipt displays Rp210,000 and MUST NOT display Rp21,000,000 for that breakdown price

#### Scenario: Packed price remains in Rupiah on draft receipt
- **WHEN** a draft or loaded transaction snapshot stores packed breakdown prices in minor units
- **THEN** its receipt preview applies the same unit labels and minor-to-Rupiah conversion as a completed receipt

#### Scenario: Historical packed snapshot lacks unit labels
- **WHEN** an older packed transaction snapshot does not contain the new unit-label fields
- **THEN** the receipt SHALL resolve the best available configured conversion and base-unit names without emitting placeholder initials or changing persisted historical data

