<?php

use App\Http\Controllers\AlarmController;
use App\Http\Controllers\HookScriptController;
use App\Http\Controllers\LogbookController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\TvController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', [OfficeController::class, 'index'])->name('office');
    Route::get('/tv', [TvController::class, 'show'])->name('tv');
    Route::get('/logbook', LogbookController::class)->name('logbook');
    Route::get('/office/state', [OfficeController::class, 'state'])->name('office.state');
    Route::patch('/office/agents/{agent}', [OfficeController::class, 'update'])->name('office.agents.update');
    Route::post('/office/questions/{question}/answer', [QuestionController::class, 'answer'])->name('office.questions.answer');
    Route::post('/office/alarms/answer', [AlarmController::class, 'answer'])->name('office.alarms.answer-all');
    Route::post('/office/alarms/{alarm}/answer', [AlarmController::class, 'answer'])->name('office.alarms.answer');
});

Route::get('/install.sh', HookScriptController::class)->defaults('script', 'install.sh')->middleware('throttle:60,1')->name('hooks.install');
Route::get('/hooks/{script}', HookScriptController::class)
    ->whereIn('script', ['kantoor-hook.sh', 'kantoor-usage.sh', 'kantoor-desktop-usage.sh'])
    ->middleware('throttle:60,1')
    ->name('hooks.script');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['signed', 'throttle:login'])->group(function () {
    Route::get('/setup/{user}/{hash}', [PasswordSetupController::class, 'show'])->name('password.setup');
    Route::post('/setup/{user}/{hash}', [PasswordSetupController::class, 'store']);
});

Route::prefix('tv/{token}')
    ->where(['token' => 'tv_[A-Za-z0-9]{40}'])
    ->middleware('throttle:60,1')
    ->group(function () {
        Route::get('/', [TvController::class, 'screen'])->name('tv.screen');
        Route::get('/state', [TvController::class, 'state'])->name('tv.state');
        Route::post('/broadcasting/auth', [TvController::class, 'auth'])->name('tv.auth');
    });
