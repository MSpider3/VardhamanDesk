<?php

use App\Http\Controllers\InvoicePdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/admin/invoices/{invoice}/pdf', InvoicePdfController::class)
        ->name('invoices.pdf');
});
