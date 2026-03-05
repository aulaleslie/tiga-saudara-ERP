# POS AI Execution Protocol

Use this protocol when the user provides a hook phrase like `execute ticket-1`.

## Resolution

1. Read `docs/pos/ai/commands.yaml`.
2. Resolve the exact hook string to `ticket_id` and ticket file.
3. Open the target ticket in `docs/pos/ai/tickets/`.

## Execution Rules

1. Follow ticket `Scope` and `Out of Scope` exactly.
2. Edit only paths listed in `allowed_paths`.
3. Respect `depends_on`:
   - If dependency is not complete in `ticket-status.md`, stop and report blocked state.
4. Keep behavior aligned with source docs:
   - `docs/pos/current-pos-supported-brainstorm.md`
   - `docs/pos/expected-ui-component-checklist.md`
5. Preserve these global constraints:
   - Theme-adaptive UI
   - No ecommerce integration
   - No on-screen number pad

## Validation

1. Run all `test_commands` in the ticket.
2. If tests cannot run, report the reason clearly.
3. Verify `done_when` checklist before marking complete.

## Required Output Format

1. `ticket_id`
2. `status`: `completed` | `blocked` | `failed`
3. `changed_files`
4. `tests_run`
5. `done_when_result`
6. `notes`

## Ticket Board Update

After execution:
1. Update `docs/pos/ai/ticket-status.md`.
2. Set current ticket to `completed` or `blocked` (with reason).
3. If complete, move next dependent ticket from `queued` to `ready`.
