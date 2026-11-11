<div class="business-checkbox-selector position-relative"
    x-data="businessCheckboxSelector({
        property: @js($livewireProperty ?? 'selectedSettingIds'),
        placeholder: @js($placeholder ?? 'Pilih bisnis...'),
        options: @js(collect($availableSettings ?? [])->map(fn($s) => ['id' => (int)(is_array($s) ? $s['id'] : $s->id), 'name' => (string)(is_array($s) ? ($s['company_name'] ?? $s['name']) : ($s->company_name ?? $s->name))])->values())
    })"
    x-init="init()"
    @click.outside="close()"
    @keydown.escape.prevent="close(true)">
    @if(!isset($hideLabel) || !$hideLabel)
        <label class="form-label small mb-1">{{ $label ?? 'Bisnis' }}</label>
    @endif

    <button type="button"
        id="{{ $selectId ?? 'businessSelectorTrigger' }}"
        class="btn form-control text-start d-flex justify-content-between align-items-center business-checkbox-trigger"
        aria-haspopup="listbox"
        :aria-expanded="open"
        @click="toggle()"
        x-ref="trigger"
        :title="buttonTitle">
        <span class="text-truncate flex-grow-1 me-2"
            :class="{ 'text-muted': pending.length === 0 }"
            x-text="buttonText"></span>
        <i class="bi bi-chevron-down small text-muted flex-shrink-0" :class="{ 'rotate-180': open }"></i>
    </button>

    <div x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 transform scale-95"
        x-transition:enter-end="opacity-100 transform scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 transform scale-100"
        x-transition:leave-end="opacity-0 transform scale-95"
        class="business-checkbox-menu"
        style="display: none;"
        role="listbox">
        
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="selectAll()">Pilih semua</button>
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-muted" @click="clearAll()">Kosongkan</button>
        </div>

        <template x-if="options.length > 6">
            <div class="p-2 border-bottom">
                <input type="text"
                    x-ref="searchInput"
                    x-model="search"
                    class="form-control form-control-sm"
                    placeholder="Cari bisnis..."
                    @keydown.escape.prevent="close(true)">
            </div>
        </template>

        <div class="business-checkbox-list" style="max-height: 220px; overflow-y: auto;">
            <template x-for="opt in filteredOptions" :key="opt.id">
                <label class="dropdown-item d-flex align-items-center px-3 py-1 mb-0 user-select-none" style="cursor: pointer; font-size: 0.875rem;">
                    <input type="checkbox"
                        :value="opt.id"
                        :checked="isChecked(opt.id)"
                        @change="toggleItem(opt.id)"
                        class="form-check-input me-2 mt-0 flex-shrink-0"
                        style="cursor: pointer;">
                    <span x-text="opt.name" class="text-truncate"></span>
                </label>
            </template>
            <template x-if="filteredOptions.length === 0">
                <div class="px-3 py-2 text-muted small text-center">
                    Tidak ada bisnis
                </div>
            </template>
        </div>
    </div>
</div>

@once
<style>
    .report-business-slot {
        flex: 0 0 260px;
        max-width: 260px;
        width: 260px;
    }
    @media (max-width: 767.98px) {
        .report-business-slot {
            flex: 0 0 100%;
            max-width: 100%;
            width: 100%;
        }
    }
    .business-checkbox-selector .business-checkbox-trigger {
        height: 38px !important;
        min-height: 38px !important;
        max-height: 38px !important;
        line-height: 1.5;
        background-color: #fff;
        border: 1px solid #ced4da;
        color: #495057;
        text-align: left;
        white-space: nowrap;
        overflow: hidden;
    }
    .business-checkbox-selector .business-checkbox-trigger:hover,
    .business-checkbox-selector .business-checkbox-trigger:focus {
        background-color: #fff;
        border-color: #80bdff;
        color: #495057;
        box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
    }
    .business-checkbox-selector .business-checkbox-menu {
        position: absolute;
        top: 100%;
        left: 0;
        z-index: 1050;
        width: 100%;
        min-width: 260px;
        max-height: 280px;
        background: #fff;
        border: 1px solid #ced4da;
        border-radius: 0.25rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        margin-top: 2px;
    }
    .business-checkbox-selector .dropdown-item:hover {
        background-color: #f8f9fa;
    }
    .business-checkbox-selector .rotate-180 {
        transform: rotate(180deg);
        transition: transform 0.15s ease-in-out;
    }
