<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('art_pieces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->default('');
            $table->bigInteger('price_cents');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE art_pieces ADD CONSTRAINT price_cents_positive CHECK (price_cents > 0)');
        DB::statement("ALTER TABLE art_pieces ADD CONSTRAINT status_valid CHECK (status IN ('draft', 'published', 'held', 'sold'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('art_pieces');
    }
};
