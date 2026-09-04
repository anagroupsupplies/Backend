<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('condition', 32)->default('new')->after('description');
            $table->text('condition_details')->nullable()->after('condition');
            $table->json('variants')->nullable()->after('sizes');
            $table->json('specifications')->nullable()->after('variants');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['condition', 'condition_details', 'variants', 'specifications']);
        });
    }
};
