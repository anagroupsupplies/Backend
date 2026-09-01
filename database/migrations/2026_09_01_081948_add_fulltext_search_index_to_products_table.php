<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index product text for search.
     *
     * `LIKE '%term%'` cannot use an index because of the leading wildcard, so
     * every search was a full table scan. A FULLTEXT index lets MySQL match on
     * words instead.
     *
     * FULLTEXT is MySQL-only; SQLite (used by the test suite) simply keeps the
     * LIKE path, which is fine at test data sizes.
     */
    public function up(): void
    {
        if (! $this->supportsFullText()) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->fullText(['name', 'description'], 'products_search_fulltext');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->supportsFullText()) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropFullText('products_search_fulltext');
        });
    }

    private function supportsFullText(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
