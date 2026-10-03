<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
Route::get('/purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->name('purchases.edit');
Route::patch('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
Route::patch('/purchases/{purchase}/archive', [PurchaseController::class, 'destroy'])->name('purchases.archive');
Route::patch('/purchases/{purchase}/restore', [PurchaseController::class, 'restore'])->name('purchases.restore');

Route::get('/settings/catalogs', [ParticipantController::class, 'index'])->name('settings.catalogs');
Route::post('/participants', [ParticipantController::class, 'store'])->name('participants.store');
Route::patch('/participants/{participant}', [ParticipantController::class, 'update'])->name('participants.update');
Route::delete('/participants/{participant}', [ParticipantController::class, 'destroy'])->name('participants.destroy');
Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
Route::patch('/payment-methods/{payment_method}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
Route::delete('/payment-methods/{payment_method}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');
