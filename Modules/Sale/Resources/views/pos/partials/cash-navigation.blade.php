<div class="card shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap align-items-center">
        <a href="{{ route('app.pos.cash-settlement') }}"
           class="btn btn-outline-primary mr-2 mb-2 {{ request()->routeIs('app.pos.cash-settlement') ? 'active' : '' }}">
            <i class="bi bi-wallet2 mr-1"></i> Penyetoran Kas
        </a>
        <a href="{{ route('app.pos.cash-pickup') }}"
           class="btn btn-outline-primary mr-2 mb-2 {{ request()->routeIs('app.pos.cash-pickup') ? 'active' : '' }}">
            <i class="bi bi-truck mr-1"></i> Penjemputan Kas
        </a>
        <a href="{{ route('app.pos.cash-reconciliation') }}"
           class="btn btn-outline-primary mr-2 mb-2 {{ request()->routeIs('app.pos.cash-reconciliation') ? 'active' : '' }}">
            <i class="bi bi-calculator mr-1"></i> Rekonsiliasi Kas
        </a>
        <a href="{{ route('app.pos.index') }}" class="btn btn-link mb-2 ml-auto">
            <i class="bi bi-arrow-left mr-1"></i> Kembali ke POS
        </a>
    </div>
</div>
