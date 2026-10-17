<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinanceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FinanceController::class, 'landing'])->name('home');
Route::get('/login', [AuthController::class, 'loginForm'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:5,1'])->name('login.store');
Route::get('/register', [AuthController::class, 'registerForm'])->middleware('guest')->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:5,1'])->name('register.store');
Route::post('/register/verify', [AuthController::class, 'verifyCode'])->middleware(['guest', 'throttle:10,1'])->name('register.verify');
Route::post('/register/resend', [AuthController::class, 'resendCode'])->middleware(['guest', 'throttle:5,1'])->name('register.resend');
Route::post('/register/cancel', [AuthController::class, 'cancelRegistration'])->middleware('guest')->name('register.cancel');
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->middleware('guest')->name('auth.google');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->middleware('guest')->name('auth.google.callback');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/app', [FinanceController::class, 'dashboard'])->name('dashboard');
    Route::get('/agent/conversations/{conversation}', [AgentController::class, 'history'])->name('agent.history');
    Route::delete('/agent/conversations/{conversation}', [AgentController::class, 'destroy'])->name('agent.destroy');
    Route::put('/profile', [FinanceController::class, 'saveProfile'])->middleware('throttle:10,1')->name('profile.save');
    Route::post('/tuition', [FinanceController::class, 'storeTuition'])->middleware('throttle:10,1')->name('tuition.store');
    Route::post('/tuition/{tuitionPlan}/progress', [FinanceController::class, 'addTuitionSavings'])->middleware('throttle:10,1')->name('tuition.progress');
    Route::post('/goals', [FinanceController::class, 'storeGoal'])->middleware('throttle:10,1')->name('goals.store');
    Route::post('/goals/{goal}/progress', [FinanceController::class, 'addGoalSavings'])->middleware('throttle:10,1')->name('goals.progress');
    Route::post('/budgets', [FinanceController::class, 'storeBudget'])->middleware('throttle:10,1')->name('budgets.store');
    Route::post('/funding-opportunities', [FinanceController::class, 'storeFundingOpportunity'])->middleware('throttle:10,1')->name('funding-opportunities.store');
    Route::patch('/funding-opportunities/{fundingOpportunity}/status', [FinanceController::class, 'updateFundingOpportunityStatus'])->middleware('throttle:10,1')->name('funding-opportunities.status');
    Route::post('/transactions', [FinanceController::class, 'storeTransaction'])->middleware('throttle:20,1')->name('transactions.store');
    Route::post('/convert', [FinanceController::class, 'convert'])->middleware('throttle:30,1')->name('currency.convert');
    Route::post('/remittance', [FinanceController::class, 'compareRemittance'])->middleware('throttle:20,1')->name('remittance.compare');
    Route::post('/scenario', [FinanceController::class, 'scenario'])->middleware('throttle:20,1')->name('scenario.run');
    Route::post('/agent/chat', [AgentController::class, 'chat'])->middleware('throttle:10,1')->name('agent.chat');
    Route::post('/agent/confirm', [AgentController::class, 'confirm'])->middleware('throttle:5,1')->name('agent.confirm');
    Route::post('/agent/cancel', [AgentController::class, 'cancel'])->middleware('throttle:10,1')->name('agent.cancel');
});
