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
            $table->string('payment_reference')->nullable()->unique()->after('payment_status');
            $table->string('payment_transaction_id')->nullable()->after('payment_reference');
            $table->string('payment_phone')->nullable()->after('payment_transaction_id');
            $table->string('payment_channel')->nullable()->after('payment_phone');
            $table->decimal('paid_amount', 15, 2)->default(0)->after('payment_channel');
            $table->timestamp('paid_at')->nullable()->index()->after('paid_amount');
            $table->string('payment_failure_reason')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['payment_reference']);
            $table->dropIndex(['paid_at']);
            $table->dropColumn(['payment_reference', 'payment_transaction_id', 'payment_phone', 'payment_channel', 'paid_amount', 'paid_at', 'payment_failure_reason']);
        });
    }
};
