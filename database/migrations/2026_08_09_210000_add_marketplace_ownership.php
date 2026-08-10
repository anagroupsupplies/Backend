<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('banner')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::table('product_groups', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->after('seller_id')->constrained('shops')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->after('seller_id')->constrained('shops')->nullOnDelete();
            $table->json('video')->nullable()->after('images');
            $table->index(['seller_id', 'is_active']);
            $table->index(['shop_id', 'is_active']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->after('product_id')->constrained('users')->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->after('seller_id')->constrained('shops')->nullOnDelete();
            $table->index(['seller_id', 'created_at']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->string('type')->default('image')->after('uploaded_by')->index();
            $table->json('metadata')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['type', 'metadata']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropForeign(['shop_id']);
            $table->dropIndex(['seller_id', 'created_at']);
            $table->dropColumn(['seller_id', 'shop_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropForeign(['shop_id']);
            $table->dropIndex(['seller_id', 'is_active']);
            $table->dropIndex(['shop_id', 'is_active']);
            $table->dropColumn(['seller_id', 'shop_id', 'video']);
        });

        Schema::table('product_groups', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropForeign(['shop_id']);
            $table->dropColumn(['seller_id', 'shop_id']);
        });

        Schema::dropIfExists('shops');
    }
};
