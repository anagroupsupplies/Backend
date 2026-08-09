<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/{product}', [CatalogController::class, 'product']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('product-groups/{productGroup}', [CatalogController::class, 'group']);
    Route::get('products/{product}/reviews', [ReviewController::class, 'index']);
    Route::get('settings', [SettingsController::class, 'show']);
    Route::post('ai/chat', AiChatController::class)->middleware('throttle:20,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('firebase')->group(function (): void {
        Route::post('auth/session', [AuthController::class, 'session']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'update']);
        Route::apiResource('cart', CartController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('wishlist', WishlistController::class)->only(['index', 'store', 'destroy']);
        Route::post('wishlist/{wishlistItem}/move-to-cart', [WishlistController::class, 'moveToCart']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('checkout', [OrderController::class, 'store']);
        Route::post('products/{product}/reviews', [ReviewController::class, 'store']);
        Route::post('analytics', [AdminController::class, 'track']);

        Route::prefix('admin')->middleware('admin')->group(function (): void {
            Route::get('dashboard', [AdminController::class, 'dashboard']);
            Route::apiResource('products', CatalogController::class)->except(['index', 'show']);
            Route::post('product-groups', [CatalogController::class, 'storeGroup']);
            Route::post('categories', [CatalogController::class, 'storeCategory']);
            Route::patch('categories/{category}', [CatalogController::class, 'updateCategory']);
            Route::delete('categories/{category}', [CatalogController::class, 'destroyCategory']);
            Route::get('orders', [AdminController::class, 'orders']);
            Route::patch('orders/{order}', [AdminController::class, 'updateOrder']);
            Route::apiResource('media', MediaController::class)->only(['index', 'store', 'destroy']);

            Route::middleware('master')->group(function (): void {
                Route::get('users', [AdminController::class, 'users']);
                Route::patch('users/{user}', [AdminController::class, 'updateUser']);
                Route::delete('users/{user}', [AdminController::class, 'destroyUser']);
                Route::put('settings', [SettingsController::class, 'update']);
            });
        });
    });
});
