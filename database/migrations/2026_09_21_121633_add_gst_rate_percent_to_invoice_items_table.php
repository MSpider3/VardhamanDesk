<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('gst_rate_percent', 5, 2)->nullable()->after('rate');
        });

        // Backfill existing rows with rate from gst_rates
        DB::statement('UPDATE invoice_items SET gst_rate_percent = (SELECT rate FROM gst_rates WHERE gst_rates.id = invoice_items.gst_rate_id) WHERE gst_rate_percent IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('gst_rate_percent');
        });
    }
};
