<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;
use App\Http\Middleware\HttpRequestTimeoutMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/order', [ApiController::class, 'createOrder'])->name('api.createOrder');

Route::middleware([
    HttpRequestTimeoutMiddleware::class
])->group(function () {
    Route::get('/order', [ApiController::class, 'getOrder'])->name('api.getOrder');
});

Route::delete('/order', [ApiController::class, 'deleteOrder'])->name('api.deleteOrder');

