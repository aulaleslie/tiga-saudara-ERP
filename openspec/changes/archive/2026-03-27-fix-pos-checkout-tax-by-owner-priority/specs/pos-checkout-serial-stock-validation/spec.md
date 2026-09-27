## REMOVED Requirements

### Requirement: Non-serial pre-check semantics SHALL remain unchanged
**Reason**: Checkout pre-check now requires owner-priority behavior for taxable non-serial lines; preserving previous unchanged semantics would conflict with the new business rule.
**Migration**: Update finalize stock-precheck expectations and related tests to assert owner-priority ordering (non-PKP source owners first, then PKP) while keeping configured location order within each owner-priority group.

## ADDED Requirements

### Requirement: Non-serial taxable pre-check SHALL apply owner-priority allocation
For non-serial taxable lines, finalize stock pre-check MUST allocate across allowed locations by owner-priority order: source owners with `is_pkp=false` first, then source owners with `is_pkp=true`. Within each owner-priority group, configured sales-location order SHALL remain deterministic.

#### Scenario: Non-serial taxable line prefers non-PKP source before PKP source
- **WHEN** a taxable non-serial line can be fulfilled from both non-PKP and PKP source owners
- **THEN** finalize pre-check MUST allocate required quantity from non-PKP-owned locations first
- **AND** only allocate from PKP-owned locations for any remaining quantity.

#### Scenario: Owner-priority preserves configured location ordering within each priority group
- **WHEN** multiple allowed locations belong to the same owner-priority group (all non-PKP or all PKP)
- **THEN** finalize pre-check MUST consume stock following configured sales-location order within that group
- **AND** identical inputs MUST produce deterministic allocation output.
