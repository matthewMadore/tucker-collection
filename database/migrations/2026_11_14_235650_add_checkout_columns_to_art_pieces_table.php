<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * held_until: when a checkout hold expires (null for manual holds).
     */
    public function up(): void
    {
        Schema::table('art_pieces', function (Blueprint $table) {
            $table->char('currency', 3)->default('USD')->after('price_cents');
            $table->timestampTz('held_until')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('art_pieces', function (Blueprint $table) {
            $table->dropColumn(['currency', 'held_until']);
        });
    }
};
