## Context

The Point of Sale (POS) UI currently relies on a static "Kasir Information" title within the `pos-info-strip`. There is no visual indication of the currently authenticated user operating the register. In environments where multiple users might access the same terminal, this omission can lead to confusion and lack of accountability. Furthermore, the existing information layout within `pos-info-metrics` is not fully responsive on tablet devices, often resulting in truncation (`overflow: hidden`) of important session details because the text labels consume too much horizontal space.

## Goals / Non-Goals

**Goals:**
- Replace the static "Kasir Information" title with the dynamically injected name of the logged-in user (`auth()->user()->name`).
- Make the `pos-info-metrics` strip responsive using standard Bootstrap 4/5 utility classes (`d-none`, `d-md-inline`, `d-inline`, `d-md-none`).
- Ensure no data truncation occurs on standard tablet portrait widths.

**Non-Goals:**
- Modifying the underlying POS layout CSS Grid structure.
- Changing session initialization logic or user authentication mechanics.
- Altering any functionality outside of the visual display in `Modules/Pos/Resources/views/sell/shell/info.blade.php`.

## Decisions

- **Icon-First Responsive Approach**: We decided against allowing `pos-info-metrics` to wrap (`flex-wrap: wrap`) because the parent CSS grid row has a clamped height (`clamp(64px, 9dvh, 86px)`), which risks overflowing the container if forced into two lines. Instead, we use Bootstrap's responsive display classes to hide text labels ("Sesi:", "Terminal:") on screens smaller than the `md` breakpoint, replacing them with succinct Bootstrap icons (`bi-hash`, `bi-pc-display`, `bi-clock`). This preserves the single-line layout and prevents truncation.
- **Shortened Timestamp**: On smaller screens, the "Dibuka" timestamp will be shortened to display only the time (`H:i`) instead of the full date and time, further conserving horizontal space since POS sessions are typically daily occurrences.

## Risks / Trade-offs

- **Risk**: A user with an exceptionally long name might still cause truncation within the `pos-info-title` container.
  **Mitigation**: The `pos-info-title` container naturally wraps or relies on `text-truncate` if we apply it. Given it sits in its own flex/grid track alongside the metrics, Bootstrap and CSS grid will allocate appropriate space. If necessary, we can enforce `text-truncate` on the username display.
- **Trade-off**: Dropping the full date from the "Dibuka" timestamp on tablets sacrifices some explicit information in favor of a cleaner layout. This is acceptable as sessions are routinely opened and closed within the same day.