</style>

<script>
    window.businessCheckboxSelector = function (config) {
        return {
            open: false,
            search: '',
            property: config.property,
            placeholder: config.placeholder,
            options: config.options || [],
            pending: [],

            init() {
                const initial = this.$wire.get(this.property);
                this.pending = (Array.isArray(initial) ? initial : []).map(v => parseInt(v, 10)).filter(v => v > 0);

                this.$wire.$watch(this.property, (newVal) => {
                    const next = (Array.isArray(newVal) ? newVal : []).map(v => parseInt(v, 10)).filter(v => v > 0);
                    if (!this.areArraysEqual(this.pending, next)) {
                        this.pending = next;
                    }
                });
            },

            areArraysEqual(a, b) {
                if (a.length !== b.length) return false;
                const sA = [...a].sort((x, y) => x - y);
                const sB = [...b].sort((x, y) => x - y);
                return sA.every((v, i) => v === sB[i]);
            },

            get buttonText() {
                if (this.pending.length === 0) {
                    return this.placeholder;
                }
                if (this.options.length > 0 && this.pending.length === this.options.length) {
                    return 'Semua bisnis';
                }
                if (this.pending.length === 1) {
                    const found = this.options.find(o => o.id === this.pending[0]);
                    return found ? found.name : '1 bisnis dipilih';
                }
                if (this.pending.length === 2) {
                    const names = this.pending
                        .map(id => this.options.find(o => o.id === id)?.name)
                        .filter(Boolean);
                    return names.join(', ');
                }
                return this.pending.length + ' bisnis dipilih';
            },

            get buttonTitle() {
                if (this.pending.length === 0) {
                    return this.placeholder;
                }
                const names = this.pending
                    .map(id => this.options.find(o => o.id === id)?.name)
                    .filter(Boolean);
                return names.join(', ');
            },

            get filteredOptions() {
                if (!this.search || this.options.length <= 6) {
                    return this.options;
                }
                const q = this.search.toLowerCase().trim();
                return this.options.filter(o => o.name.toLowerCase().includes(q));
            },

            selectAll() {
                this.pending = this.options.map(o => o.id);
            },

            clearAll() {
                this.pending = [];
            },

            toggleItem(id) {
                const intId = parseInt(id, 10);
                const idx = this.pending.indexOf(intId);
                if (idx > -1) {
                    this.pending.splice(idx, 1);
                } else {
                    this.pending.push(intId);
                }
            },

            isChecked(id) {
                return this.pending.includes(parseInt(id, 10));
            },

            toggle() {
                if (this.open) {
                    this.close();
                } else {
                    this.open = true;
                    this.search = '';
                    this.$nextTick(() => {
                        if (this.options.length > 6 && this.$refs.searchInput) {
                            this.$refs.searchInput.focus();
                        }
                    });
                }
            },

            close(returnFocus = false) {
                if (!this.open) return;
                this.open = false;

                const serverVal = (Array.isArray(this.$wire.get(this.property)) ? this.$wire.get(this.property) : [])
                    .map(v => parseInt(v, 10))
                    .filter(v => v > 0);

                if (!this.areArraysEqual(this.pending, serverVal)) {
                    this.$wire.set(this.property, this.pending);
                }

                if (returnFocus && this.$refs.trigger) {
                    this.$refs.trigger.focus();
                }
            }
        };
    };
</script>
@endonce
