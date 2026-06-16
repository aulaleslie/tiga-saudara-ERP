@php use Modules\Product\Entities\Product; @endphp
<button class="c-header-toggler c-class-toggler d-block d-sm-none mfe-auto" type="button" data-target="#sidebar"
        data-class="c-sidebar-show">
    <i class="bi bi-list" style="font-size: 2rem;"></i>
</button>

<ul class="c-header-nav ml-auto">
</ul>
<ul class="c-header-nav ml-auto mr-4">
    @php
        $currentSetting = settings();
        $posEnabledForCurrentSetting = (bool) ($currentSetting->pos_enabled ?? false);
        $canQuickOpenPosSell = $posEnabledForCurrentSetting && auth()->user()->can('pos.access') && auth()->user()->can('pos.sell');
        $canQuickOpenPosSession = $posEnabledForCurrentSetting && auth()->user()->can('pos.access') && auth()->user()->can('pos.sessions.open');
        $canQuickOpenPosTerminals = $posEnabledForCurrentSetting && auth()->user()->can('pos.terminals.access');

        $posQuickLink = null;
        if ($canQuickOpenPosSell) {
            $posQuickLink = route('pos.sell');
        } elseif ($canQuickOpenPosSession) {
            $posQuickLink = route('pos.sessions.create');
        } elseif ($canQuickOpenPosTerminals) {
            $posQuickLink = route('pos.terminals.index');
        }
    @endphp

    @if($posQuickLink)
        <li class="c-header-nav-item mr-2">
            <a class="btn btn-success btn-pill" href="{{ $posQuickLink }}">
                <i class="bi bi-upc-scan mr-1"></i> Buka POS
            </a>
        </li>
    @endif

    @can('pricePoints.access')
        <li class="c-header-nav-item mr-2">
            <a class="btn btn-primary btn-pill" href="{{ route('price-points.index') }}" target="_blank">
                <i class="bi bi-tags mr-1"></i> Terminal Harga
            </a>
        </li>
    @endcan

    @if(session('user_settings') && session('user_settings')->isNotEmpty())
        @php
            $userSettings = session('user_settings');
            $currentSetting = $userSettings
            ? ($userSettings->firstWhere('id', session('setting_id')) ?? $userSettings->first())
            : null;
        @endphp
        @if(count($userSettings) > 1)
            <li class="c-header-nav-item dropdown">
                <a class="c-header-nav-link" href="#" role="button" aria-haspopup="true" aria-expanded="false">
                    <div class="d-flex flex-column">
                        <span class="font-weight-bold">{{ $currentSetting->company_name }}</span>
                    </div>
                </a>
                <div class="dropdown-menu dropdown-menu-right pt-0">
                    <div class="dropdown-header bg-light py-2"><strong>Bisnis</strong></div>
                    @foreach($userSettings as $setting)
                        <a class="dropdown-item" href="#"
                           onclick="event.preventDefault(); document.getElementById('select-business-form-{{$setting->id}}').submit();">
                           {{$setting->company_name}}
                        </a>
                        <form id="select-business-form-{{$setting->id}}" action="{{ route('update.active.business') }}"
                              method="POST" class="d-none">
                            @csrf
                            <input type="hidden" name="setting_id" value="{{ $setting->id }}">
                        </form>
                    @endforeach
                </div>
            </li>
        @else
            <li class="c-header-nav-item d-md-down-none mr-2">
                <a class="c-header-nav-link font-weight-bold">
                    {{ $userSettings->first()->company_name }}
                </a>
            </li>
        @endif
    @endif

    @can('notifications.access')
        @php
            $feedService = app(\App\Services\Notification\NotificationFeedService::class);
            $unreadCount = $feedService->getUnreadCount(auth()->id());
            $dropdownItems = $feedService->getHeaderDropdownItems(auth()->id(), 10);
        @endphp
        <li class="c-header-nav-item dropdown d-md-down-none mr-2">
            <a class="c-header-nav-link" data-toggle="dropdown" href="#" role="button" aria-haspopup="true"
               aria-expanded="false">
                <i class="bi bi-bell" style="font-size: 20px;"></i>
                @if($unreadCount > 0)
                    <span class="badge badge-pill badge-danger">{{ $unreadCount }}</span>
                @endif
            </a>
            <div class="dropdown-menu dropdown-menu-right dropdown-menu-lg pt-0">
                <div class="dropdown-header bg-light d-flex justify-content-between align-items-center">
                    <strong>{{ $unreadCount }} Belum Dibaca</strong>
                    <a href="{{ route('notifications.index') }}" class="text-muted small">Lihat Semua</a>
                </div>
                @forelse($dropdownItems as $notification)
                    <a class="dropdown-item {{ is_null($notification->read_at) ? 'font-weight-bold bg-light' : '' }}" href="{{ route('notifications.read', $notification->id) }}">
                        <div class="text-truncate" style="max-width: 250px;">
                            @if(is_null($notification->read_at))
                                <i class="bi bi-circle-fill text-primary mr-1" style="font-size: 8px;"></i>
                            @endif
                            {{ $notification->title }}
                        </div>
                        <div class="small text-muted text-truncate" style="max-width: 250px;">
                            {{ $notification->message }}
                        </div>
                    </a>
                @empty
                    <a class="dropdown-item" href="#">
                        <i class="bi bi-app-indicator mr-2 text-muted"></i> Tidak ada notifikasi.
                    </a>
                @endforelse
                @if($dropdownItems->isNotEmpty())
                    <div class="dropdown-divider"></div>
                    <div class="p-2 text-center">
                        <form action="{{ route('notifications.markAllRead') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-link btn-sm text-muted p-0">Tandai semua dibaca</button>
                        </form>
                    </div>
                @endif
            </div>
        </li>
    @endcan

    <li class="c-header-nav-item dropdown">
        <a class="c-header-nav-link" href="#" role="button" aria-haspopup="true" aria-expanded="false">
            <div class="c-avatar mr-2">
                <img class="c-avatar rounded-circle" src="{{ auth()->user()->getFirstMediaUrl('avatars') }}"
                     alt="Profile Image">
            </div>
            <div class="d-flex flex-column">
                <span class="font-weight-bold">{{ auth()->user()->name }}</span>
                <span class="font-italic">Aktif <i class="bi bi-circle-fill text-success" style="font-size: 11px;"></i></span>
            </div>
        </a>
        <div class="dropdown-menu dropdown-menu-right pt-0">
            <div class="dropdown-header bg-light py-2"><strong>Akun</strong></div>
            <a class="dropdown-item" href="{{ route('profile.edit') }}">
                <i class="mfe-2 bi bi-person" style="font-size: 1.2rem;"></i> Profil
            </a>
            <a class="dropdown-item" href="#"
               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                <i class="mfe-2 bi bi-box-arrow-left" style="font-size: 1.2rem;"></i> Keluar
            </a>
            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                @csrf
            </form>
        </div>
    </li>
</ul>
