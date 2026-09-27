<div class="form-group business-selector-container">
    <label for="{{ $selectId }}">Perusahaan @if($isRequired)<span class="text-danger">*</span>@endif</label>
    <div wire:ignore class="business-selector-wrapper">
        <select
            id="{{ $selectId }}"
            class="form-control"
            style="width: 100%;"
            @if($isRequired) required @endif
        >
            <option value="">Pilih perusahaan...</option>
            @forelse($availableSettings as $setting)
                <option value="{{ $setting['id'] }}" @selected($setting['id'] == $selectedSettingId)>
                    {{ $setting['company_name'] }}
                </option>
            @empty
                <option disabled>Tidak ada perusahaan</option>
            @endforelse
        </select>
    </div>
    @if($error)
        <div class="text-danger small mt-1">{{ $error }}</div>
    @endif

    @once
    <style>
        .business-selector-wrapper .select2-selection {
            height: 38px;
            padding: 2px 6px;
        }

        .business-selector-wrapper .select2-selection__rendered {
            padding: 0 6px;
            line-height: 34px;
        }

        .business-selector-wrapper .select2-search__field {
            height: 34px;
            line-height: 34px;
        }
    </style>
    @endonce

    <script>
        document.addEventListener('livewire:initialized', () => {
            const $select = $('#{{ $selectId }}');

            if ($select.hasClass('select2-hidden-accessible')) {
                $select.select2('destroy');
            }

            $select.select2({
                placeholder: 'Pilih perusahaan...',
                allowClear: @json(!$isRequired),
                theme: 'coreui',
                width: '100%'
            }).on('change', function() {
                const value = $(this).val();
                @this.set('selectedSettingId', value ? parseInt(value) : null);
                @this.dispatch('business-selector-changed', { settingId: value ? parseInt(value) : null });
            });

            // Sync when component updates
            @this.on('updateSelectedSetting', (settingId) => {
                $select.val(settingId).trigger('change.select2');
            });
        });
    </script>
</div>
