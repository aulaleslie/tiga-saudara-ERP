@php use Modules\Product\Entities\Product; @endphp
<button class="c-header-toggler c-class-toggler d-block d-sm-none mfe-auto" type="button" data-target="#sidebar"
        data-class="c-sidebar-show">
    <i class="bi bi-list" style="font-size: 2rem;"></i>
</button>

<ul class="c-header-nav ml-auto">
</ul>
<ul class="c-header-nav ml-auto mr-4">
    @can('create_pos_sales')
        <li class="c-header-nav-item mr-3">
            {{--        <a class="btn btn-primary btn-pill {{ request()->routeIs('app.pos.index') ? 'disabled' : '' }}" href="{{ route('app.pos.index') }}">--}}
            {{--            <i class="bi bi-cart mr-1"></i> POS System--}}
            {{--        </a>--}}
        </li>
    @endcan

    @if(session('user_settings'))
        @php
            $userSettings = session('user_settings');
            $currentSetting = $userSettings->firstWhere('id', session('setting_id'));
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

    @can('show_notifications')
        <li class="c-header-nav-item dropdown d-md-down-none mr-2">
            <a class="c-header-nav-link" data-toggle="dropdown" href="#" role="button" aria-haspopup="true"
               aria-expanded="false">
                <i class="bi bi-bell" style="font-size: 20px;"></i>
                <span class="badge badge-pill badge-danger">
            @php
                $low_quantity_products = Product::select('id', 'product_quantity', 'product_stock_alert', 'product_code')->whereColumn('product_quantity', '<=', 'product_stock_alert')->get();
                echo $low_quantity_products->count();
            @endphp
            </span>
            </a>
            <div class="dropdown-menu dropdown-menu-right dropdown-menu-lg pt-0">
                <div class="dropdown-header bg-light">
                    <strong>{{ $low_quantity_products->count() }} Notifications</strong>
                </div>
                @forelse($low_quantity_products as $product)
                    <a class="dropdown-item" href="{{ route('products.show', $product->id) }}">
                        <i class="bi bi-hash mr-1 text-primary"></i> Product: "{{ $product->product_code }}" is low in
                        quantity!
                    </a>
                @empty
                    <a class="dropdown-item" href="#">
                        <i class="bi bi-app-indicator mr-2 text-danger"></i> No notifications available.
                    </a>
                @endforelse
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
