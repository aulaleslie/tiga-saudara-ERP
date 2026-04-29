# Implementation Plan: Fix Product Quick Add Modal Reset

**Branch**: `20260429-230139-fix-product-quick-add-reset` | **Date**: 2026-04-29 | **Spec**: [spec.md](file:///home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/specs/20260429-230139-fix-product-quick-add-reset/spec.md)

## Summary

The `ProductQuickAddModal` (Livewire 3) does not reliably clear its input fields (specifically `product_name` and `is_sold`) after a product is successfully created. This plan aims to force a DOM re-render of the modal's input fields by utilizing `wire:key` bound to a versioned state property (`formResetVersion`), ensuring a "clean slate" for subsequent entries.

## Technical Context

- **Language/Version**: PHP 8.2, Laravel 10
- **Primary Dependencies**: Livewire 3, Bootstrap 4 / CoreUI
- **Storage**: MySQL (Eloquent)
- **Testing**: Pest/PHPUnit Feature Tests
- **Project Type**: Web Application (ERP)

## Constitution Check

- **I. Brownfield First**: Fixes existing `ProductQuickAddModal` without changing its core behavior.
- **III. Laravel Pattern Fidelity**: Uses standard Livewire 3 `reset()` and `wire:key` patterns.
- **IV. Verification Proportional to Risk**: Requires a Livewire feature test to ensure state clearing.

## Project Structure

### Documentation (this feature)

```text
specs/20260429-230139-fix-product-quick-add-reset/
├── plan.md              # This file
├── spec.md              # Feature specification
└── tasks.md             # Implementation tasks (Phase 2)
```

### Source Code (affected files)

```text
app/Livewire/Modules/Product/Modals/
└── ProductQuickAddModal.php

resources/views/livewire/modules/product/modals/
└── product-quick-add-modal.blade.php
```

## Phase 0: Research & Verification

1.  **Reproduction**: 
    - Found that `ProductQuickAddModal.php` already has a `resetForm()` method that increments `formResetVersion`.
    - Identified that `product_name` and `is_sold` inputs in `product-quick-add-modal.blade.php` are NOT currently keyed by `formResetVersion`, which can lead to Livewire's diffing algorithm preserving the old DOM state/values.
2.  **Tests**: Existing test `tests/Feature/Livewire/Product/SaleProductQuickAddTest.php` can be used as a template for a new reset-specific test.

## Phase 1: Design

### 1. State Management Enhancement
- Ensure `resetForm()` in `ProductQuickAddModal.php` explicitly resets all public properties to their defaults (handled via `$this->reset([...])`).
- Verify `applyContextDefaults()` correctly sets `is_sold` to `false` for purchase context.

### 2. UI Keying
- Add `wire:key` to the `product_name` container.
- Add `wire:key` to the `is_sold` checkbox container.
- This ensures that when `formResetVersion` changes, Livewire replaces these DOM elements entirely, clearing any browser-level or diffing-level persistence.

## Phase 2: Tasks (High Level)

1.  **Verification Test**: Create `tests/Feature/Livewire/Product/ProductQuickAddResetTest.php` to verify state clearing.
2.  **PHP Refinement**: Review and refine `resetForm()` in `ProductQuickAddModal.php`.
3.  **Blade Refinement**: Add `wire:key` to affected inputs in the modal view.
4.  **Final Check**: Run tests and perform manual verification.
