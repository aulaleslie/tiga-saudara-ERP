<div class="form-group">
    <label>Perusahaan</label>
    <div wire:ignore class="business-source-setting-select">
        <select id="{{ $selectId ?? 'settingIds' }}" multiple class="form-control" style="width: 100%;">
            @forelse($availableSettings as $setting)
                <option value="{{ $setting['id'] }}">{{ $setting['company_name'] }}</option>
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
        const $select = $('#{{ $selectId ?? 'settingIds' }}');

        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }

        $select.select2({
            placeholder: 'Pilih perusahaan...',
            allowClear: true,
            theme: 'coreui',
            width: '100%'
        }).on('change', function() {
            const values = $(this).val() || [];
            @this.set('selectedSettingIds', values);
        });
    });
</script>
