@extends('layouts.app')

@section('title', 'Input Serial Numbers')

@section('content')
    <div class="container">
        <h3>Input Serial Numbers for {{ $product->product_name }}</h3>

        <form
            action="{{ route('products.storeSerialNumbers', ['product_id' => $product->id, 'location_id' => $location->id]) }}"
            method="POST">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">

            <!-- Input fields for new serial numbers -->
            <h5>Add New Serial Numbers</h5>
            <table class="table table-bordered">
                <thead>
                <tr>
                    <th>Serial Number (Required)</th>
                    <th>Location (Required)</th>
                    <th>Tax (Optional)</th>
                </tr>
                </thead>
                <tbody>
                @for($i = 0; $i < $transaction->quantity; $i++)
                    <tr>
                        <td>
                            <input type="text" name="serial_numbers[]" class="form-control" required
                                   value="{{ old('serial_numbers.' . $i) }}"> <!-- Keeps the old value -->
                        </td>
                        <td>
                            <!-- Location is now readonly -->
                            <input type="hidden" name="locations[]" value="{{ $location->id }}">
                            <span>{{ $location->name }}</span>
                        </td>
                        <td>
                            <select name="tax_ids[]" class="form-control">
                                <option value="">No Tax</option>
                                @foreach($taxes as $tax)
                                    <option value="{{ $tax->id }}"
                                            @if(old('tax_ids.' . $i) == $tax->id) selected @endif>
                                        {{ $tax->name }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endfor
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary">Save Serial Numbers</button>
        </form>
    </div>
@endsection
