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
                    <div class="rounded-md border border-slate-200 bg-white shadow-sm overflow-hidden">
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
                                        {{-- Price --}}
                                        <div class="mb-1 md:mb-0">
                                            <div class="text-[11.5px] md:text-[10.5px] uppercase tracking-wide text-slate-500">Harga</div>
                                            @php
                                                $priceTiers = [
                                                    'Umum'   => $product->display_sale_price ?? null,
                                                    'Tier 1' => $product->display_tier_1_price ?? null,
                                                    'Tier 2' => $product->display_tier_2_price ?? null,
                                                ];
                                            @endphp
                                            <dl class="space-y-0.5">
                                                @foreach($priceTiers as $label => $rawPrice)
                                                    @php($formatted = $formatCurrency($rawPrice))
                                                    @if($formatted)
                                                        <div class="flex items-center justify-between text-[13.5px] md:text-[12.5px] text-slate-800">
                                                            <dt class="font-medium text-slate-600">{{ $label }}</dt>
                                                            <dd class="font-semibold">{{ $formatted }}</dd>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </dl>
                                        </div>

                                        {{-- Codes (product code + barcode) --}}
                                        <div class="mb-1 md:mb-0">
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
