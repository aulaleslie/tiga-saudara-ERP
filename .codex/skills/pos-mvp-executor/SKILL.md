---
name: pos-mvp-executor
description: 'Execute the POS MVP implementation for the ERP codebase using a tests-first, milestone-based workflow with explicit planning, acceptance-criteria confirmation, and progress tracking. Use when the user asks to plan or execute POS MVP work in small verifiable tasks (for example: "plan phase 1", "execute phase 1", "execute milestone 1", "status", "verify POS-MVP-013", or "continue") against `docs/pos/pos-requirements-discovery.md`, `docs/pos/pos-hybrid-technical-design.md`, `docs/pos/pos-mvp-backlog-tests-first.md`, and `docs/pos/pos-mvp-test-matrix.md`.'
---

# POS MVP Executor

## Overview

Use this skill to run the POS MVP as a controlled execution program instead of ad hoc implementation.
Break work into small tasks, confirm acceptance criteria first, implement with TDD, and maintain a persistent progress tracker.

## Start Here

Read these files first and treat them as the authoritative MVP plan set:

- `docs/pos/pos-requirements-discovery.md`
- `docs/pos/pos-hybrid-technical-design.md`
- `docs/pos/pos-mvp-backlog-tests-first.md`
- `docs/pos/pos-mvp-test-matrix.md`

Work only on Phase 1 / MVP scope unless the user explicitly asks to expand scope.

## Command Protocol

Interpret short user commands using this mapping.

- `plan phase 1`
  - Break down Phase 1 / MVP into manageable tasks from `docs/pos/pos-mvp-backlog-tests-first.md`.
  - Propose sequence, dependencies, tests-first approach, and acceptance criteria questions.
  - Do not code yet.
- `execute phase 1`
  - Execute Phase 1 tasks in order, one manageable task at a time.
  - Enforce tests-first and progress tracking after each task.
- `execute milestone N`
  - Execute only the requested milestone from `docs/pos/pos-mvp-backlog-tests-first.md`.
- `status`
  - Read and summarize `docs/pos/pos-mvp-execution-status.md`.
  - Report `done / in-progress / blocked / next`.
- `verify <task-id>`
  - Verify the specified task against acceptance criteria and tests.
- `continue`
  - Continue the next approved task from the current plan/tracker.
- `pause`
  - Stop at a checkpoint and summarize state, tests, risks, and next step.

If wording is ambiguous (for example `phase` vs `milestone`), ask one short clarification before acting.

## Planning Workflow (Always First)

When the user asks to execute work, begin with a plan unless they explicitly ask to skip planning.

### 1. Build a Manageable Task Plan

- Break the requested scope into small, reviewable tasks.
- Prefer task IDs from `docs/pos/pos-mvp-backlog-tests-first.md` (`POS-MVP-xxx`).
- Identify dependencies and sequence them clearly.
- Keep each task narrow enough to verify in one review cycle.

For each proposed task, include:

- scope
- files likely to change
- tests to write first
- acceptance criteria (draft)
- dependencies / blockers

### 2. Confirm Acceptance Criteria Before Coding

- Ask the user to confirm or adjust acceptance criteria for the next task.
- In Plan mode, use structured user-input prompts for acceptance criteria/tradeoffs when available.
- Outside Plan mode, ask concise direct questions (1-3 short questions).
- Do not write production code until acceptance criteria for the next task are confirmed.

Cover these items when asking acceptance criteria questions:

- behavior boundaries (what is in/out)
- validation/error behavior
- testability (what should be asserted)
- compatibility constraints (what must not break)

### 3. Initialize or Update the Progress Tracker

- Use `docs/pos/pos-mvp-execution-status.md` as the persistent tracker.
- If missing, create it from `assets/pos-mvp-execution-status-template.md`.
- Record:
  - current milestone
  - current task
  - task statuses (`pending`, `in-progress`, `blocked`, `done`)
  - acceptance criteria summary
  - tests run and outcomes
  - open risks / follow-ups

## Execution Workflow (Tests-First)

Use this loop for every task.

### A. Restate the Task Boundary

- Name the task ID and milestone.
- State the exact acceptance criteria being implemented.
- State what is explicitly not included in the task.

### B. Write Tests First

- Add or update automated tests before implementation.
- Prefer feature/integration tests for POS flows that cross DB and services.
- Use unit tests for calculators/resolvers (cash totals, stock allocation, tax snapshot logic).
- Run targeted tests and capture the failing baseline.

### C. Implement Minimal Code

- Implement only what is needed to satisfy the confirmed acceptance criteria.
- Preserve current sales flow safety and feature-flag behavior.
- Avoid scope creep; defer extras and note them in the tracker.

### D. Verify and Re-Run Tests

- Run the targeted tests again.
- Run additional regression tests if the touched area overlaps shared sales/dispatch/payment logic.
- Summarize test results clearly (pass/fail, command names, notable assertions).

### E. Update the Progress Tracker

After each task, update `docs/pos/pos-mvp-execution-status.md`:

- mark the task as `done` or `blocked`
- record changed files
- record tests run
- note risks / follow-ups
- set the next proposed task

Then ask whether to continue.

## Verification Workflow (`verify <task-id>`)

When the user requests verification:

1. Read the tracker entry for the task.
2. Read the related backlog item and acceptance criteria.
3. Inspect code/tests touched by that task.
4. Run relevant tests (or explain if rerun is not possible).
5. Report:
   - what passes
   - gaps or regressions
   - whether the task should remain `done` or move back to `in-progress`

## Scope Guardrails

- Stay within `MVP / Phase 1` unless the user explicitly expands scope.
- Treat Phase 2/3 items in the docs as deferred.
- Do not silently introduce split tender, POS returns/exchanges UI, loyalty, promo engine, or offline support in MVP tasks.
- Prefer feature flags and incremental changes.
- Do not break or refactor current sales flow without tests covering the changed behavior.

## Progress Tracker File Rules

- Use this exact file path: `docs/pos/pos-mvp-execution-status.md`.
- Keep entries concise and append-only for completed tasks.
- Always include dates and test evidence.
- Mark status explicitly (`pending`, `in-progress`, `blocked`, `done`).
- If the plan changes, record why.

## Output Format (Default)

### Planning Responses

Include:

1. scope summary
2. proposed tasks (with IDs)
3. dependencies
4. tests-first plan
5. acceptance criteria questions
6. wait for confirmation before coding

### Execution Responses

Include:

1. task executed
2. tests written first
3. implementation summary
4. tests run and results
5. tracker update summary
6. next task proposal

## Resource

- `assets/pos-mvp-execution-status-template.md`: template for initializing `docs/pos/pos-mvp-execution-status.md`
