## Context

Currently, the POS session opening form (`/pos/sessions/open`) displays Terminal selection and Total Saldo Awal (opening float) fields to all authenticated users with `pos.sessions.open` permission, regardless of whether they have `pos.sell` permission. This creates confusion because:

1. Users without `pos.sell` can see and interact with form fields they shouldn't be able to use
2. The backend only validates `pos.sessions.open`, not the `pos.sell` requirement
3. Field requirements are static (always required), not context-aware

The fix involves two coordinated changes:
- **Frontend**: Conditionally hide Terminal/Saldo fields if user lacks `pos.sell`; dynamically adjust field requirement indicators
- **Backend**: Refine validation rules to make Total Saldo mandatory only when a terminal is selected

## Goals / Non-Goals

**Goals:**
- Hide Terminal selection and Total Saldo Awal fields from users without `pos.sell` permission
- Make Terminal selection optional for all users
- Make Total Saldo Awal mandatory only when Terminal is selected (not the reverse)
- Provide clear UI indicators for field requirements that update dynamically
- Maintain backward compatibility for users with `pos.sell` permission
- Use existing permissions; no new permission gates needed

**Non-Goals:**
- Changing permission requirements for `pos.sessions.open` itself
- Modifying `PosRolePolicyService` or role detection logic
- Creating new permission types
- Changing session lifecycle behavior or business logic
- Adding new capabilities beyond this access control fix

## Decisions

### Decision 1: Frontend Field Visibility Control

**Choice**: Use Blade `@if` conditional to show/hide Terminal and Saldo fields based on `auth()->user()->can('pos.sell')`

**Rationale**:
- Simple, maintainable approach using Laravel's built-in auth checks
- No additional service calls needed
- Directly reflects server-side permission state
- Prevents form submission by hiding fields before user interaction

**Alternatives considered**:
- JavaScript-only hide/show: Rejected because doesn't prevent form submission and creates security gap
- Separate form route for non-sellers: Rejected as over-engineering
- Controller-level redirect: Rejected because prevents future "Simpan dan Buka Baru" feature for non-sellers

### Decision 2: Dynamic Field Requirement Indicators

**Choice**: Use JavaScript to toggle required attribute and label indicators based on terminal selection state

**Rationale**:
- Provides real-time feedback to user about field requirements
- Complements server-side validation
- HTML5 `required` attribute enables browser validation
- Label indicators (`*` for required, "(Opsional)" for optional) provide visual clarity

**Implementation**:
- Listen to terminal dropdown `change` events
- When terminal selected → remove `required` from Saldo, show "(Opsional)"
- When terminal empty → add `required` to Saldo, show "*"

**Alternatives considered**:
- Static UI (no dynamic updates): Rejected because creates confusion about which fields are actually required
- CSS-only approach: Rejected because can't control `required` attribute

### Decision 3: Validation Rule Logic

**Choice**: Make `opening_float_total` required when `terminal_id` has a value

```php
'opening_float_total' => [
    $hasTerminal ? 'required' : 'nullable',
    'numeric',
    'gt:0',
]
```

**Rationale**:
- Terminal is the "trigger" for requiring Saldo (conceptually: "if using a terminal, need opening balance")
- Allows non-terminal sessions to proceed without Saldo
- Simple, predictable validation rule

**Alternatives considered**:
- Requiring both OR neither: Rejected as unintuitive
- Always requiring Saldo: Rejected because conflicts with requirement
- Conditional on `pos.sell` in rules: Rejected because permission checks belong in `authorize()`, not `rules()`

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| User without `pos.sell` still submits form directly (browser dev tools) | Backend authorization gate should reject; backend also validates `required` rules. Form submission fails with validation error shown to user. |
| Terminal dropdown has async loading (Livewire); JavaScript may run before element fully loads | Added null-safety checks (`if (terminalDropdown)`) and load-time check (`updateSaldoRequirement()` on DOMContentLoaded). |
| Browser doesn't support HTML5 `required` attribute (very old browsers) | Graceful degradation: form submission validated server-side regardless. Modern browsers enforce client-side. |
| Future "Simpan dan Buka Baru" feature for non-sellers needs Notes field visible | Notes field is outside the conditional block and always visible to all users with `pos.sessions.open`. |

## Migration Plan

**Deployment steps:**
1. Deploy code changes (Blade template + validation rules)
2. No database migrations needed
3. No cache clearing needed beyond normal app boot

**Rollback strategy:**
1. Revert Blade template changes to show all fields unconditionally
2. Revert validation rule to always require Saldo
3. No data cleanup needed

**Testing approach:**
- User with `pos.sessions.open` + `pos.sell`: Should see both fields, form works as before
- User with `pos.sessions.open` only: Should see only Notes field, Terminal/Saldo hidden
- Terminal selection scenarios: Verify dynamic label/requirement updates work

## Open Questions

- Should we also update the permission documentation to clarify that `pos.sessions.open` requires `pos.sell` for full functionality?
- Future feature: when "Simpan dan Buka Baru" is added for non-sellers, should we track their usage or add analytics?
