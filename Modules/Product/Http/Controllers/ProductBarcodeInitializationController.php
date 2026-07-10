<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Routing\Controller;

class ProductBarcodeInitializationController extends Controller
{
    public function index()
    {
        return view('product::barcodes.index');
    }
}
