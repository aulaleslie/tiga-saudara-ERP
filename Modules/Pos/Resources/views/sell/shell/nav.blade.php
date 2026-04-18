                <div class="pos-area pos-area-nav">
                    <div class="card pos-card pos-thin-card">
                        <div class="card-body">
                            <div class="pos-nav-strip">
                                <div class="pos-nav-label">Navigation</div>
                                <a href="{{ route('home') }}" class="btn btn-outline-dark">Kembali</a>
                                <div class="dropdown">
                                    <button class="btn btn-secondary dropdown-toggle" type="button" id="pos-nav-menu-dropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        Menu
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="pos-nav-menu-dropdown"
                                         data-session-id="{{ $activeSession->id }}"
                                         data-terminal-code="{{ $activeSession->terminal->code ?? '-' }}"
                                         data-terminal-name="{{ $activeSession->terminal->name ?? '-' }}"
                                         data-cashier-name="{{ $activeSession->cashier->name ?? '-' }}"
                                         data-expected-cash="{{ $activeSession->expected_cash_total ?? 0 }}">
                                        <button type="button" id="pos-shortcut-reprint" class="dropdown-item" disabled>Reprint</button>

                                        @can('pos.reports.access')
                                            <a href="{{ route('pos.reports.index') }}" target="_blank" class="dropdown-item">Lap. Sales</a>
                                        @endcan
                                        @can('saleReturns.access')
                                            <a href="{{ route('sale-returns.index') }}" target="_blank" class="dropdown-item">Retur</a>
                                        @endcan

                                        @if(auth()->user()->canAny(['pos.sessions.view', 'pos.reconciliation.access', 'pos.terminals.access']))
                                            <div class="dropdown-divider"></div>
                                        @endif

                                        @can('pos.sessions.view')
                                            <a class="dropdown-item" href="{{ route('pos.sessions.index') }}" target="_blank">Sesi POS</a>
                                        @endcan
                                        @if($posTransactionsEnabled && auth()->user()->can('pos.transactions.view'))
                                            <a class="dropdown-item" href="{{ route('pos.transactions.index') }}" target="_blank">Transaksi POS</a>
                                        @endif
                                        @can('pos.reconciliation.access')
                                            <a class="dropdown-item" href="{{ route('pos.reconciliation.index') }}" target="_blank">Rekonsiliasi</a>
                                        @endcan
                                        @can('pos.terminals.access')
                                            <a class="dropdown-item" href="{{ route('pos.terminals.index') }}" target="_blank">Kelola Terminal</a>
                                        @endcan
                                        @can('pos.supervisor.approval')
                                            <a class="dropdown-item" href="{{ route('pos.supervisor.approval-requests.index') }}" target="_blank">Antrian Persetujuan</a>
                                        @endcan
                                        <div class="dropdown-divider"></div>
                                        @if(auth()->user()->canAny(['pos.sessions.close', 'pos.sessions.close-admin']))
                                            <button type="button" id="pos-close-session-btn" class="dropdown-item">Tutup Sesi</button>
                                        @endif
                                        <button type="button" id="pos-cash-pickup-btn" class="dropdown-item">Pengambilan Kas</button>
                                    </div>
                                </div>
                                <span id="pos-shell-posting-note" class="pos-nav-note">pos-shell-posting-note</span>
                            </div>
                        </div>
                    </div>
                </div>
