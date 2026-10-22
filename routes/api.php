<?php

use App\Http\Controllers\AlertHookController;
use App\Http\Controllers\ClaudeCodeHookController;
use App\Http\Middleware\AuthenticateHookToken;
use Illuminate\Support\Facades\Route;

Route::post('/hooks/claude-code', ClaudeCodeHookController::class)
    ->middleware([AuthenticateHookToken::class, 'throttle:hooks'])
    ->name('hooks.claude-code');

Route::post('/hooks/alerts/{token}', AlertHookController::class)
    ->where('token', 'ka_[A-Za-z0-9]{48}')
    ->middleware('throttle:60,1')
    ->name('hooks.alerts');
