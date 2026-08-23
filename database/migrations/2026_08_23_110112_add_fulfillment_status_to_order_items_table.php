<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('fulfillment_status')->default('pending')->index()->after('shop_id');
            $table->timestamp('fulfillment_updated_at')->nullable()->after('fulfillment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn(['fulfillment_status', 'fulfillment_updated_at']);
        });
    }
};
