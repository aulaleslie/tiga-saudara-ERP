## ADDED Requirements

### Requirement: Sales import tax follows resolved owner PKP status
The sales importer SHALL apply CSV sales tax only when the generated sale's resolved owner setting is PKP, and SHALL suppress tax for non-PKP generated owner sales.

#### Scenario: Asterisk owner row may retain CSV tax
- **WHEN** a sales CSV row resolves to `CV TIGA NUSA COMPUTER` from a leading `*` product-name marker
- **AND** that setting has `is_pkp = true`
- **AND** the row has CSV `pajak` or `tarif_pajak`
- **THEN** the importer MUST allow tax resolution for that row
- **AND** the generated sale detail MAY persist a non-null `tax_id`
- **AND** the generated sale detail MAY persist a positive `product_tax_amount`

#### Scenario: TP owner row suppresses CSV tax when non-PKP
- **WHEN** a sales CSV row resolves to `CV TOP IT INTERNUSA` from a ` TP` product-name suffix
- **AND** that setting has `is_pkp = false`
- **AND** the row has CSV `pajak` or `tarif_pajak`
- **THEN** the importer MUST persist `tax_id` as null for that sale detail
- **AND** the importer MUST persist `product_tax_amount` as `0.00`
- **AND** the generated owner sale header tax amount MUST NOT include tax from that row

#### Scenario: Perdana owner row suppresses CSV tax when non-PKP
- **WHEN** a sales CSV row resolves to `PERDANA` from the unmarked product-name fallback
- **AND** that setting has `is_pkp = false`
- **AND** the row has CSV `pajak` or `tarif_pajak`
- **THEN** the importer MUST persist `tax_id` as null for that sale detail
- **AND** the importer MUST persist `product_tax_amount` as `0.00`
- **AND** the generated owner sale header tax amount MUST NOT include tax from that row

#### Scenario: Split invoice allocation uses persisted tax-gated totals
- **WHEN** a source sales invoice splits into PKP and non-PKP generated owner sales
- **AND** CSV tax fields are present on rows for multiple owners
- **THEN** the importer MUST compute owner group totals using the same PKP-gated tax values that will be persisted to sale details
- **AND** proportional document adjustment and payment allocation MUST be based on those persisted tax-gated owner totals
