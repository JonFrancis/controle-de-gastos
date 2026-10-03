<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\PaymentMethodController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class);

Route::get('/settings/catalogs', [ParticipantController::class, 'index'])->name('settings.catalogs');
Route::post('/participants', [ParticipantController::class, 'store'])->name('participants.store');
Route::patch('/participants/{participant}', [ParticipantController::class, 'update'])->name('participants.update');
Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
Route::patch('/payment-methods/{payment_method}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
