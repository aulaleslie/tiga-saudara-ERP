@extends('layouts.app')

@section('title', 'Ubah Jurnal')

@section('third_party_stylesheets')
    <!-- Additional styles if needed -->
@endsection

@section('content')
    <div class="container">
        <h1>Ubah Jurnal</h1>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('journals.update', $journal->id) }}" method="POST" id="journalForm">
            @csrf
            @method('PUT')
            <div class="form-group">
                <label for="transaction_date">Tanggal Transaksi</label>
                <input type="date" name="transaction_date" id="transaction_date" class="form-control" value="{{ old('transaction_date', $journal->transaction_date) }}" required>
            </div>

            <div class="form-group">
                <label for="description">Deskripsi</label>
                <textarea name="description" id="description" class="form-control" rows="3">{{ old('description', $journal->description) }}</textarea>
            </div>

            <hr>
            <h3>Item Jurnal</h3>
            <table class="table table-bordered" id="itemsTable">
                <thead>
                <tr>
                    <th>Akun</th>
                    <th>Debit</th>
                    <th>Kredit</th>
                    <th>Aksi</th>
                </tr>
                </thead>
                <tbody>
                @php $rowIndex = 0; @endphp
                @foreach($journal->items as $item)
                    <tr>
                        <td>
                            <select name="items[{{ $rowIndex }}][chart_of_account_id]" id="items_{{ $rowIndex }}_chart_of_account_id" class="form-control" required>
                                <option value="">Pilih Akun</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}"
                                        {{ old("items.$rowIndex.chart_of_account_id", $item->chart_of_account_id) == $account->id ? 'selected' : '' }}>
                                        {{ $account->name }} ({{ $account->account_number }})
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            <input type="number" step="0.01" name="items[{{ $rowIndex }}][amount_debit]" id="items_{{ $rowIndex }}_amount_debit" class="form-control"
                                   value="{{ old("items.$rowIndex.amount_debit", $item->type === 'debit' ? $item->amount : '') }}">
                        </td>
                        <td>
                            <input type="number" step="0.01" name="items[{{ $rowIndex }}][amount_credit]" id="items_{{ $rowIndex }}_amount_credit" class="form-control"
                                   value="{{ old("items.$rowIndex.amount_credit", $item->type === 'credit' ? $item->amount : '') }}">
                        </td>
                        <td>
                            <button type="button" class="btn btn-danger btn-sm removeRow">Hapus</button>
                        </td>
                    </tr>
                    @php $rowIndex++; @endphp
                @endforeach
                </tbody>
            </table>
            <button type="button" class="btn btn-secondary" id="addRow">Tambah Item</button>
            <br><br>
            <button type="submit" class="btn btn-primary">Ubah Jurnal</button>
        </form>
    </div>
@endsection

@push('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var rowIndex = {{ $rowIndex }};
            var addRowButton = document.getElementById('addRow');
            var itemsTableBody = document.querySelector('#itemsTable tbody');

            // Generate account options HTML using PHP (all in one file)
            var accountsOptions = `{!! addslashes(
        collect($accounts)->map(function($account) {
            return '<option value="' . $account->id . '">' . $account->name . ' (' . $account->account_number . ')</option>';
        })->implode('')
    ) !!}`;

            addRowButton.addEventListener('click', function() {
                var newRow = document.createElement('tr');
                newRow.innerHTML = `
            <td>
                <select name="items[${rowIndex}][chart_of_account_id]" id="items_${rowIndex}_chart_of_account_id" class="form-control" required>
                    <option value="">Pilih Akun</option>
                    ${accountsOptions}
                </select>
            </td>
            <td>
                <input type="number" step="0.01" name="items[${rowIndex}][amount_debit]" id="items_${rowIndex}_amount_debit" class="form-control">
            </td>
            <td>
                <input type="number" step="0.01" name="items[${rowIndex}][amount_credit]" id="items_${rowIndex}_amount_credit" class="form-control">
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm removeRow">Hapus</button>
            </td>
        `;
                itemsTableBody.appendChild(newRow);
                rowIndex++;
            });

            itemsTableBody.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('removeRow')) {
                    e.target.closest('tr').remove();
                }
            });
        });
    </script>
@endpush
