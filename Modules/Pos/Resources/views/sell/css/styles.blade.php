<style>
    html,
    body {
        height: 100%;
        overflow: hidden;
    }

    .pos-shell {
        height: 100dvh;
        max-height: 100dvh;
        background: #f2f4f8;
        padding: 0.5rem;
        overflow: hidden;
    }

    .pos-lock-screen {
        display: none;
    }

    .pos-viewport {
        height: 100%;
        display: grid;
        grid-template-columns: minmax(0, 7fr) minmax(0, 3fr);
        grid-template-rows: clamp(64px, 9dvh, 86px) clamp(104px, 16dvh, 150px) minmax(0, 1fr) clamp(132px, 22dvh, 184px);
        grid-template-areas:
            "info nav"
            "search search"
            "cart customer"
            "cart payment";
        gap: 0.5rem;
        min-height: 0;
    }

    .pos-area {
        min-height: 0;
    }

    .pos-area-nav {
        position: relative;
        z-index: 40;
    }

    .pos-area-search {
        position: relative;
        z-index: 30;
    }

    .pos-area-customer {
        position: relative;
        z-index: 25;
    }

    .pos-area-cart,
    .pos-area-payment {
        position: relative;
        z-index: 10;
    }

    .pos-card {
        border: 1px solid #dbe1ea;
        border-radius: 0.5rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .pos-card .card-header {
        flex: 0 0 auto;
        padding: 0.36rem 0.68rem;
    }

    .pos-card .card-body {
        flex: 1 1 auto;
        min-height: 0;
        padding: 0.45rem 0.68rem;
        overflow: hidden;
    }

    .pos-area-nav .card-body,
    .pos-area-search .card-body,
    .pos-area-customer .card-body {
        overflow: visible;
    }

    .pos-section-title {
        font-size: 0.84rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        margin: 0;
        color: #1f2937;
    }

    .pos-area-info {
        grid-area: info;
    }

    .pos-area-nav {
        grid-area: nav;
    }

    .pos-area-search {
        grid-area: search;
    }

    .pos-area-cart {
        grid-area: cart;
    }

    .pos-area-customer {
        grid-area: customer;
    }

    .pos-area-payment {
        grid-area: payment;
    }

    .pos-thin-card .card-body {
        padding-top: 0.34rem;
        padding-bottom: 0.34rem;
    }

    .pos-info-strip {
        height: 100%;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.6rem;
        align-items: center;
    }

    .pos-info-title {
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
        color: #0f172a;
    }

    .pos-info-metrics {
        display: flex;
        align-items: center;
        gap: 0.72rem;
        overflow: hidden;
        white-space: nowrap;
        min-width: 0;
    }

    .pos-info-item {
        font-size: 0.74rem;
        color: #334155;
        line-height: 1.1;
        white-space: nowrap;
    }

    .pos-info-item strong {
        color: #0f172a;
        font-weight: 600;
    }

    .pos-nav-strip {
        height: 100%;
        display: grid;
        grid-template-columns: auto auto minmax(0, 1fr);
        align-items: center;
        gap: 0.45rem;
    }

    .pos-nav-label {
        font-size: 0.8rem;
        font-weight: 700;
        color: #0f172a;
        white-space: nowrap;
    }

    .pos-nav-strip .btn {
        padding-top: 0.28rem;
        padding-bottom: 0.28rem;
        font-size: 0.74rem;
    }

    .pos-nav-strip .dropdown-menu {
        max-height: 230px;
        overflow-y: auto;
        z-index: 1400;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
        border: 1px solid #dbe1ea;
    }

    .pos-nav-note {
        justify-self: end;
        font-size: 0.66rem;
        color: #94a3b8;
        line-height: 1;
    }

    #pos-shell-posting-note {
        margin: 0;
    }

    .pos-search-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-areas:
            "input actions"
            "status status";
        column-gap: 0.45rem;
        row-gap: 0.25rem;
        align-items: end;
        min-height: 0;
    }

    .pos-search-input-row {
        grid-area: input;
        min-width: 0;
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .pos-search-input-row label {
        margin-bottom: 0;
    }

    .pos-search-input-shell {
        position: relative;
    }

    #pos-shell-search {
        padding-right: 2.5rem;
    }

    #pos-shell-search-clear {
        position: absolute;
        top: 50%;
        right: 0.55rem;
        transform: translateY(-50%);
        width: 1.6rem;
        height: 1.6rem;
        border: 0;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        background: #e2e8f0;
        color: #475569;
        cursor: pointer;
        transition: background-color 0.15s ease, color 0.15s ease;
    }

    #pos-shell-search-clear:hover,
    #pos-shell-search-clear:focus {
        background: #cbd5e1;
        color: #0f172a;
        outline: 0;
    }

    #pos-shell-search-clear span[aria-hidden="true"] {
        font-size: 1rem;
        line-height: 1;
    }

    .pos-scan-action-rail {
        grid-area: actions;
        display: flex;
        flex-direction: row;
        gap: 0.35rem;
        flex-wrap: nowrap;
        align-items: stretch;
        align-self: end;
        justify-self: end;
    }

    .pos-scan-action-primary {
        flex: 0 1 auto;
        min-width: 90px;
        white-space: nowrap;
    }

    .pos-scan-action-secondary {
        flex: 0 1 auto;
        min-width: 110px;
        white-space: nowrap;
    }

    .pos-scan-action-camera {
        flex: 0 1 auto;
        min-width: 45px;
        opacity: 0.5;
        cursor: not-allowed;
    }

    .pos-scan-action-primary,
    .pos-scan-action-secondary,
    .pos-scan-action-camera {
        min-height: calc(1.5em + 0.75rem + 2px);
    }

    .pos-scan-action-camera:disabled {
        cursor: not-allowed;
    }

    #pos-shell-scan-feedback {
        height: calc(1.5em + 0.75rem + 2px);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    #pos-shell-search-status {
        grid-area: status;
        min-height: 0;
        line-height: 1.2;
        margin: 0;
    }

    .pos-camera-scanner-dialog {
        max-width: min(92vw, 760px);
    }

    .pos-camera-scanner-modal .modal-content {
        border: 0;
        border-radius: 1rem;
        overflow: hidden;
        background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
        color: #e2e8f0;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
    }

    .pos-camera-scanner-modal .modal-header,
    .pos-camera-scanner-modal .modal-footer {
        border-color: rgba(148, 163, 184, 0.2);
        background: rgba(15, 23, 42, 0.72);
    }

    .pos-camera-scanner-modal .modal-header {
        align-items: flex-start;
        gap: 0.75rem;
    }

    .pos-camera-scanner-title-wrap {
        min-width: 0;
    }

    .pos-camera-scanner-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: #f8fafc;
    }

    .pos-camera-scanner-subtitle {
        margin: 0.15rem 0 0;
        font-size: 0.82rem;
        color: #cbd5e1;
    }

    .pos-camera-scanner-close {
        color: #f8fafc;
        opacity: 0.9;
        text-shadow: none;
    }

    .pos-camera-scanner-body {
        display: grid;
        gap: 0.9rem;
    }

    .pos-camera-preview-shell {
        position: relative;
        border-radius: 0.95rem;
        overflow: hidden;
        background: #020617;
        box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.16);
    }

    .pos-camera-preview-shell::after {
        content: "";
        position: absolute;
        inset: 0;
        background:
            linear-gradient(to bottom, rgba(2, 6, 23, 0.3), rgba(2, 6, 23, 0.1) 26%, rgba(2, 6, 23, 0.1) 74%, rgba(2, 6, 23, 0.34)),
            radial-gradient(circle at top, rgba(59, 130, 246, 0.16), transparent 48%);
        pointer-events: none;
    }

    .pos-camera-video {
        width: 100%;
        max-height: min(62vh, 460px);
        background: #000;
        object-fit: cover;
        display: block;
    }

    .pos-camera-scan-lane {
        position: absolute;
        left: 8%;
        right: 8%;
        top: 50%;
        transform: translateY(-50%);
        height: clamp(74px, 16vw, 108px);
        border: 2px solid rgba(248, 250, 252, 0.92);
        border-radius: 1rem;
        box-shadow:
            0 0 0 9999px rgba(2, 6, 23, 0.38),
            0 0 0 1px rgba(125, 211, 252, 0.4) inset;
        pointer-events: none;
    }

    .pos-camera-scan-lane::before {
        content: "";
        position: absolute;
        left: 6%;
        right: 6%;
        top: 50%;
        height: 2px;
        transform: translateY(-50%);
        background: linear-gradient(90deg, rgba(56, 189, 248, 0), rgba(56, 189, 248, 0.95), rgba(56, 189, 248, 0));
        box-shadow: 0 0 12px rgba(56, 189, 248, 0.55);
    }

    .pos-camera-guide-copy {
        position: absolute;
        left: 50%;
        bottom: 1rem;
        transform: translateX(-50%);
        padding: 0.45rem 0.75rem;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.74);
        color: #e2e8f0;
        font-size: 0.76rem;
        line-height: 1.2;
        text-align: center;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.3);
    }

    .pos-camera-session-status {
        border-radius: 0.95rem;
        background: rgba(15, 23, 42, 0.64);
        border: 1px solid rgba(148, 163, 184, 0.18);
        padding: 0.85rem 0.95rem;
    }

    .pos-camera-status-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        background: rgba(59, 130, 246, 0.16);
        color: #bfdbfe;
    }

    .pos-camera-session-status[data-status-tone="ready"] .pos-camera-status-chip {
        background: rgba(59, 130, 246, 0.16);
        color: #bfdbfe;
    }

    .pos-camera-session-status[data-status-tone="accepted"] .pos-camera-status-chip {
        background: rgba(34, 197, 94, 0.16);
        color: #bbf7d0;
    }

    .pos-camera-session-status[data-status-tone="warning"] .pos-camera-status-chip {
        background: rgba(245, 158, 11, 0.16);
        color: #fde68a;
    }

    .pos-camera-session-status[data-status-tone="error"] .pos-camera-status-chip {
        background: rgba(248, 113, 113, 0.16);
        color: #fecaca;
    }

    .pos-camera-session-headline {
        margin: 0.55rem 0 0.15rem;
        font-size: 0.98rem;
        font-weight: 700;
        color: #f8fafc;
    }

    .pos-camera-session-detail {
        margin: 0;
        font-size: 0.82rem;
        line-height: 1.45;
        color: #cbd5e1;
    }

    .pos-camera-session-meta {
        margin-top: 0.75rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        font-size: 0.72rem;
        color: #94a3b8;
    }

    .pos-camera-session-meta span {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    @media (max-width: 767.98px) {
        .pos-camera-scanner-modal .modal-body {
            padding: 0.85rem;
        }

        .pos-camera-scanner-modal .modal-footer {
            padding-top: 0.65rem;
        }

        .pos-camera-guide-copy {
            width: calc(100% - 1.5rem);
            white-space: normal;
        }
    }

    .pos-scanner-debug-panel {
        display: none;
        margin-top: 0.75rem;
        padding: 0.75rem;
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.82));
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 0.75rem;
        font-size: 0.72rem;
        font-family: ui-monospace, monospace;
        color: #cbd5e1;
        line-height: 1.5;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    }

    .pos-scanner-debug-panel.is-active {
        display: block;
    }

    .pos-scanner-debug-grid {
        display: grid;
        gap: 0.2rem;
    }

    .pos-scanner-debug-row {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.16rem 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.08);
        align-items: flex-start;
        min-width: 0;
    }

    .pos-scanner-debug-row:last-child {
        border-bottom: 0;
    }

    .pos-scanner-debug-row.is-wrap {
        flex-direction: column;
        gap: 0.2rem;
    }

    .pos-scanner-debug-row span:first-child {
        color: #94a3b8;
        flex-shrink: 0;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 0.64rem;
    }

    .pos-scanner-debug-row span:last-child {
        text-align: right;
        word-break: break-all;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        min-width: 0;
    }

    .pos-scanner-debug-row.is-wrap span:last-child {
        text-align: left;
    }



    .pos-cart-shell {
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .pos-cart-table-wrap {
        flex: 1 1 auto;
        min-height: 0;
        overflow: auto;
        border: 1px solid #e2e8f0;
        border-radius: 0.45rem;
        background: #fff;
    }

    .pos-cart-table {
        margin-bottom: 0;
    }

    .pos-cart-table td,
    .pos-cart-table th {
        vertical-align: middle;
        padding: 0.31rem 0.4rem;
        font-size: 0.77rem;
    }

    .pos-cart-product {
        max-width: 320px;
    }

    .pos-cart-product .name {
        font-weight: 700;
        line-height: 1.2;
    }

    .pos-cart-product .meta {
        font-size: 0.69rem;
        color: #64748b;
        line-height: 1.2;
    }

    .pos-cart-qty {
        width: 55px !important;
        margin: 0 !important;
        padding: 0.25rem 0.375rem !important;
        flex: 0 0 auto;
    }

    /* Phase 4: Serial UI Refinement */
    .pos-serial-wrapper {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        align-items: center;
    }

    .pos-serial-action {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }

    .pos-serial-action .bi {
        font-size: 0.85rem;
    }

    .pos-serial-action-label {
        font-size: 0.64rem;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .pos-serial-chip {
        display: inline-flex;
        align-items: center;
        background-color: #17a2b8;
        color: white;
        padding: 1px 6px;
        border-radius: 4px;
        font-size: 0.725rem;
        font-weight: 500;
        line-height: normal;
        border: 1px solid rgba(0,0,0,0.1);
    }

    .pos-serial-chip span {
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pos-serial-chip .js-serial-remove {
        background: transparent;
        border: none;
        color: rgba(255,255,255,0.8);
        padding: 0;
        margin-left: 5px;
        font-size: 1rem;
        line-height: 1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: color 0.15s;
    }

    .pos-serial-chip .js-serial-remove:hover {
        color: #fff;
    }

    .pos-cart-actions {
        text-align: center;
    }

    .pos-cart-actions .btn {
        min-width: 60px;
        font-size: 0.69rem;
        padding-top: 0.18rem;
        padding-bottom: 0.18rem;
    }

    /* Quantity Reduction Button */
    .pos-qty-reduce-btn {
        padding: 0.25rem 0.4rem;
        font-size: 0.8rem;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        min-height: 28px;
        white-space: nowrap;
    }

    .pos-qty-reduce-btn .bi {
        font-size: 0.9rem;
    }

    /* Task 3.1: Shared qty control strip with stable slot width across reduce/periksa/approved states */
    .pos-qty-control-strip {
        /* Reserve fixed minimum space for left slot to prevent jitter when button text/width changes */
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* Qty reduce/check buttons - square 32x32 for consistency everywhere */
    .pos-qty-reduce-btn {
        flex: 0 0 auto;
        width: 32px !important;
        height: 32px !important;
        min-width: 32px !important;
        min-height: 32px !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 0.75rem !important;
        border-radius: 0.25rem !important;
        line-height: 1 !important;
    }

    .pos-qty-control-strip .pos-qty-reduce-btn {
        /* Ensure flex alignment in strip context */
        flex: 0 0 auto;
    }

    /* Task 2.1-2.3: Spinner button styling with semantic colors and fill-on-hover */
    .pos-qty-control-strip .btn.btn-sm.btn-outline-danger.js-qty-decrease,
    .pos-qty-control-strip .btn.btn-sm.btn-outline-primary.js-qty-increase {
        flex: 0 0 auto;
        width: 32px !important;
        height: 32px !important;
        padding: 0 !important;
        min-width: 32px !important;
        min-height: 32px !important;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.875rem;
        border-radius: 0.25rem !important;
        transition: all 0.15s ease-in-out;
    }

    .btn.btn-sm.btn-outline-danger.js-qty-decrease {
        color: #dc3545 !important;
        border-color: #dc3545 !important;
    }

    .btn.btn-sm.btn-outline-danger.js-qty-decrease:hover,
    .btn.btn-sm.btn-outline-danger.js-qty-decrease:focus {
        background-color: #dc3545 !important;
        color: #fff !important;
        border-color: #dc3545 !important;
    }

    .btn.btn-sm.btn-outline-primary.js-qty-increase {
        color: #007bff !important;
        border-color: #007bff !important;
    }

    .btn.btn-sm.btn-outline-primary.js-qty-increase:hover,
    .btn.btn-sm.btn-outline-primary.js-qty-increase:focus {
        background-color: #007bff !important;
        color: #fff !important;
        border-color: #007bff !important;
    }

    /* Bundle Selection Styles */
    .pos-bundle-card {
        border: 2px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 1rem;
        height: 100%;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        background: #fff;
    }

    .pos-bundle-card:hover {
        border-color: #3b82f6;
        background-color: #f8fafc;
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .pos-bundle-card .bundle-name {
        font-weight: 700;
        font-size: 1rem;
        color: #1e293b;
        margin-bottom: 0.5rem;
    }

    .pos-bundle-card .bundle-price {
        font-weight: 700;
        font-size: 1.15rem;
        color: #3b82f6;
        margin-bottom: 0.75rem;
    }

    .pos-bundle-card .bundle-items {
        font-size: 0.8rem;
        color: #64748b;
        flex-grow: 1;
    }

    .pos-bundle-card .bundle-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.25rem;
        gap: 0.5rem;
    }

    .pos-bundle-card .bundle-item-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .pos-bundle-card .bundle-item-qty {
        font-weight: 600;
        flex-shrink: 0;
    }

    .pos-customer-shell {
        height: 100%;
        display: flex;
        flex-direction: column;
        min-height: 0;
        position: relative;
    }

    .pos-customer-search-anchor {
        position: relative;
    }

    #pos-customer-search-results {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        max-height: clamp(150px, 24dvh, 230px);
        overflow-y: auto;
        z-index: 1250;
        background: #fff;
        border: 1px solid #dbe1ea;
        border-radius: 0.45rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
    }

    #pos-customer-search-results:empty {
        display: none;
    }

    .pos-payment-shell {
        min-height: 0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 0.45rem;
        height: 100%;
    }

    .pos-total-value {
        font-size: clamp(1.25rem, 2.4vw, 1.82rem);
        font-weight: 700;
        line-height: 1.1;
        margin-bottom: 0;
    }

    .pos-payment-shell .btn {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
        font-size: 0.92rem;
    }

    #pos-customer-search-results .list-group-item {
        padding: 0.45rem 0.55rem;
        line-height: 1.35;
        font-size: 0.79rem;
        border-left: 0;
        border-right: 0;
    }

    #pos-customer-search-results .list-group-item:first-child {
        border-top: 0;
    }

    #pos-customer-search-results .list-group-item:last-child {
        border-bottom: 0;
    }

    .modal-dialog {
        margin: 0.75rem auto;
    }

    @media (max-height: 780px) {
        .pos-shell {
            padding: 0.4rem;
        }

        .pos-viewport {
            gap: 0.4rem;
            grid-template-rows: clamp(58px, 8dvh, 76px) clamp(92px, 14dvh, 126px) minmax(0, 1fr) clamp(118px, 21dvh, 164px);
        }

        .pos-card .card-header {
            padding: 0.28rem 0.58rem;
        }

        .pos-card .card-body {
            padding: 0.34rem 0.58rem;
        }

        .pos-section-title {
            font-size: 0.78rem;
        }

        .pos-info-title,
        .pos-nav-label {
            font-size: 0.74rem;
        }

        .pos-info-item {
            font-size: 0.69rem;
        }

        .pos-nav-strip .btn {
            font-size: 0.69rem;
            padding-top: 0.22rem;
            padding-bottom: 0.22rem;
        }

        .pos-cart-table td,
        .pos-cart-table th {
            font-size: 0.72rem;
            padding: 0.24rem 0.32rem;
        }

        .pos-total-value {
            font-size: clamp(1.1rem, 2.1vw, 1.55rem);
        }

        .pos-payment-shell .btn {
            font-size: 0.82rem;
            padding-top: 0.36rem;
            padding-bottom: 0.36rem;
        }
    }

    @media (max-width: 991.98px) {
        .pos-viewport {
            grid-template-columns: minmax(0, 6fr) minmax(0, 4fr);
            grid-template-rows: clamp(64px, 10dvh, 88px) clamp(96px, 15dvh, 132px) minmax(0, 1fr) clamp(128px, 22dvh, 176px);
        }
    }

    @media (max-width: 767.98px) and (orientation: landscape) {
        .pos-viewport {
            grid-template-columns: minmax(0, 58fr) minmax(0, 42fr);
            grid-template-rows: clamp(60px, 12dvh, 76px) clamp(92px, 18dvh, 124px) minmax(0, 1fr) clamp(118px, 24dvh, 160px);
            gap: 0.35rem;
        }

        .pos-shell {
            padding: 0.32rem;
        }

        .pos-info-strip {
            gap: 0.4rem;
        }

        .pos-info-metrics {
            gap: 0.5rem;
        }

        .pos-info-item,
        .pos-nav-label,
        .pos-info-title {
            font-size: 0.66rem;
        }

        .pos-nav-strip {
            grid-template-columns: auto auto;
        }

        .pos-nav-note {
            display: none;
        }

        .pos-nav-strip .btn {
            font-size: 0.66rem;
            padding-top: 0.18rem;
            padding-bottom: 0.18rem;
        }

        .pos-scan-action-rail {
            gap: 0.25rem;
        }

        .pos-scan-action-primary,
        .pos-scan-action-secondary {
            font-size: 0.75rem;
            padding-top: 0.35rem;
            padding-bottom: 0.35rem;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
            min-width: 64px;
        }

        .pos-scan-action-camera {
            font-size: 0.75rem;
            min-width: 38px;
            padding: 0.35rem 0.4rem;
        }

        #pos-shell-scan-feedback {
            font-size: 0.68rem;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .pos-cart-table td,
        .pos-cart-table th {
            font-size: 0.68rem;
        }

        .pos-total-value {
            font-size: 1.05rem;
        }

        .pos-payment-shell .btn {
            font-size: 0.74rem;
            padding-top: 0.32rem;
            padding-bottom: 0.32rem;
        }

        #pos-customer-search-results {
            max-height: 42dvh;
        }
    }

    /* Phase 3: Search card grid layout */
    .pos-search-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 0.75rem;
        padding: 1rem;
    }

    .pos-search-card {
        display: block;
        width: 100%;
        text-align: left;
        padding: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        background: white;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.875rem;
    }

    .pos-search-card:hover {
        border-color: #007bff;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        background-color: #f8f9ff;
    }

    .pos-search-card:focus {
        outline: 2px solid #007bff;
        outline-offset: -1px;
    }

    .pos-search-card-disabled {
        opacity: 0.65;
        cursor: not-allowed !important;
        background-color: #f1f5f9 !important;
        border-color: #e2e8f0 !important;
        position: relative;
        filter: grayscale(0.5);
    }

    .pos-search-card-disabled:hover,
    .pos-search-card-disabled:focus {
        border-color: #e2e8f0 !important;
        box-shadow: none !important;
        background-color: #f1f5f9 !important;
        outline: none !important;
    }

    .pos-search-card-oos-badge {
        position: absolute;
        top: 40%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-12deg);
        background-color: rgba(239, 68, 68, 0.9);
        color: white;
        padding: 0.2rem 0.6rem;
        border-radius: 0.25rem;
        font-weight: 800;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        white-space: nowrap;
        pointer-events: none;
        z-index: 10;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    @media (max-width: 640px) and (orientation: portrait) {
        .pos-shell {
            display: none;
        }

        .pos-lock-screen {
            display: flex;
            height: 100dvh;
            width: 100%;
            padding: 1.1rem;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: #e2e8f0;
            text-align: center;
        }

        .pos-lock-card {
            width: 100%;
            max-width: 420px;
            border-radius: 0.8rem;
            border: 1px solid rgba(148, 163, 184, 0.3);
            background: rgba(15, 23, 42, 0.76);
            padding: 1.1rem;
        }

        .pos-lock-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 0.45rem;
        }

        .pos-lock-text {
            font-size: 0.88rem;
            margin-bottom: 0;
            color: #cbd5e1;
        }
    }
</style>
