    <div class="modal fade" id="pos-search-results-modal" tabindex="-1" role="dialog" aria-labelledby="pos-search-results-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pos-search-results-modal-label">Cari Produk</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body d-flex flex-column" style="max-height: 70vh; padding: 0;">
                    <!-- Phase 3: In-modal search input -->
                    <div class="p-3 border-bottom flex-shrink-0">
                        <div class="input-group">
                            <input id="pos-modal-search-input" type="text" class="form-control" 
                                   placeholder="Cari nama produk atau SKU..." 
                                   autocomplete="off">
                            <div class="input-group-append">
                                <button id="pos-modal-search-btn" class="btn btn-primary" type="button">Cari</button>
                            </div>
                        </div>
                    </div>
                    <!-- Phase 3: Card-grid results container -->
                    <div id="pos-search-modal-results" class="pos-search-card-grid flex-grow-1 overflow-auto"></div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                </div>
            </div>
        </div>
    </div>
