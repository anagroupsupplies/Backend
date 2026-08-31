<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Applications from customers who want to sell on the platform.
     *
     * The identity and business details collected here are what lets the
     * platform trace a seller if a customer later reports being defrauded.
     */
    public function up(): void
    {
        Schema::create('seller_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Who is applying and what they intend to sell.
            $table->string('full_name');
            $table->string('business_name');
            $table->string('product_category');
            $table->string('phone');
            $table->string('alternate_phone')->nullable();
            $table->string('region');
            $table->string('city');
            $table->string('street_address');
            $table->text('business_description');

            // Registration details, optional for informal traders.
            $table->string('tin_number')->nullable();
            $table->string('business_registration_number')->nullable();

            // Where the platform sends this seller's escrow payouts.
            $table->string('payout_method')->default('mobile_money');
            $table->string('payout_account_name');
            $table->string('payout_number');
            $table->string('payout_bank')->nullable();

            // Public branding, stored on the public disk.
            $table->string('logo_url')->nullable();

            // Identity documents. Paths on the PRIVATE disk: these are national
            // ID and passport scans and must never be publicly reachable.
            $table->string('id_document_type')->default('nida');
            $table->string('id_number');
            $table->string('id_document_path');
            $table->string('business_document_path')->nullable();

            $table->timestamp('terms_accepted_at')->nullable();

            $table->string('status')->default('pending')->index();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_applications');
    }
};
