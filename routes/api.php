<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuditTrailController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\EscrowController;
use App\Http\Controllers\Api\MalipoCallbackController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public routes
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/{product}', [CatalogController::class, 'product']);
    Route::get('product-groups/{group}', [CatalogController::class, 'group']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('shops/{slug}', [ShopController::class, 'show']);
    Route::get('settings', [SettingsController::class, 'index']);
    Route::get('banners', [BannerController::class, 'index']);
    Route::get('products/{product}/reviews', [ReviewController::class, 'index']);

    // Malipo payment webhook
    Route::post('webhooks/malipo', [MalipoCallbackController::class, 'handle']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('session', [AuthController::class, 'session']);
            Route::post('email/verification-notification', [AuthController::class, 'resendVerificationNotification']);
        });

        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateProfile']);
        Route::post('cart/merge', [CartController::class, 'merge']);
        Route::apiResource('cart', CartController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('wishlist', WishlistController::class)->only(['index', 'store', 'destroy']);
        Route::apiResource('orders', OrderController::class)->only(['index', 'show', 'store']);
        Route::patch('orders/{order}/confirm-delivery', [OrderController::class, 'confirmDelivery']);
        Route::post('checkout', [CheckoutController::class, 'checkout']);
        Route::apiResource('seller-application', SellerApplicationController::class)->only(['index', 'store']);
        Route::post('seller-application/document', [SellerApplicationController::class, 'uploadDocument']);
        Route::get('escrow', [EscrowController::class, 'index']);
        Route::get('payouts', [EscrowController::class, 'payouts']);
        Route::post('escrow/{holding}/dispute', [EscrowController::class, 'dispute']);
        Route::post('products/{product}/reviews', [ReviewController::class, 'store']);
        Route::apiResource('tickets', TicketController::class)->only(['index', 'show', 'store']);
        Route::post('tickets/{ticket}/messages', [TicketController::class, 'reply']);
        Route::post('tickets/{ticket}/close', [TicketController::class, 'close']);

        Route::prefix('seller')->middleware('seller')->group(function (): void {
            Route::get('dashboard', [AdminController::class, 'sellerDashboard']);
            Route::get('orders', [AdminController::class, 'sellerOrders']);
            Route::get('orders/{order}', [AdminController::class, 'sellerOrder']);
            Route::patch('orders/{order}/status', [AdminController::class, 'updateSellerOrderStatus']);
            Route::get('shop', [ShopController::class, 'mine']);
            Route::patch('shop', [ShopController::class, 'update']);
            Route::get('products', [CatalogController::class, 'manageableProducts']);
            Route::post('products', [CatalogController::class, 'store']);
            Route::patch('products/{product}', [CatalogController::class, 'update']);
            Route::delete('products/{product}', [CatalogController::class, 'destroy']);
            Route::post('product-groups', [CatalogController::class, 'storeGroup']);
            Route::post('media/video', [MediaController::class, 'store']);
            Route::apiResource('media', MediaController::class)->only(['index', 'store', 'destroy']);
        });

        Route::prefix('admin')->middleware('admin')->name('admin.')->group(function (): void {
            Route::get('dashboard', [AdminController::class, 'dashboard']);
            Route::get('products', [CatalogController::class, 'manageableProducts']);
            Route::apiResource('products', CatalogController::class)->only(['update', 'destroy']);
            Route::post('categories', [CatalogController::class, 'storeCategory']);
            Route::patch('categories/{category}', [CatalogController::class, 'updateCategory']);
            Route::delete('categories/{category}', [CatalogController::class, 'destroyCategory']);
            Route::get('orders', [AdminController::class, 'orders']);
            Route::patch('orders/{order}', [AdminController::class, 'updateOrder']);
            Route::post('media/video', [MediaController::class, 'store']);
            Route::apiResource('media', MediaController::class)->only(['index', 'store', 'destroy']);
            Route::get('shops', [AdminController::class, 'shops']);
            Route::patch('shops/{shop}', [AdminController::class, 'updateShop']);
            Route::post('escrow/{holding}/resolve', [EscrowController::class, 'resolve']);
            Route::get('payable-sellers', [EscrowController::class, 'payableSellers']);
            Route::post('payouts', [EscrowController::class, 'createPayout']);
            Route::patch('payouts/{payout}', [EscrowController::class, 'updatePayout']);
            Route::get('seller-applications', [SellerApplicationController::class, 'index']);
            Route::get('seller-applications/{application}', [SellerApplicationController::class, 'show']);
            Route::get('seller-applications/{application}/document/{kind}', [SellerApplicationController::class, 'document']);
            Route::post('seller-applications/{application}/approve', [SellerApplicationController::class, 'approve']);
            Route::post('seller-applications/{application}/reject', [SellerApplicationController::class, 'reject']);
            Route::post('seller-applications/{application}/request-information', [SellerApplicationController::class, 'requestInformation']);
            Route::get('reviews', [AdminController::class, 'reviews']);
            Route::delete('reviews/{review}', [AdminController::class, 'destroyReview']);
            Route::apiResource('banners', BannerController::class)->except(['index', 'show']);

            Route::prefix('ai')->group(function (): void {
                Route::get('system-status', [AiController::class, 'systemStatus']);
                Route::get('recommendations', [AiController::class, 'recommendations']);
                Route::post('analyze', [AiController::class, 'analyze']);
                Route::post('generate-copy', [AiController::class, 'generateCopy']);
                Route::post('suggest-ticket-reply', [AiController::class, 'suggestTicketReply']);
            });

            Route::prefix('settings')->group(function (): void {
                Route::post('delivery-fee', [SettingsController::class, 'updateDeliveryFee']);
                Route::post('commission-rate', [SettingsController::class, 'updateCommissionRate']);
            });

            Route::middleware('master')->group(function (): void {
                Route::apiResource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
                Route::get('audit-trail', [AuditTrailController::class, 'index']);
            });
        });
    });
});
