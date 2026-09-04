<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
    @php
        $currency = optional($setting->currency);
        $decimalSeparator = $currency->decimal_separator ?? ',';
        $thousandSeparator = $currency->thousand_separator ?? '.';
        $currencySymbol = $currency->symbol ?? 'Rp';
        $currencyPosition = $setting->default_currency_position ?? 'prefix';
        $formatCurrency = static function ($value) use ($decimalSeparator, $thousandSeparator, $currencySymbol, $currencyPosition) {
            if ($value === null) {
                return null;
            }

            $numeric = number_format((float) $value, 0, $decimalSeparator, $thousandSeparator);

            return $currencyPosition === 'suffix'
                ? $numeric . $currencySymbol
                : $currencySymbol . $numeric;
        };
    @endphp

    {{-- Logo --}}
    <div class="mb-4 flex justify-center">
        <img class="w-40 sm:w-44" src="{{ asset('images/logo-dark.png') }}" alt="Logo">
    </div>

    {{-- Sticky header + search (compact desktop, readable mobile) --}}
    <div class="sticky top-0 z-30 mb-4 rounded-lg border border-slate-200 bg-white/90 backdrop-blur shadow-sm">
        <div class="p-3 md:p-2.5">
            <div class="flex flex-wrap items-center gap-2">
                <div class="mr-auto text-left">
                    <div class="text-sm md:text-[13px] font-semibold text-slate-800">Terminal Harga</div>
                    <div class="text-[12.5px] md:text-[12px] text-slate-500">
                        Outlet: <strong class="text-slate-700">{{ $setting->company_name ?? ('#'.$setting->id) }}</strong>
                    </div>
                </div>

                <div class="w-full"></div>

                {{-- Customer selection control --}}
                <div class="w-full">
                    @if($selectedCustomerId)
                        <div class="flex items-center gap-2 p-2 bg-blue-50 rounded-md border border-blue-200 mb-2">
                            <div class="flex-1">
                                <div class="text-sm font-medium text-slate-800">
                                    {{ $selectedCustomerLabel }}
                                </div>
                                @if($selectedCustomerTier)
                                    <div class="text-[11px] text-slate-600">
                                        @if($selectedCustomerTier === 'WHOLESALER')
                                            Tier: <strong>Grosir</strong>
                                        @elseif($selectedCustomerTier === 'RESELLER')
                                            Tier: <strong>Reseller</strong>
                                        @else
                                            Tier: {{ $selectedCustomerTier }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <button
                                wire:click="clearCustomer"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded bg-white border border-slate-300 text-slate-600 hover:bg-slate-50 text-[13px]"
                                type="button"
                                aria-label="Hapus pelanggan"
                            >
                                <i class="bi bi-x-lg text-[12px]"></i>
                                <span class="hidden sm:inline">Hapus</span>
                            </button>
                        </div>
                    @else
                        <div class="relative mb-2">
                            <div class="relative">
                                <i class="bi bi-search pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <input
                                    type="text"
                                    class="w-full rounded-md border border-slate-300 bg-white pl-8 pr-3 py-2 text-[13px] placeholder-slate-400 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
                                    placeholder="Cari pelanggan (opsional)..."
                                    wire:model.live="customerSearchText"
                                    autocomplete="off"
                                >
                            </div>

                            {{-- Customer dropdown --}}
                            @if($showCustomerDropdown && count($customerSearchResults) > 0)
                                <div class="absolute top-full left-0 right-0 mt-1 bg-white border border-slate-300 rounded-md shadow-lg z-40">
                                    @foreach($customerSearchResults as $customer)
                                        <button
                                            wire:click="selectCustomer({{ $customer['id'] }})"
                                            type="button"
                                            class="w-full text-left px-3 py-2 text-[13px] text-slate-800 hover:bg-slate-100 border-b border-slate-100 last:border-b-0"
                                        >
                                            <div class="font-medium">{{ $customer['label'] }}</div>
                                            @if($customer['tier'])
                                                <div class="text-[11px] text-slate-600">
                                                    @if($customer['tier'] === 'WHOLESALER')
                                                        Grosir
                                                    @elseif($customer['tier'] === 'RESELLER')
                                                        Reseller
                                                    @else
                                                        {{ $customer['tier'] }}
                                                    @endif
                                                </div>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Scanner-friendly search --}}
                <form wire:submit.prevent="searchNow" class="flex w-full items-stretch gap-2">
                    <div class="relative flex-1 min-w-0">
                        <i class="bi bi-upc-scan pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input
                            id="pp-search"
                            type="text"
                            class="w-full rounded-md border border-slate-300 bg-white pl-8 pr-2 py-2 text-[15px] sm:text-[14px] md:text-[13px] placeholder-slate-400 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100"
                            placeholder="Scan/ketik nama • brand • kategori • barcode • serial"
                            wire:model.defer="q"
                            autocomplete="off"
                            autofocus
                        >
                    </div>

                    @if($q !== '')
                        <button
                            class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-[14px] md:text-[13px] text-slate-600 hover:bg-slate-50"
                            wire:click="$set('q','')"
                            type="button"
                            aria-label="Bersihkan"
                        >
                            <span class="hidden sm:inline">Bersihkan</span>
                            <i class="bi bi-x-lg sm:hidden text-[12px]"></i>
                        </button>
                    @endif

                    <button type="submit" class="hidden" aria-hidden="true"></button>
                </form>

                <div class="w-full text-[12.5px] md:text-[12px] text-slate-500 mt-1 hidden sm:block">
                    Gunakan scanner (akhiri dengan Enter). Setelah pencarian, kursor otomatis kembali ke kotak ini.
                </div>
            </div>
        </div>
    </div>

    {{-- Loading --}}
    <div wire:loading.flex class="justify-center my-6">
        <svg class="h-5 w-5 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
    </div>

    {{-- Results --}}
    <div wire:loading.remove>
        @if($products->count() === 0)
            <div class="rounded-lg border border-slate-200 bg-white p-5 text-center text-slate-600 text-sm">
                Tidak ada produk untuk kata kunci ini.
            </div>
        @else
            {{-- Mobile: vertical list; Desktop: tight grid --}}
            <div class="space-y-2 md:space-y-0 md:grid md:grid-cols-2 lg:grid-cols-3 md:gap-3 lg:gap-4">
                @foreach($products as $product)
                    @php
                        $isOos = ($product->stock_state ?? '') === 'out_of_stock';
                        $isService = ($product->stock_state ?? '') === 'service';
                    @endphp
                    <div class="relative rounded-md border shadow-sm overflow-hidden {{ $isOos ? 'opacity-65 grayscale cursor-not-allowed bg-slate-100 border-slate-200' : 'bg-white border-slate-200' }}">
                        @if($isOos)
                            <div class="absolute top-[40%] left-1/2 -translate-x-1/2 -translate-y-1/2 -rotate-12 bg-red-600/90 text-white px-2.5 py-0.5 rounded font-extrabold text-[11px] uppercase tracking-wider whitespace-nowrap pointer-events-none z-10 shadow border border-white/20">
                                Stok Kosong
                            </div>
                        @elseif($isService)
                            <div class="absolute top-[40%] left-1/2 -translate-x-1/2 -translate-y-1/2 -rotate-12 bg-sky-500/90 text-white px-2.5 py-0.5 rounded font-extrabold text-[11px] uppercase tracking-wider whitespace-nowrap pointer-events-none z-10 shadow border border-white/20">
                                Service
                            </div>
                        @endif
                        <div class="p-3 md:p-2.5">
                            {{-- Mobile: row (image ≈26% left). Desktop: column (image on top). --}}
                            <div class="flex items-start gap-3 md:block md:space-y-2">
                                {{-- IMAGE (smaller, keeps ratio) --}}
                                @php
                                    $img = method_exists($product, 'getFirstMediaUrl')
                                        ? $product->getFirstMediaUrl('images')
                                        : null;
                                @endphp
                                <div class="basis-[26%] max-w-[26%] shrink-0 md:max-w-full md:basis-auto md:mb-1">
                                    <img
                                        src="{{ $img ?: asset('images/fallback_product_image.png') }}"
                                        alt="product image"
                                        class="w-full h-auto object-contain rounded border border-slate-200 max-h-20 md:max-h-24 lg:max-h-28"
                                        loading="lazy"
                                    >
                                </div>

                                {{-- INFO --}}
                                <div class="flex-1 min-w-0 text-left">
                                    {{-- Title (larger on mobile, tighter on desktop) --}}
                                    <div class="mb-1 text-[15px] md:text-sm font-medium text-slate-800 leading-snug break-words">
                                        {{ $product->product_name }}
                                    </div>

                                    {{-- Desktop info grid (2 cols). On mobile it flows naturally. --}}
                                    <div class="md:grid md:grid-cols-2 md:gap-x-4 md:gap-y-1.5">
                                        {{-- Contextual Price --}}
                                        <div class="mb-1 md:mb-0">
                                            @php
                                                $displayPrice = $product->contextual_price['price'];
                                                $priceLabel = $product->contextual_price['label'];
                                            @endphp
                                            <div class="text-[11.5px] md:text-[10.5px] uppercase tracking-wide text-slate-500">
                                                Harga
                                                @if($selectedCustomerTier)
                                                    ({{ $priceLabel }})
                                                @endif
                                            </div>
                                            @php($formatted = $formatCurrency($displayPrice))
                                            @if($formatted)
                                                <div class="text-[13.5px] md:text-[12.5px] font-semibold text-slate-800">
                                                    {{ $formatted }}
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Stok (only if user has permission to view quantities) --}}
                                        @if(isset($product->formatted_available_qty))
                                            <div class="mb-1 md:mb-0">
                                                <div class="text-[11.5px] md:text-[10.5px] uppercase tracking-wide text-slate-500">Stok</div>
                                                @if($isService)
                                                    <div class="text-[13.5px] md:text-[12.5px] font-semibold text-slate-800">
                                                        -
                                                    </div>
                                                @elseif($isOos)
                                                    <div class="text-[13.5px] md:text-[12.5px] font-bold text-red-600">
                                                        {{ $product->formatted_available_qty }}
                                                    </div>
                                                @else
                                                    <div class="text-[13.5px] md:text-[12.5px] font-semibold text-slate-800">
                                                        {{ $product->formatted_available_qty }}
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                        {{-- Codes (product code + barcode) --}}
                                        <div class="mb-1 md:mb-0 md:col-span-2">
                                            <div class="text-[11.5px] md:text-[10.5px] uppercase tracking-wide text-slate-500">Kode / Barcode</div>
                                            <div class="text-[14px] md:text-[13px] text-slate-700 break-words">
                                                <span class="font-mono">{{ $product->product_code }}</span>
                                                @if($product->barcode)
                                                    • <span class="font-mono break-all">{{ $product->barcode }}</span>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Brand / Category (full width) --}}
                                        <div class="md:col-span-2">
                                            <div class="text-[14px] md:text-[13px] text-slate-600">
                                                @if(optional($product->brand)->name)
                                                    <span class="mr-3"><i class="bi bi-tags"></i> {{ $product->brand->name }}</span>
                                                @endif
                                                @if(optional($product->category)->category_name)
                                                    <span><i class="bi bi-folder2"></i> {{ $product->category->category_name }}</span>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- Conversions (full width) --}}
                                        @if($product->conversions && $product->conversions->count())
                                            <div class="md:col-span-2 mt-1.5">
                                                <div class="text-[11.5px] md:text-[10.5px] uppercase tracking-wide text-slate-500 mb-1">Konversi</div>
                                                <div class="flex flex-wrap gap-1.5">
                                                    @foreach($product->conversions as $uc)
                                                        <span class="inline-flex items-center rounded border border-slate-200 bg-white px-2 py-0.5 text-[13px] md:text-[12px] text-slate-700">
                                                            {{ $uc->unit->short_name ?? $uc->unit->name ?? 'Unit' }}
                                                            @if($uc->quantity) x{{ (int)$uc->quantity }} @endif
                                                            @php($conversionPrice = $uc->priceForSetting($setting->id))
                                                            @if($conversionPrice)
                                                                • {{ number_format((float) $conversionPrice->price, 0, ',', '.') }}
                                                            @endif
                                                            @if($uc->barcode) • <span class="font-mono break-all">{{ $uc->barcode }}</span> @endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Footer / pagination --}}
            <div class="mt-3 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div class="order-2 md:order-1 text-[12.5px] md:text-[12px] text-slate-600">
                    Menampilkan {{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ $products->total() }}
                </div>
                <div class="order-1 md:order-2 flex items-center justify-center gap-2">
                    <button
                        type="button"
                        wire:click="previousPage('pp')"
                        @disabled($products->onFirstPage())
                        class="px-3 py-2 border rounded disabled:opacity-50"
                    >
                        « Previous
                    </button>

                    <span class="text-sm">Page {{ $products->currentPage() }} / {{ $products->lastPage() }}</span>

                    <button
                        type="button"
                        wire:click="nextPage('pp')"
                        @disabled(!$products->hasMorePages())
                        class="px-3 py-2 border rounded disabled:opacity-50"
                    >
                        Next »
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>

<script>
    window.addEventListener('refocus-search', () => {
        const el = document.getElementById('pp-search');
        if (el) { el.focus(); el.select(); }
    });
</script>
