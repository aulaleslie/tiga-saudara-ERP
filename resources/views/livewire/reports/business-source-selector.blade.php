<div class="form-group mb-0">
    @if(!isset($hideLabel) || !$hideLabel)
    <label class="form-label">{{ $label ?? 'Perusahaan' }}</label>
    @endif
    <div wire:ignore class="business-source-setting-select">
        <select id="{{ $selectId ?? 'settingIds' }}" multiple class="form-control" style="width: 100%;">
            @forelse($availableSettings as $setting)
                <option value="{{ $setting['id'] }}" 
                    @if(in_array($setting['id'], $selectedValues ?? [])) selected @endif
                >{{ $setting['company_name'] }}</option>
            @empty
                <option disabled>Tidak ada perusahaan</option>
            @endforelse
        </select>
    </div>
</div>

@once
<style>
    .business-source-setting-select .select2-selection--multiple {
        min-height: 38px;
        height: auto !important;
        padding: 2px 6px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
    }

    .business-source-setting-select .select2-selection__rendered {
        display: flex !important;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        padding: 0 !important;
        margin: 0 !important;
        width: auto !important;
    }

    .business-source-setting-select .select2-search--inline {
        flex: 1 1 160px;
        min-width: 160px;
        line-height: 28px;
    }

    .business-source-setting-select .select2-search__field {
        width: 100% !important;
        height: 28px !important;
        min-height: 28px !important;
        max-height: 28px !important;
        line-height: 28px !important;
        resize: none !important;
        overflow: hidden !important;
        margin: 0 !important;
        padding: 0 !important;
        text-align: left;
    }
</style>
@endonce

<script>
    document.addEventListener('livewire:initialized', () => {
        const selectId = @js($selectId ?? 'settingIds');
        const propertyName = @js($livewireProperty ?? 'selectedSettingIds');
        const $select = $('#' + selectId);

        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
            $select.off('change');
        }

        let synchronizing = false;

        $select.select2({
            placeholder: @js($placeholder ?? 'Pilih perusahaan...'),
            allowClear: true,
            theme: 'coreui',
            width: '100%'
        }).on('change', function(e) {
            if (!synchronizing) {
                const values = $(this).val() || [];
                @this.set(propertyName, values);
            }
        });

        Livewire.on('sync-select2-' + selectId, (data) => {
            // Livewire 3 dispatch payload handling
            let payload = Array.isArray(data) ? data[0] : data;
            let values = payload.values || payload || [];
            
            synchronizing = true;
            try {
                $select.val(values).trigger('change.select2');
            } finally {
                synchronizing = false;
            }
        });
    });
</script>
