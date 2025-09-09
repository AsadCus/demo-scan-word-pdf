<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\FileImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/demo', [DemoController::class, 'index'])->name('demo.index');
Route::get('/demo/list', [DemoController::class, 'getData'])->name('demo.list');
Route::post('/demo/upload', [FileImportController::class, 'upload'])->name('demo.upload');

Route::get('/phpinfo', function () {
    phpinfo();
});
