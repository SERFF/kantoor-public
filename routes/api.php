<?php

use App\Http\Controllers\AlertHookController;
use App\Http\Controllers\ClaudeCodeHookController;
use App\Http\Controllers\UsageHookController;
use App\Http\Middleware\AuthenticateHookToken;
use Illuminate\Support\Facades\Route;

Route::post('/hooks/claude-code', ClaudeCodeHookController::class)
    ->middleware([AuthenticateHookToken::class, 'throttle:hooks'])
    ->name('hooks.claude-code');

Route::get('/hooks/claude-code/requests/{request}', [ClaudeCodeHookController::class, 'show'])
    ->whereUuid('request')
    ->middleware([AuthenticateHookToken::class, 'throttle:hooks'])
    ->name('hooks.claude-code.requests.show');

Route::delete('/hooks/claude-code/requests/{request}', [ClaudeCodeHookController::class, 'destroy'])
    ->whereUuid('request')
    ->middleware([AuthenticateHookToken::class, 'throttle:hooks'])
    ->name('hooks.claude-code.requests.destroy');

Route::post('/hooks/usage', UsageHookController::class)
    ->middleware([AuthenticateHookToken::class, 'throttle:hooks'])
    ->name('hooks.usage');

Route::post('/hooks/alerts', AlertHookController::class)
    ->middleware('throttle:60,1')
    ->name('hooks.alerts.trusted');

Route::post('/hooks/alerts/{token}', AlertHookController::class)
    ->where('token', 'ka_[A-Za-z0-9]{48}')
    ->middleware('throttle:60,1')
    ->name('hooks.alerts');
