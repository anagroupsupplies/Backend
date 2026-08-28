<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escrow ledger.
     *
     * One holding per (order, seller) rather than per order: a single order can
     * contain items from several shops, and each shop's money must be released
     * on its own delivery rather than waiting for the slowest seller.
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('holdings_count')->default(0);
            $table->string('status')->default('pending')->index();
            $table->string('method')->nullable();
            $table->string('destination')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['seller_id', 'status']);
        });

        Schema::create('escrow_holdings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
            // The commission rate is snapshotted so changing the platform fee
            // later never rewrites what a seller was already promised.
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->string('status')->default('held')->index();
            $table->timestamp('held_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('releasable_at')->nullable()->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamps();
            // One holding per shop per order.
            $table->unique(['order_id', 'seller_id']);
            $table->index(['seller_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escrow_holdings');
        Schema::dropIfExists('payouts');
    }
};
