## Context

Phase 1 has successfully replaced critical exception messages in core POS services (FinalizePosCheckoutService, PosCartService, InlinePosCheckoutPostingAdapter, PosSessionFinalizeService) and authentication (LoginController). Phase 2 focuses on HIGH priority user-facing messages: authorization errors shown via `abort()` calls, validation messages returned in HTTP responses, and flash messages displayed in Livewire components during product table operations.

The codebase uses direct string replacement (not Laravel localization helpers like `trans()` or `__()`), which means each English string must be manually replaced with its Indonesian equivalent directly in the source code.

## Goals / Non-Goals

**Goals:**
- Replace all HIGH priority English strings with Indonesian equivalents (11 strings across 6 files)
- Ensure all authorization error messages display in Indonesian
- Ensure all Livewire component flash messages (product table operations) display in Indonesian
- Ensure validation failure messages display in Indonesian
- Maintain test suite integrity (verify or update assertions that check these strings)

**Non-Goals:**
- Creating or modifying Laravel localization files
- Implementing dynamic language switching or locale detection
- Refactoring message delivery mechanisms
- Changing code behavior or logic
- Handling MEDIUM or LOW priority strings in this phase

## Decisions

### Decision 1: Direct String Replacement (as per Phase 1 established pattern)
**Choice:** Continue with direct in-code string replacement rather than introducing Laravel `trans()` helpers

**Rationale:**
- Consistency with Phase 1 implementation approach
- Simpler for developers to audit (string visible in code vs. lookup in localization files)
- No additional dependencies or configuration changes needed
- Aligns with inventory document which explicitly states "no Laravel localization helpers"

**Alternatives Considered:**
- Implement Laravel localization files with `trans()` - would require restructuring Phase 1 work; rejected for consistency
- Use a translation management tool - overkill for direct replacement; rejected for simplicity

### Decision 2: File Processing Order
**Choice:** Process files in this order:
1. POS Controllers (`PosSellController.php`, `PosSessionController.php`)
2. Validation Request (`StorePosSessionCloseRequest.php`)
3. Livewire Components (`ProductTable.php` files)

**Rationale:**
- Controllers are fewer files and test easily
- Validation request is single file, single string
- Livewire components are last because they may require more careful component testing

**Alternatives Considered:**
- Process by file size - rejected; logical module grouping is clearer
- Process alphabetically - rejected; logical grouping is more maintainable

### Decision 3: Test Assertion Updates
**Choice:** Update test assertions that compare English strings to Indonesian equivalents; verify test behavior remains sound

**Rationale:**
- Tests that assert error messages must match the new Indonesian strings
- This prevents test failures from masking real errors
- Verification ensures replacements are in correct context

**Alternatives Considered:**
- Don't update tests - would cause failures; rejected for reliability
- Mock/skip message assertions - loses test coverage; rejected

## Risks / Trade-offs

**[Risk]** Tests may fail if string changes weren't anticipated
→ **Mitigation**: After each file replacement, run relevant tests to catch mismatches immediately

**[Risk]** Copy-paste errors when replacing long strings
→ **Mitigation**: Use Find & Replace with "Replace" (not "Replace All") to verify context before each replacement

**[Risk]** Indonesian messages may be longer/shorter than English, affecting UI layout
→ **Mitigation**: Verify messages display properly in UI/browser testing after replacements

**[Risk]** Livewire components may cache messages
→ **Mitigation**: Run `php artisan view:clear` and `php artisan cache:clear` after deployment

**[Trade-off]** No language-switching capability
→ **Accepted**: Direct replacement is simple and matches project requirements; future localization needs would be addressed in a separate initiative

## Migration Plan

1. **Create feature branch:** `git checkout -b feat/localize-phase-2`
2. **Replace strings in order** (see Decision 2 order above)
3. **After each file:** Run focused tests to verify no regressions
4. **After all replacements:** Run full test suite: `php artisan test`
5. **Manual verification:** Test affected workflows in browser
6. **Commit and create PR** for review

**Rollback Strategy:**
- If tests fail: `git diff` to identify problematic replacements, fix and re-test
- If deployment issue: `git revert <commit-hash>` to roll back change
- Minimal risk: direct string replacements are easily reversible

## Open Questions

1. **Test Coverage:** Do all message strings have corresponding test assertions, or are some messages untested?
   - Resolution: Check test coverage during implementation; flag untested messages
2. **UI Impact:** Should we verify Indonesian messages don't break UI layouts (form fields, error containers)?
   - Resolution: Perform browser testing for components with long messages (Livewire ProductTable)
3. **Special Characters:** Are there any Indonesian characters (ä, ö, ü, etc.) that might cause encoding issues?
   - Resolution: Verify UTF-8 encoding is correct in all files; PHP files should have `<?php` with no BOM
