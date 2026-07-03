<?php

use App\Http\Controllers\MobilesentrixController;
use App\Http\Controllers\Parts4CellsController;
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

Route::get('p4c-dashboard', [Parts4CellsController::class, 'Dashboard'])->name('p4c-dashboard');
Route::get('p4c-products/export', [Parts4CellsController::class, 'ExportAllProducts'])->name('p4c-products.export');

Route::get('p4c-category', [Parts4CellsController::class, 'P4cCategory'])->name('p4c-category');
Route::get('p4c-product', [Parts4CellsController::class, 'P4cProduct'])->name('p4c-product');
Route::get('p4c-product-sync', [Parts4CellsController::class, 'P4cProductSync'])->name('p4c-product-sync');
