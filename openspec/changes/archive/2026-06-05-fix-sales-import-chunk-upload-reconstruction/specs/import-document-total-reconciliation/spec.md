## ADDED Requirements

### Requirement: Sales imports derive missing unit price from line total
The sales importer SHALL derive `harga_satuan` from `Jumlah Per Baris / Kuantitas` before document total reconciliation when the source row has no usable `Harga per Unit` value and the line total plus quantity are usable.

#### Scenario: Missing sales unit price is recovered from line total
- **WHEN** a sales CSV row has blank or zero `Harga per Unit`
- **AND** `Kuantitas` is greater than zero
- **AND** `Jumlah Per Baris` is greater than zero
- **THEN** the staged row MUST set `harga_satuan` to `Jumlah Per Baris / Kuantitas`
- **AND** the derived unit price MUST participate in invoice document total calculation

#### Scenario: JL2915 reconciles after unit price recovery
- **WHEN** invoice `JL2915` contains `STIKER NAMA UNDANGAN POLOS (103)` with `Kuantitas` `25` and `Jumlah Per Baris` `60000`
- **AND** `Harga per Unit` is missing during staging
- **THEN** the sales importer MUST derive `harga_satuan` as `2400`
- **AND** the invoice calculated total MUST reconcile to source `Total` `529000` when all invoice lines are included

#### Scenario: Existing sales unit price remains authoritative
- **WHEN** a sales CSV row has a non-zero `Harga per Unit`
- **THEN** the sales importer MUST use the source `Harga per Unit`
- **AND** the importer MUST NOT replace it from `Jumlah Per Baris / Kuantitas`

### Requirement: Sales import row mapping is consistent across entry points
All sales import entry points SHALL recognize the same source columns needed for document total reconciliation, including `Jumlah Per Baris` as the source line total fallback.

#### Scenario: Web upload and service mapping recognize line total
- **WHEN** sales import headers include `Jumlah Per Baris`
- **THEN** the web upload mapper MUST normalize it as the sales line total fallback
- **AND** the service-level mapper MUST normalize it as the sales line total fallback

#### Scenario: Local command and page upload stage equivalent row data
- **WHEN** the same sales CSV is imported through the sales import page and through the local import command
- **THEN** rows with missing `Harga per Unit` and usable `Jumlah Per Baris` plus `Kuantitas` MUST stage with the same derived `harga_satuan`
- **AND** document total reconciliation MUST see equivalent row values from both entry points
