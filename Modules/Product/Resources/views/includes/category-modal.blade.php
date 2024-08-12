@php
    use Modules\Product\Entities\Category;

    $category_max_id = Category::max('id') + 1;
    $category_code = "CA_" . str_pad($category_max_id, 2, '0', STR_PAD_LEFT);
    $parent_categories = Category::whereNull('parent_id')->get(); // Fetch categories without parent_id
@endphp

<div class="modal fade" id="categoryCreateModal" tabindex="-1" role="dialog" aria-labelledby="categoryCreateModal"
     aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryCreateModalLabel">Buat Kategori</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="{{ route('product-categories.store') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="form-group">
                        <label for="category_code">Kode Kategori <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" name="category_code" required
                               value="{{ $category_code }}">
                    </div>
                    <div class="form-group">
                        <label for="category_name">Nama Kategori <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" name="category_name" required>
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="addAsSubCategory"
                                   name="add_as_subcategory">
                            <label class="form-check-label" for="addAsSubCategory">
                                Tambahkan sebagai sub-kategori
                            </label>
                        </div>
                    </div>
                    <div class="form-group" id="parentCategorySelect" style="display: none;">
                        <label for="parent_id">Pilih Kategori Induk</label>
                        <select class="form-control" name="parent_id" id="parent_id">
                            <option value="">-- Pilih Kategori Induk --</option>
                            @foreach($parent_categories as $parent_category)
                                <option
                                    value="{{ $parent_category->id }}">{{ $parent_category->category_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Tambahkan <i class="bi bi-check"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('addAsSubCategory').addEventListener('change', function () {
        var parentCategorySelect = document.getElementById('parentCategorySelect');
        if (this.checked) {
            parentCategorySelect.style.display = 'block';
        } else {
            parentCategorySelect.style.display = 'none';
        }
    });
</script>
