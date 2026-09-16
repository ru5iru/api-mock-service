<?php

use App\Http\Controllers\MockInvocationController;
use Illuminate\Support\Facades\Route;

Route::any('/', MockInvocationController::class)->name('mock.invoke.root');

Route::any('/{any}', MockInvocationController::class)
    ->where('any', '(?!dashboard(?:/|$)|livewire(?:-|/|$)|up$|css(?:/|$)).*')
    ->name('mock.invoke');
