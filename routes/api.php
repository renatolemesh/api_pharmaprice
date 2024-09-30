<?php

use App\Http\Controllers\DescricaoController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PrecoController;
use App\Http\Controllers\HistoricoController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\InformacoesProdutoController;
use App\Http\Controllers\LinkController;
use Illuminate\Http\Request;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/precos', [PrecoController::class, 'consultar']);
Route::get('/precos/historico', [HistoricoController::class, 'historico']);

// Novas rotas
Route::post('/produtos', [ProdutoController::class, 'store']);
Route::get('/produtos', [ProdutoController::class, 'index']);
Route::post('/informacoes_produtos', [InformacoesProdutoController::class, 'store']);
Route::get('/informacoes_produtos', [InformacoesProdutoController::class, 'index']);
Route::get('/informacoes_produtos/all', [InformacoesProdutoController::class, 'indexAll']);
Route::post('/precos', [PrecoController::class, 'store']);
Route::get('preco_atual', [PrecoController::class, 'obterPrecoAtual']);
Route::delete('/links', [LinkController::class, 'destroy']);
Route::get('/links', [LinkController::class, 'index']);
Route::post('/links', [LinkController::class, 'store']);
Route::get('/descricoes', [DescricaoController::class, 'index']);

