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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->default('cash_on_delivery')->index()->after('status');
            $table->string('payment_status')->default('pending')->index()->after('payment_method');
            $table->decimal('delivery_latitude', 10, 7)->nullable()->after('shipping_details');
            $table->decimal('delivery_longitude', 10, 7)->nullable()->after('delivery_latitude');
            $table->decimal('delivery_accuracy', 8, 2)->nullable()->after('delivery_longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['payment_method', 'payment_status', 'delivery_latitude', 'delivery_longitude', 'delivery_accuracy']);
        });
    }
};
