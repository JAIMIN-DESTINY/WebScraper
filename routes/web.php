<?php

use App\Http\Controllers\MobilesentrixController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('ms-category', [MobilesentrixController::class, 'MsCategory'])->name('ms-category');
Route::get('ms-product', [MobilesentrixController::class, 'MsProduct'])->name('ms-product');
Route::get('ms-product-sync', [MobilesentrixController::class, 'MsProductSync'])->name('ms-product-sync');
