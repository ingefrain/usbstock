<?php

use App\Commerce\Api\QuoteApiException;
use App\Http\Controllers\Api\QuoteReadController;
use Illuminate\Support\Facades\Route;

// Method handling lives at the API boundary, including HEAD and OPTIONS.
Route::any('v1/cotizaciones/{id?}', [QuoteReadController::class, 'show'])
    ->where('id', '.*')->name('api.quotes.show');
Route::any('v1/documentos/{ticket}', [QuoteReadController::class, 'pdf'])
    ->where('ticket', '[^/]+')->name('api.quotes.pdf');
Route::fallback(fn () => throw new QuoteApiException(404, 'ROUTE_NOT_FOUND', 'Ruta no encontrada'));
