<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function upload(Request $request) {
        $request->validate([
            'file' => 'required|mimes:pdf|max:1000000'
        ]);

        return "test123";
    }
}
