## ADDED Requirements

### Requirement: Receipt expresses packing split for conversion lines

The POS receipt SHALL express a packed line's unit breakdown as its packing split (number of boxes plus loose base units) rather than a single conversion-unit price, so the printed line reflects how the quantity was decomposed.

#### Scenario: Receipt shows box plus loose base unit split
- **WHEN** a packed line has quantity 6 decomposed into 1 box (factor 5) plus 1 loose base unit
- **THEN** the receipt line's unit breakdown reads as "1 box + 1 ream" (or the product's box and base unit names)

#### Scenario: Pure loose line shows base units only
- **WHEN** a packed line has quantity 3 with no full box group
- **THEN** the receipt line's unit breakdown shows only loose base units and no box
