<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing order lines inherit the status of the order they belong to, so
     * historic orders do not all appear to regress to "pending".
     */
    public function up(): void
    {
        DB::table('orders')->select('id', 'status')->orderBy('id')->chunk(500, function ($orders): void {
            foreach ($orders as $order) {
                DB::table('order_items')->where('order_id', $order->id)->update(['fulfillment_status' => $order->status]);
            }
        });
    }

    /**
     * Data backfill only; the column drop in the previous migration reverses it.
     */
    public function down(): void {}
};
