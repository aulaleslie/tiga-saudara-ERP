# Tasks

## 1. Dispatch-Scoped Serial Claim Validation

- [x] 1.1 Update the POS Return serial exclusivity guard to require the persisted source dispatch identity and match claims by `returned_serial_id` plus `dispatch_detail_id`; verify existing active/non-reversed status filtering and current-return exclusion remain intact through focused assertions.
- [x] 1.2 Pass the authoritative source dispatch identity from both direct serialized return lines and synthesized serialized bundle-component lines; verify a serialized line with unresolved required dispatch lineage is rejected rather than checked globally or allowed unscoped.

## 2. Focused Regression Coverage

- [x] 2.1 Add a focused ordinary serialized-product regression test proving that a second claim from the same dispatch is blocked while the same serial sold through a later dispatch can be returned.
- [x] 2.2 Add focused serialized bundle-component coverage proving same-dispatch duplicate protection remains and a synthesized claim from a genuinely later component dispatch is accepted.
- [x] 2.3 Run only the directly affected POS Return serial test files or focused test filters with `php artisan test`; verify the new lifecycle cases and existing nearby duplicate-claim cases pass without scheduling the full project test suite.
