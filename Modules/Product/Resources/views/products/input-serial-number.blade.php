@extends('layouts.app')

@section('title', 'Input Serial Numbers')

@section('content')
    <div class="container">
        <h3>Input Serial Numbers for {{ $product->product_name }}</h3>

        <form action="{{ route('products.saveSerialNumbers') }}" method="POST">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">

            <!-- Read-only existing serial numbers -->
            @if(count($readonlySerialNumbers) > 0)
                <h5>Existing Serial Numbers (Read-only)</h5>
                <table class="table table-bordered">
                    <thead>
                    <tr>
                        <th>Serial Number</th>
                        <th>Location</th>
                        <th>Tax</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($readonlySerialNumbers as $serial)
                        <tr>
                            <td>{{ $serial['serial_number'] }}</td>
                            <td>{{ $serial['location']['name'] ?? 'N/A' }}</td>
                            <td>{{ $serial['tax']['name'] ?? 'No Tax' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            <!-- Input fields for new serial numbers -->
            @if($remainingRows > 0)
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
                    @for($i = 0; $i < $remainingRows; $i++)
                        <tr>
                            <td>
                                <input type="text" name="serial_numbers[]" class="form-control" required>
                            </td>
                            <td>
                                @if($isEditMode)
                                    <!-- Flexibility to select location during edit -->
                                    <select name="locations[]" class="form-control" required>
                                        @foreach($locations as $location)
                                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <!-- Preselected location during create -->
                                    <input type="hidden" name="locations[]" value="{{ $locationId }}">
                                    <span>{{ $preselectedLocationName }}</span>
                                @endif
                            </td>
                            <td>
                                <select name="tax_ids[]" class="form-control">
                                    <option value="">No Tax</option>
                                    @foreach($taxes as $tax)
                                        <option value="{{ $tax->id }}">{{ $tax->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endfor
                    </tbody>
                </table>
            @else
                <p>All serial numbers are already added for this product.</p>
            @endif

            <button type="submit" class="btn btn-primary">Save Serial Numbers</button>
        </form>
    </div>
@endsection
