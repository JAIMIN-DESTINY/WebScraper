<?php

use App\Http\Controllers\MobilesentrixController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('get-ms-category', [MobilesentrixController::class, 'getMsCategory'])->name('get-ms-category');
