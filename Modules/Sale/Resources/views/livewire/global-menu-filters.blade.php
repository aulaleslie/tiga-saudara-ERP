<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="bi bi-funnel me-2"></i>
            Advanced Filters
        </h6>
        <button
            wire:click="toggleFilters"
            class="btn btn-sm btn-outline-secondary"
            type="button"
        >
            <i class="bi bi-chevron-{{ $isOpen ? 'up' : 'down' }}"></i>
        </button>
    </div>

    <div class="collapse {{ $isOpen ? 'show' : '' }}">
        <div class="card-body">
            <!-- Quick Filter Buttons -->
            <div class="mb-3">
                <label class="form-label">Quick Filters</label>
                <div class="d-flex flex-wrap gap-2">
                    <div class="btn-group btn-group-sm" role="group">
                        <button
                            wire:click="quickFilter('status', 'APPROVED')"
                            class="btn btn-outline-primary"
                            type="button"
                        >
                            Approved
                        </button>
                        <button
                            wire:click="quickFilter('status', 'DISPATCHED')"
                            class="btn btn-outline-success"
                            type="button"
                        >
                            Dispatched
                        </button>
                        <button
                            wire:click="quickFilter('status', 'RETURNED')"
                            class="btn btn-outline-danger"
                            type="button"
                        >
                            Returned
                        </button>
                    </div>

                    <div class="btn-group btn-group-sm" role="group">
                        <button
                            wire:click="presetDateRange('today')"
                            class="btn btn-outline-info"
                            type="button"
                        >
                            Today
                        </button>
                        <button
                            wire:click="presetDateRange('this_week')"
                            class="btn btn-outline-info"
                            type="button"
                        >
                            This Week
                        </button>
                        <button
                            wire:click="presetDateRange('this_month')"
                            class="btn btn-outline-info"
                            type="button"
                        >
                            This Month
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filter Form -->
            <form wire:submit="applyFilters">
                <div class="row">
                    <!-- Basic Filters -->
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Serial Number</label>
                            <input
                                wire:model="filters.serial_number"
                                type="text"
                                class="form-control"
                                placeholder="Enter serial number..."
                            >
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Sale Reference</label>
                            <input
                                wire:model="filters.sale_reference"
                                type="text"
                                class="form-control"
                                placeholder="Enter sale reference..."
                            >
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Customer</label>
                            <select wire:model="filters.customer_id" class="form-control">
                                <option value="">All Customers</option>
                                @foreach($customers ?? [] as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select wire:model="filters.status" class="form-control">
                                <option value="">All Statuses</option>
                                @foreach($statuses as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Date Range -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Date From</label>
                            <input
                                wire:model="filters.date_from"
                                type="date"
                                class="form-control"
                            >
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Date To</label>
                            <input
                                wire:model="filters.date_to"
                                type="date"
                                class="form-control"
                            >
                        </div>
                    </div>
                </div>

                <!-- Advanced Filters -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <select wire:model="filters.location_id" class="form-control">
                                <option value="">All Locations</option>
                                @foreach($locations ?? [] as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Product</label>
                            <select wire:model="filters.product_id" class="form-control">
                                <option value="">All Products</option>
                                @foreach($products ?? [] as $product)
                                    <option value="{{ $product->id }}">
                                        {{ $product->product_name }} ({{ $product->product_code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Product Category</label>
                            <select wire:model="filters.product_category_id" class="form-control">
                                <option value="">All Categories</option>
                                @foreach($categories ?? [] as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Seller</label>
                            <select wire:model="filters.seller_id" class="form-control">
                                <option value="">All Sellers</option>
                                @foreach($sellers ?? [] as $seller)
                                    <option value="{{ $seller->id }}">{{ $seller->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Serial Number Status -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Serial Number Status</label>
                            <select wire:model="filters.serial_number_status" class="form-control">
                                <option value="">All Serial Statuses</option>
                                <option value="allocated">Allocated</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="returned">Returned</option>
                                <option value="available">Available</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted">
                            Active filters: {{ count(array_filter($filters)) }}
                        </span>
                    </div>

                    <div>
                        <button
                            wire:click="clearFilters"
                            class="btn btn-outline-secondary"
                            type="button"
                        >
                            <i class="bi bi-x-circle"></i> Clear All
                        </button>
                        <button
                            class="btn btn-primary ml-2"
                            type="submit"
                        >
                            <i class="bi bi-search"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('livewire:loaded', () => {
    // Auto-submit form when Enter is pressed in filter inputs
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.matches('input[wire\\:model*="filters"]')) {
            e.preventDefault();
            // Find the submit button and click it
            const submitBtn = e.target.closest('form').querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.click();
            }
        }
    });
});
</script>
@endpush