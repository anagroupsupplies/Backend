<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AiIntelligenceController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\EscrowController;
use App\Http\Controllers\Api\MalipoPayWebhookController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SellerAiController;
use App\Http\Controllers\Api\SellerApplicationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('products', [CatalogController::class, 'products']);
    Route::get('products/{product}', [CatalogController::class, 'product']);
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('banners', [BannerController::class, 'index']);
    Route::get('shops/{shop:slug}', [ShopController::class, 'show']);
    Route::get('product-groups/{productGroup}', [CatalogController::class, 'group']);
    Route::get('products/{product}/reviews', [ReviewController::class, 'index']);
    Route::get('settings', [SettingsController::class, 'show']);
    // Public: MALIPOPAY authenticates itself with an HMAC signature header.
    Route::post('webhooks/malipopay', MalipoPayWebhookController::class)->middleware('throttle:120,1');
    Route::post('ai/chat', AiChatController::class)->middleware('throttle:20,1');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::get('auth/email/verify/{user}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:10,1'])->name('verification.verify');

    Route::middleware('firebase')->group(function (): void {
        Route::post('auth/session', [AuthController::class, 'session']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'update']);
        Route::post('auth/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');
        Route::apiResource('cart', CartController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('wishlist', WishlistController::class)->only(['index', 'store', 'destroy']);
        Route::post('wishlist/{wishlistItem}/move-to-cart', [WishlistController::class, 'moveToCart']);
        Route::apiResource('addresses', AddressController::class)->only(['index', 'store', 'update', 'destroy']);
        // Support tickets. One set of endpoints for customers, sellers and
        // admins; visibility is scoped per role inside the controller.
        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:20,1');
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::patch('tickets/{ticket}', [TicketController::class, 'update']);
        Route::post('tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('throttle:60,1');
        // Escrow. Visibility is scoped per role inside the controller.
        Route::get('escrow', [EscrowController::class, 'index']);
        Route::get('payouts', [EscrowController::class, 'payouts']);
        Route::post('escrow/{holding}/confirm', [EscrowController::class, 'confirm']);
        Route::post('escrow/{holding}/dispute', [EscrowController::class, 'dispute']);
        // Becoming a seller: any signed-in customer may apply.
        Route::get('seller-application', [SellerApplicationController::class, 'mine']);
        Route::post('seller-application', [SellerApplicationController::class, 'store'])->middleware('throttle:10,1');
        Route::post('seller-application/documents', [SellerApplicationController::class, 'upload'])->middleware('throttle:30,1');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('checkout', [OrderController::class, 'store'])->middleware('verified.api');
        Route::post('products/{product}/reviews', [ReviewController::class, 'store']);
        Route::post('analytics', [AdminController::class, 'track']);

        Route::prefix('seller')->middleware('seller')->name('seller.')->group(function (): void {
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

            Route::prefix('ai')->name('ai.')->group(function (): void {
                Route::get('analytics', [SellerAiController::class, 'analytics']);
                Route::get('recommendations', [SellerAiController::class, 'recommendations']);
                Route::post('generate-product', [SellerAiController::class, 'generateProduct']);
                Route::post('optimize-pricing', [SellerAiController::class, 'optimizePricing']);
                Route::post('chat', [SellerAiController::class, 'chat'])->middleware('throttle:30,1');
                Route::get('ticket-reply/{ticket}', [SellerAiController::class, 'suggestTicketReply']);
            });
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
                Route::get('health', [AiIntelligenceController::class, 'health']);
                Route::get('recommendations', [AiIntelligenceController::class, 'recommendations']);
                Route::get('analytics', [AiIntelligenceController::class, 'analytics']);
                Route::post('generate-description', [AiIntelligenceController::class, 'generateDescription']);
                Route::get('tickets/{ticket}/suggest-reply', [AiIntelligenceController::class, 'suggestTicketReply']);
                Route::post('feature-product/{product}', [AiIntelligenceController::class, 'featureProduct']);
            });

            Route::middleware('master')->group(function (): void {
                Route::get('audit-logs', [AuditLogController::class, 'index']);
                Route::get('users', [AdminController::class, 'users']);
                Route::patch('users/{user}', [AdminController::class, 'updateUser']);
                Route::delete('users/{user}', [AdminController::class, 'destroyUser']);
                Route::put('settings', [SettingsController::class, 'update']);
                Route::patch('settings/mobile-money', [SettingsController::class, 'updateMobileMoney']);
                Route::patch('settings/sms', [SettingsController::class, 'updateSms']);
                Route::post('settings/sms/test', [SettingsController::class, 'testSms'])->middleware('throttle:10,1');
            });
        });
    });
});
