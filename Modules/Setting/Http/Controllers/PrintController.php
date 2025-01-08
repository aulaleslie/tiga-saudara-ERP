<?php

namespace Modules\Setting\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PrintController extends Controller
{
    // Print 80mm Receipt
    public function printReceipt()
    {
        return view('setting::receipt'); // Create a Blade view for the receipt
    }

    // Print A4 Sales Document
    public function printSalesDocument()
    {
        return view('setting::sales'); // Create a Blade view for the sales document
    }
}
