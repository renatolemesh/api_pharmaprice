<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PrecoController;
use App\Http\Controllers\HistoricoController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/precos', [PrecoController::class, 'consultar']);
Route::get('/precos/historico', [HistoricoController::class, 'historico']);

