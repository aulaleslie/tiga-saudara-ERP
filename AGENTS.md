<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan:
[plan.md](file:///home/aulaleslie/Workspace/Rahmat/tiga-saudara-ERP/specs/20260429-234320-harden-purchase-report/plan.md)
<!-- SPECKIT END -->

## Brownfield Context

This is an existing Laravel 10 ERP with Livewire 3, nwidart modules under `Modules/`, and established specs under `openspec/`.

- Treat `.specify/` and root `specs/` as the GitHub Spec Kit workspace for new work.
- Preserve `openspec/` history and use it as reference context when a feature overlaps existing behavior.
- Prefer the existing Laravel, Livewire, Eloquent, module, and test patterns already present in the codebase.
- For PHP verification, prefer `composer test:fresh-sqlite` or `php artisan test` with focused filters when appropriate.
- Do not overwrite unrelated working-tree changes; inspect them and work around them.
