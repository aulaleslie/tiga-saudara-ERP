# Spec Delta

## ADDED Requirements

### Requirement: Replacement commercial preview is independent of cash valuation
Replacement preview SHALL preserve its authoritative commercial amount independently of expected cash refund. Bundled replacement amounts MUST use attributable source-sale commercial value; ordinary replacement amounts MUST retain the reviewed line amount. Zero cash refund MUST NOT erase replacement commercial evidence or change serial versus note-only execution semantics.

#### Scenario: Bundled replacement has zero refund
- **WHEN** the reviewed bundle list price is Rp1,500 but its attributable source-sale replacement value is Rp1,000
- **THEN** preview shows Rp1,000 replacement commercial value and zero customer refund
- **AND** any generated cross-owner sale effects agree with that value

#### Scenario: Ordinary replacement retains reviewed value
- **WHEN** an ordinary replacement line has reviewed commercial amount Rp1,500 and zero expected cash refund
- **THEN** preview retains Rp1,500 commercial amount without applying cash-return rounding or replacing it with zero

#### Scenario: Non-serial replacement remains note only
- **WHEN** replacement commercial display is corrected for a non-serial POS line
- **THEN** it remains note-only with no new receiving, stock, dispatch, HPP, or cash effects
