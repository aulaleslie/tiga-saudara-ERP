## ADDED Requirements

### Requirement: Company-owned inventory valuation excludes supplier-owned consignment custody
The Inventory Valuation report SHALL identify consignment receipt and reversal movements and SHALL exclude supplier-owned consignment quantity and value from company-owned inventory totals while preserving physical consignment evidence in a clearly separated view or classification.

#### Scenario: Consignment-only product is received
- **WHEN** a product has only approved consignment stock in the active setting
- **THEN** company-owned ending quantity and value SHALL exclude that consignment stock
- **AND** the report SHALL NOT present its operational average cost as company-owned inventory value

#### Scenario: Product has owned and consignment stock
- **WHEN** a product has both ordinary owned stock and supplier-owned consignment stock
- **THEN** company-owned valuation SHALL include only the owned quantity/value
- **AND** consignment quantity/value SHALL be distinguishable without double counting

#### Scenario: Consignment receipt is reversed
- **WHEN** an approved consignment receipt is fully reversed within the report period
- **THEN** consignment custody quantity/value SHALL return to its pre-receipt result
- **AND** company-owned totals SHALL remain unaffected

#### Scenario: Existing standard valuation is preserved
- **WHEN** the report contains only standard inventory activity
- **THEN** existing filters, weighted-average replay, summaries, details, pagination, and exports SHALL retain their behavior
