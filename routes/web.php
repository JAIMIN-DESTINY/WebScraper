<?php

use App\Http\Controllers\MobilesentrixController;
use App\Http\Controllers\Parts4CellsController;
use App\Http\Controllers\PhoneLCDPartsController;
use App\Http\Controllers\XCellPartsController;
use Illuminate\Support\Facades\Route;

Route::get('date-check', function () {
    $date = \Carbon\Carbon::now()->format('Y-m-d H:i:s');
    return $date;
})->name('date-check');

// Mobilesentrix
Route::get('ms-category', [MobilesentrixController::class, 'MsCategory'])->name('ms-category');
Route::get('ms-product', [MobilesentrixController::class, 'MsProduct'])->name('ms-product');
Route::get('ms-product-sync', [MobilesentrixController::class, 'MsProductSync'])->name('ms-product-sync');

Route::get('/', [MobilesentrixController::class, 'Dashboard'])->name('ms-dashboard');
Route::get('ms-products/export', [MobilesentrixController::class, 'ExportAllProducts'])->name('ms-products.export');

// Parts4Cells
Route::get('p4c-category', [Parts4CellsController::class, 'P4cCategory'])->name('p4c-category');
Route::get('p4c-product', [Parts4CellsController::class, 'P4cProduct'])->name('p4c-product');
Route::get('p4c-product-sync', [Parts4CellsController::class, 'P4cProductSync'])->name('p4c-product-sync');

Route::get('p4c-dashboard', [Parts4CellsController::class, 'Dashboard'])->name('p4c-dashboard');
Route::get('p4c-products/export', [Parts4CellsController::class, 'ExportAllProducts'])->name('p4c-products.export');

// PhoneLCDParts
Route::get('plp-category', [PhoneLCDPartsController::class, 'PlpCategory'])->name('plp-category');
Route::get('plp-product', [PhoneLCDPartsController::class, 'PlpProduct'])->name('plp-product');
Route::get('plp-product-sync', [PhoneLCDPartsController::class, 'PlpProductSync'])->name('plp-product-sync');

Route::get('plp-dashboard', [PhoneLCDPartsController::class, 'Dashboard'])->name('plp-dashboard');
Route::get('plp-products/export', [PhoneLCDPartsController::class, 'ExportAllProducts'])->name('plp-products.export');

// XCellParts
Route::get('xcp-category', [XCellPartsController::class, 'XcpCategory'])->name('xcp-category');
Route::get('xcp-product', [XCellPartsController::class, 'XcpProduct'])->name('xcp-product');
Route::get('xcp-product-sync', [XCellPartsController::class, 'XcpProductSync'])->name('xcp-product-sync');

Route::get('xcp-dashboard', [XCellPartsController::class, 'Dashboard'])->name('xcp-dashboard');
Route::get('xcp-products/export', [XCellPartsController::class, 'ExportAllProducts'])->name('xcp-products.export');
