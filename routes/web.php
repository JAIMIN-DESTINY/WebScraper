<?php

use App\Http\Controllers\MobilesentrixController;
use Illuminate\Support\Facades\Route;

Route::get('date-check', function () {
    $date = \Carbon\Carbon::now()->format('Y-m-d H:i:s');
    return $date;
})->name('date-check');

Route::get('ms-category', [MobilesentrixController::class, 'MsCategory'])->name('ms-category');
Route::get('ms-product', [MobilesentrixController::class, 'MsProduct'])->name('ms-product');
Route::get('ms-product-sync', [MobilesentrixController::class, 'MsProductSync'])->name('ms-product-sync');

Route::get('/', [MobilesentrixController::class, 'Dashboard'])->name('ms-dashboard');
Route::get('ms-products/export', [MobilesentrixController::class, 'ExportAllProducts'])->name('ms-products.export');
